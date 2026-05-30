<?php

namespace App\Services\TelnyxAi;

use App\Models\VoiceCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Layer 1 of the v3 voice cockpit. Consumes Telnyx realtime webhook chunks and
 * persists them to voice_calls.metadata.live so the SSE stream and cockpit can
 * replay them. Where Telnyx does not provide a signal (interruption rate,
 * speaking pace) we derive it locally from transcript timestamps.
 */
class VoiceLiveStreamService
{
    public function __construct(private readonly VoiceIntelligenceService $intelligence)
    {
    }

    /**
     * Record a transcript chunk and recompute derived realtime signals.
     *
     * @param array{text?:string,speaker?:string,confidence?:float|int|null,sentiment?:string|float|null} $chunk
     */
    public function recordTranscriptChunk(VoiceCall $call, array $chunk): VoiceCall
    {
        $text = trim((string) ($chunk['text'] ?? ''));
        if ($text === '') {
            return $call;
        }

        $live = $this->live($call);
        $seq = (int) ($live['transcript_seq'] ?? 0) + 1;
        $speaker = in_array(($chunk['speaker'] ?? null), ['assistant', 'customer'], true)
            ? $chunk['speaker']
            : 'customer';
        $confidence = $this->normalizeConfidence($chunk['confidence'] ?? null);
        $sentiment = $this->normalizeSentiment($chunk['sentiment'] ?? null);
        $nowIso = now()->toIso8601String();

        $chunks = $live['transcript_chunks'] ?? [];
        $chunks[] = [
            'seq' => $seq,
            'text' => $text,
            'speaker' => $speaker,
            'ts' => $nowIso,
            'telnyx_confidence' => $confidence,
            'sentiment' => $sentiment,
        ];
        // Keep the tail bounded so metadata stays light.
        if (count($chunks) > 400) {
            $chunks = array_slice($chunks, -400);
        }

        $live['transcript_seq'] = $seq;
        $live['transcript_chunks'] = $chunks;
        $live['realtime'] = $this->deriveRealtime($chunks, $confidence, $sentiment, $live['realtime'] ?? []);

        $call->forceFill([
            'metadata' => array_merge($call->metadata ?? [], ['live' => $live]),
            // Keep the flat transcript column in sync for backwards compatibility.
            'transcript' => trim(($call->transcript ? $call->transcript . "\n" : '') . $text),
        ])->save();

        $this->intelligence->onRealtimeUpdate($call->fresh(), $this->triggerSignals($live, $text));

        return $call->fresh();
    }

    /**
     * Record an assistant lifecycle event (assistant_started, tool_called...).
     */
    public function recordAssistantEvent(VoiceCall $call, string $type, array $payload = []): VoiceCall
    {
        $live = $this->live($call);
        $events = $live['assistant_events'] ?? [];
        $events[] = [
            'type' => $type,
            'ts' => now()->toIso8601String(),
            'payload' => $payload,
        ];
        if (count($events) > 200) {
            $events = array_slice($events, -200);
        }
        $live['assistant_events'] = $events;

        $call->forceFill([
            'metadata' => array_merge($call->metadata ?? [], ['live' => $live]),
        ])->save();

        return $call->fresh();
    }

    /**
     * Build the full SSE snapshot payload for a call.
     *
     * @return array<string,mixed>
     */
    public function snapshot(VoiceCall $call): array
    {
        $metadata = $call->metadata ?? [];
        $live = is_array($metadata['live'] ?? null) ? $metadata['live'] : [];

        return [
            'transcript' => [
                'seq' => $live['transcript_seq'] ?? 0,
                'chunks' => $live['transcript_chunks'] ?? [],
            ],
            'realtime' => $live['realtime'] ?? [],
            'assistant_events' => $live['assistant_events'] ?? [],
            'insights' => $metadata['intel_live'] ?? null,
            'final_summary' => $metadata['intel_final'] ?? null,
            'memory' => [
                'tier1' => $metadata['memory']['tier1'] ?? null,
                'tier2' => $metadata['memory']['tier2'] ?? null,
                'tier3' => $metadata['memory']['tier3'] ?? null,
            ],
            'status' => $call->status,
        ];
    }

    private function live(VoiceCall $call): array
    {
        $metadata = $call->metadata ?? [];
        return is_array($metadata['live'] ?? null) ? $metadata['live'] : [];
    }

    /**
     * Derive confidence, silence, speaking pace, and interruption rate from the
     * accumulated chunk timeline.
     *
     * @param array<int,array<string,mixed>> $chunks
     */
    private function deriveRealtime(array $chunks, ?float $confidence, ?string $sentiment, array $previous): array
    {
        $count = count($chunks);
        $last = $chunks[$count - 1] ?? null;
        $prev = $chunks[$count - 2] ?? null;

        $silenceSec = 0.0;
        if ($prev && $last) {
            try {
                $silenceSec = max(0, Carbon::parse($last['ts'])->floatDiffInSeconds(Carbon::parse($prev['ts'])));
            } catch (\Throwable $e) {
                $silenceSec = 0.0;
            }
        }

        // Speaking pace: words / elapsed minutes across recent window.
        $window = array_slice($chunks, -12);
        $words = 0;
        foreach ($window as $c) {
            $words += max(1, str_word_count((string) ($c['text'] ?? '')));
        }
        $pace = 0;
        $firstTs = $window[0]['ts'] ?? null;
        $lastTs = $last['ts'] ?? null;
        if ($firstTs && $lastTs) {
            try {
                $minutes = max(1 / 60, Carbon::parse($lastTs)->floatDiffInSeconds(Carbon::parse($firstTs)) / 60);
                $pace = (int) round($words / $minutes);
            } catch (\Throwable $e) {
                $pace = 0;
            }
        }

        // Interruption rate: fraction of speaker switches over total transitions.
        $switches = 0;
        $transitions = 0;
        for ($i = 1; $i < $count; $i++) {
            $transitions++;
            if (($chunks[$i]['speaker'] ?? null) !== ($chunks[$i - 1]['speaker'] ?? null)) {
                $switches++;
            }
        }
        $interruptionRate = $transitions > 0 ? round($switches / $transitions, 2) : 0.0;

        return [
            'confidence' => $confidence ?? ($previous['confidence'] ?? null),
            'sentiment' => $sentiment ?? ($previous['sentiment'] ?? null),
            'interruption_rate' => $interruptionRate,
            'silence_sec' => round($silenceSec, 1),
            'speaking_pace_wpm' => $pace,
            'last_keyword_hit' => $previous['last_keyword_hit'] ?? null,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function triggerSignals(array $live, string $text): array
    {
        return [
            'confidence' => $live['realtime']['confidence'] ?? null,
            'sentiment' => $live['realtime']['sentiment'] ?? null,
            'silence_sec' => $live['realtime']['silence_sec'] ?? 0,
            'text' => $text,
        ];
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $float = (float) $value;
        // Telnyx may send 0..1 or 0..100. Normalise to 0..1.
        if ($float > 1) {
            $float = $float / 100;
        }
        return max(0, min(1, round($float, 3)));
    }

    private function normalizeSentiment(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return strtolower($value);
        }
        if (is_numeric($value)) {
            $score = (float) $value;
            return match (true) {
                $score >= 0.5 => 'positive',
                $score <= -0.5 => 'negative',
                default => 'neutral',
            };
        }
        return null;
    }
}
