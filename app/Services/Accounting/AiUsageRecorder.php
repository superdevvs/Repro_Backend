<?php

namespace App\Services\Accounting;

use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Stores counters and provider identifiers only. Never stores prompts, responses or credentials. */
class AiUsageRecorder
{
    public const FEATURES = [
        'robbie_chat' => 'Robbie chat', 'email_assistant' => 'Email drafting',
        'property_description' => 'Property descriptions', 'photo_classification' => 'Photo classification',
        'object_detection' => 'Object detection', 'image_edit' => 'Image editing',
        'image_outpaint' => 'Image extension', 'voice_summary' => 'Voice intelligence',
        'health_check' => 'Provider health checks', 'other' => 'Other OpenAI calls',
    ];

    public function begin(string $feature, string $model, string $endpoint): ?string
    {
        $id = (string) Str::uuid();
        return $this->write(function () use ($id, $feature, $model, $endpoint): string {
            DB::table('ai_provider_usage')->insert([
                'id' => $id, 'occurred_at' => now('UTC')->format('Y-m-d H:i:s'),
                'provider' => 'openai', 'feature' => array_key_exists($feature, self::FEATURES) ? $feature : 'other',
                'model' => substr($model, 0, 100), 'endpoint' => substr($endpoint, 0, 60),
                'source' => 'metered', 'calls' => 1, 'status' => 'pending',
            ]);
            return $id;
        });
    }

    public function finish(?string $id, string $model, ?array $usage, string $status, ?int $httpStatus = null, ?string $requestId = null, ?int $durationMs = null): void
    {
        if ($id === null) return;
        $input = $this->tokens($usage, ['prompt_tokens', 'input_tokens']);
        $output = $this->tokens($usage, ['completion_tokens', 'output_tokens']);
        $cached = $usage === null ? null : (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? 0);
        $cost = $status === 'success' ? $this->estimate($model, $usage) : null;
        $this->write(fn () => DB::table('ai_provider_usage')->where('id', $id)->update([
            'model' => substr($model, 0, 100), 'status' => $status, 'http_status' => $httpStatus,
            'request_id' => $requestId === null ? null : substr($requestId, 0, 150),
            'input_tokens' => $input, 'output_tokens' => $output,
            'cached_tokens' => $cached === null || $input === null ? null : min($input, max(0, $cached)),
            'estimated_cost_usd' => $cost, 'pricing_version' => $cost === null ? null : config('ai_usage.pricing_version'),
            'duration_ms' => $durationMs,
        ]));
    }

    public function estimate(string $model, ?array $usage): ?float
    {
        $input = $this->tokens($usage, ['prompt_tokens', 'input_tokens']);
        $output = $this->tokens($usage, ['completion_tokens', 'output_tokens']);
        if ($input === null || $output === null) return null;
        $base = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model);
        $rates = config('ai_usage.prices.'.$base);
        if (! is_array($rates)) return null;
        if (isset($rates['image_input'])) {
            // Images API edits do not receive a prompt-cache discount. Missing modality splits are unpriced.
            $details = $usage['input_tokens_details'] ?? [];
            if (! isset($details['text_tokens'], $details['image_tokens']) || $details['text_tokens'] + $details['image_tokens'] !== $input) return null;
            return round(($details['text_tokens'] * $rates['text_input'] + $details['image_tokens'] * $rates['image_input'] + $output * $rates['output']) / 1_000_000, 8);
        }
        $cached = min($input, max(0, (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? 0)));
        return round((($input - $cached) * $rates['input'] + $cached * $rates['cached'] + $output * $rates['output']) / 1_000_000, 8);
    }

    private function tokens(?array $usage, array $keys): ?int
    {
        foreach ($keys as $key) if (isset($usage[$key]) && is_numeric($usage[$key]) && $usage[$key] >= 0) return (int) $usage[$key];
        return null;
    }

    private function write(callable $callback): mixed
    {
        try { return LockedWrite::run($callback, 'ai-usage'); }
        catch (Throwable $exception) {
            Log::error('AI usage could not be recorded', ['exception_type' => $exception::class]);
            return null; // A ledger failure must never cause a paid provider operation to be repeated.
        }
    }
}
