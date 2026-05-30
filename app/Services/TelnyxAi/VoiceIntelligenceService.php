<?php

namespace App\Services\TelnyxAi;

use App\Models\VoiceCall;
use App\Services\ReproAi\LlmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Layer 2 of the v3 cockpit. Event-triggered, debounced, cost-capped Robbie
 * enrichment. Produces Robbie Context Objects (mood, quality, suggestions with
 * "why", risk, next-best-action) stored under metadata.intel_live, and a final
 * summary under metadata.intel_final. Never runs per-chunk.
 */
class VoiceIntelligenceService
{
    private const DEBOUNCE_SECONDS = 12;
    private const MAX_MIDCALL_RUNS = 8;
    private const LOOPING_REPEAT_THRESHOLD = 3;

    public function __construct(
        private readonly VoiceSettingsService $settings,
        private readonly VoiceLlmUsageRecorder $usage,
        private readonly VoiceMemoryService $memory,
        private readonly ?LlmClient $llm = null,
    ) {
    }

    /**
     * Called by the live stream on every realtime update. Evaluates triggers
     * and, if one fires (and debounce/budget allow), runs enrichment.
     *
     * @param array<string,mixed> $signals
     */
    public function onRealtimeUpdate(VoiceCall $call, array $signals): void
    {
        $triggers = $this->evaluateTriggers($call, $signals);
        if (empty($triggers)) {
            return;
        }

        $this->enrich($call, $triggers, final: false);
    }

    /**
     * Explicit trigger when the cockpit is opened (first time per call).
     */
    public function onCockpitOpened(VoiceCall $call): ?array
    {
        $intel = $call->metadata['intel_live'] ?? null;
        if ($this->triggerEnabled('cockpit_opened') && empty($call->metadata['intel_meta']['cockpit_opened'])) {
            $this->markMeta($call, 'cockpit_opened', true);
            $this->enrich($call->fresh(), ['cockpit_opened'], final: false);
            $intel = $call->fresh()->metadata['intel_live'] ?? $intel;
        }
        return $intel;
    }

    /**
     * Always-on final enrichment when the call ends.
     */
    public function finalize(VoiceCall $call): ?array
    {
        if (!$this->layerEnabled()) {
            return null;
        }

        return $this->enrich($call, ['call_ending'], final: true);
    }

    /**
     * Evaluate which triggers fire for the current signals. Public so the
     * log-only validation path and tests can inspect it.
     *
     * @param array<string,mixed> $signals
     * @return array<int,string>
     */
    public function evaluateTriggers(VoiceCall $call, array $signals): array
    {
        $config = $this->intelligenceConfig();
        $thresholds = $config['thresholds'] ?? [];
        $fired = [];

        $confidence = $signals['confidence'] ?? null;
        if ($this->triggerEnabled('low_confidence') && $confidence !== null) {
            $pct = (float) $confidence * 100;
            if ($pct < (float) ($thresholds['low_confidence_pct'] ?? 70)) {
                $fired[] = 'low_confidence';
            }
        }

        if ($this->triggerEnabled('silence')
            && (float) ($signals['silence_sec'] ?? 0) > (float) ($thresholds['silence_seconds'] ?? 8)) {
            $fired[] = 'silence';
        }

        if ($this->triggerEnabled('sentiment_shift') && ($signals['sentiment'] ?? null) === 'negative') {
            $fired[] = 'sentiment_shift';
        }

        if ($this->triggerEnabled('keyword')) {
            $keywords = $thresholds['keywords'] ?? [];
            $text = strtolower((string) ($signals['text'] ?? ''));
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($text, strtolower((string) $keyword))) {
                    $fired[] = 'keyword';
                    break;
                }
            }
        }

        if ($this->triggerEnabled('transfer_requested') && ($signals['transfer_requested'] ?? false)) {
            $fired[] = 'transfer_requested';
        }

        return array_values(array_unique($fired));
    }

    /**
     * Run an enrichment pass if debounce + budget allow.
     *
     * @param array<int,string> $triggers
     */
    public function enrich(VoiceCall $call, array $triggers, bool $final): ?array
    {
        if (!$this->layerEnabled()) {
            return null;
        }

        $meta = $call->metadata['intel_meta'] ?? [];

        if (!$final) {
            // Debounce: at most one run per DEBOUNCE_SECONDS per call.
            $lastRun = $meta['last_run_at'] ?? null;
            if ($lastRun) {
                try {
                    if (Carbon::parse($lastRun)->diffInSeconds(now()) < self::DEBOUNCE_SECONDS) {
                        return $call->metadata['intel_live'] ?? null;
                    }
                } catch (\Throwable $e) {
                    // fall through
                }
            }
            // Hard cap on mid-call runs.
            if ((int) ($meta['midcall_runs'] ?? 0) >= self::MAX_MIDCALL_RUNS) {
                return $call->metadata['intel_live'] ?? null;
            }
        }

        // Cost cap.
        $budget = (float) ($this->intelligenceConfig()['monthly_llm_budget_usd'] ?? 0);
        if ($this->usage->isBudgetExceeded($budget)) {
            $this->markMeta($call, 'budget_paused', true);
            return $call->metadata['intel_live'] ?? null;
        }

        // Auto-load Tier 2 memory when behavioral triggers fire.
        $this->maybeLoadMemory($call, $triggers, $final);

        $result = $this->runModel($call, $triggers, $final);

        $metadata = $call->fresh()->metadata ?? [];
        if ($final) {
            $metadata['intel_final'] = $result;
        } else {
            $metadata['intel_live'] = $result;
        }
        $metadata['intel_meta'] = array_merge($metadata['intel_meta'] ?? [], [
            'last_run_at' => now()->toIso8601String(),
            'midcall_runs' => $final
                ? ($metadata['intel_meta']['midcall_runs'] ?? 0)
                : ((int) ($metadata['intel_meta']['midcall_runs'] ?? 0) + 1),
            'last_triggers' => $triggers,
            'budget_paused' => false,
        ]);

        $call->forceFill(['metadata' => $metadata])->save();

        return $result;
    }

    /**
     * Whether the whole layer is enabled (env kill switch + settings toggle).
     */
    public function layerEnabled(): bool
    {
        if (!(bool) env('VOICE_INSIGHTS_LLM_ENABLED', true)) {
            return false;
        }
        return (bool) ($this->intelligenceConfig()['enabled'] ?? true);
    }

    public function budgetPaused(): bool
    {
        $budget = (float) ($this->intelligenceConfig()['monthly_llm_budget_usd'] ?? 0);
        return $this->usage->isBudgetExceeded($budget);
    }

    // ---- model -------------------------------------------------------------

    /**
     * Produce a Robbie Context Object. Uses the LLM when configured; otherwise
     * falls back to deterministic heuristics so the layer is testable and works
     * in "log-only" mode without an API key.
     *
     * @param array<int,string> $triggers
     */
    private function runModel(VoiceCall $call, array $triggers, bool $final): array
    {
        $heuristic = $this->heuristic($call, $triggers, $final);

        $apiKey = (string) config('services.openai.api_key', '');
        if ($this->llm === null || $apiKey === '') {
            // Log-only / heuristic mode — still records a zero-cost usage row for auditability.
            $this->usage->record($call, $final ? 'final_summary' : 'realtime_enrichment', 'heuristic', 0, 0);
            return $heuristic;
        }

        try {
            $messages = [
                ['role' => 'system', 'content' => $this->systemPrompt($final)],
                ['role' => 'user', 'content' => $this->buildContextObject($call, $triggers)],
            ];
            $response = $this->llm->chatCompletion($messages, [], false, ['temperature' => 0.3, 'max_tokens' => 600]);
            $content = $response['choices'][0]['message']['content'] ?? '';
            $usageData = $response['usage'] ?? [];
            $this->usage->record(
                $call,
                $final ? 'final_summary' : 'realtime_enrichment',
                (string) ($response['model'] ?? config('services.openai.model', 'gpt-4o')),
                (int) ($usageData['prompt_tokens'] ?? 0),
                (int) ($usageData['completion_tokens'] ?? 0),
            );

            $parsed = json_decode($content, true);
            if (is_array($parsed)) {
                return array_merge($heuristic, $parsed);
            }
        } catch (\Throwable $e) {
            Log::warning('Voice intelligence LLM call failed, using heuristic', ['error' => $e->getMessage()]);
        }

        return $heuristic;
    }

    /**
     * Deterministic enrichment from transcript + signals.
     *
     * @param array<int,string> $triggers
     */
    private function heuristic(VoiceCall $call, array $triggers, bool $final): array
    {
        $live = $call->metadata['live'] ?? [];
        $chunks = $live['transcript_chunks'] ?? [];
        $sentiment = $live['realtime']['sentiment'] ?? 'neutral';

        $customerMood = match ($sentiment) {
            'negative' => in_array('keyword', $triggers, true) ? 'frustrated' : 'concerned',
            'positive' => 'positive',
            default => 'neutral',
        };

        $looping = $this->detectLooping($chunks);
        $robbieQuality = $looping
            ? 'looping'
            : (in_array('low_confidence', $triggers, true) ? 'ok' : 'good');

        $transferRequested = in_array('transfer_requested', $triggers, true)
            || in_array('keyword', $triggers, true);

        $intent = $call->intent ?: 'general_support';

        $suggestions = $this->buildSuggestions($triggers, $transferRequested, $intent);

        $result = [
            'customer_mood' => $customerMood,
            'robbie_quality' => $robbieQuality,
            'intent' => $intent,
            'intent_confidence' => round((float) ($live['realtime']['confidence'] ?? 0.8), 2),
            'suggested_replies' => $suggestions,
            'next_best_action' => $transferRequested
                ? 'Prepare a warm transfer to the team.'
                : 'Keep gathering details and confirm next steps.',
            'risk' => $this->buildRisk($triggers, $customerMood),
            'sales_opportunity' => null,
            'human_takeover_recommended' => $looping || $transferRequested,
            'triggers' => $triggers,
            'generated_at' => now()->toIso8601String(),
        ];

        if ($final) {
            $result['summary_text'] = $call->summary
                ?: 'Call handled by Robbie. ' . count($chunks) . ' transcript turns captured.';
            $result['quality_score'] = match ($robbieQuality) {
                'excellent', 'good' => 'Good',
                'ok' => 'Average',
                default => 'Poor',
            };
            $result['issue_resolved'] = $transferRequested ? 'partial' : 'yes';
            $result['follow_up_at'] = $transferRequested ? now()->addDay()->toIso8601String() : null;
            $result['auto_scheduled_callback_id'] = $call->scheduled_voice_call_id;
        }

        return $result;
    }

    private function buildSuggestions(array $triggers, bool $transferRequested, string $intent): array
    {
        $suggestions = [];

        if ($transferRequested) {
            $suggestions[] = [
                'label' => 'Transfer to the team',
                'spoken' => 'Let me connect you to a teammate now.',
                'why' => 'Caller asked for a person or used an escalation keyword.',
            ];
        }

        if (in_array('low_confidence', $triggers, true)) {
            $suggestions[] = [
                'label' => 'Ask to repeat',
                'spoken' => "I want to make sure I get this right — could you say that once more?",
                'why' => 'Recent transcript confidence dropped below threshold.',
            ];
        }

        if (in_array('silence', $triggers, true)) {
            $suggestions[] = [
                'label' => 'Re-engage',
                'spoken' => 'Are you still there? I can keep helping whenever you are ready.',
                'why' => 'Detected a long silence on the line.',
            ];
        }

        if (empty($suggestions)) {
            $suggestions[] = [
                'label' => 'Confirm next step',
                'spoken' => "Here's what I can do next — does that work for you?",
                'why' => 'Keep momentum toward resolving the request.',
            ];
        }

        return array_slice($suggestions, 0, 3);
    }

    private function buildRisk(array $triggers, string $mood): ?array
    {
        if (in_array($mood, ['frustrated', 'angry'], true) || in_array('keyword', $triggers, true)) {
            return [
                'type' => 'churn',
                'score' => 0.6,
                'why' => 'Negative sentiment or escalation language detected on this call.',
            ];
        }
        return null;
    }

    private function detectLooping(array $chunks): bool
    {
        $assistantLines = array_values(array_filter(
            array_map(fn ($c) => ($c['speaker'] ?? null) === 'assistant' ? strtolower(trim((string) ($c['text'] ?? ''))) : null, $chunks)
        ));
        if (count($assistantLines) < self::LOOPING_REPEAT_THRESHOLD) {
            return false;
        }

        $tail = array_slice($assistantLines, -self::LOOPING_REPEAT_THRESHOLD);
        return count(array_unique($tail)) === 1;
    }

    private function buildContextObject(VoiceCall $call, array $triggers): string
    {
        return json_encode([
            'triggers' => $triggers,
            'intent' => $call->intent,
            'realtime' => $call->metadata['live']['realtime'] ?? [],
            'recent_transcript' => array_slice($call->metadata['live']['transcript_chunks'] ?? [], -12),
            'memory_tier1' => $this->memory->tier($call, 'tier1'),
            'memory_tier2' => $this->memory->tier($call, 'tier2'),
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function systemPrompt(bool $final): string
    {
        $base = 'You are Robbie, a co-pilot for a live phone agent. Given a Robbie Context Object, return ONLY compact JSON.';
        return $final
            ? $base . ' Produce final-call fields: summary_text, quality_score (Good/Average/Poor), issue_resolved (yes/no/partial), follow_up_at, customer_mood, robbie_quality.'
            : $base . ' Produce: customer_mood, robbie_quality, intent, intent_confidence, suggested_replies (each with label, spoken, why), next_best_action, risk, sales_opportunity, human_takeover_recommended.';
    }

    private function maybeLoadMemory(VoiceCall $call, array $triggers, bool $final): void
    {
        $signals = [
            'keyword_hit' => in_array('keyword', $triggers, true),
            'negative_sentiment' => in_array('sentiment_shift', $triggers, true),
            'human_transfer_requested' => in_array('transfer_requested', $triggers, true),
            'duration_seconds' => $call->started_at ? $call->started_at->diffInSeconds(now()) : 0,
        ];

        if ($this->memory->shouldAutoLoadTier2($call, $signals)) {
            try {
                $this->memory->loadTier2($call);
            } catch (\Throwable $e) {
                Log::warning('Tier2 memory load failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function markMeta(VoiceCall $call, string $key, mixed $value): void
    {
        $metadata = $call->metadata ?? [];
        $metadata['intel_meta'] = array_merge($metadata['intel_meta'] ?? [], [$key => $value]);
        $call->forceFill(['metadata' => $metadata])->save();
    }

    private function triggerEnabled(string $trigger): bool
    {
        return (bool) ($this->intelligenceConfig()['triggers'][$trigger] ?? false);
    }

    private function intelligenceConfig(): array
    {
        $all = $this->settings->all();
        return is_array($all['intelligence'] ?? null) ? $all['intelligence'] : [];
    }
}
