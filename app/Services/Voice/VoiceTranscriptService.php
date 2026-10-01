<?php

namespace App\Services\Voice;

use App\Models\VoiceCall;
use App\Services\TelnyxAi\VoiceLiveStreamService;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Support\Facades\DB;

class VoiceTranscriptService
{
    public function rebuild(VoiceCall $call): VoiceCall
    {
        $call = app(VoiceConversationHistoryService::class)->restoreSaved($call);

        return VoiceLocks::lock('voice-transcript:'.$call->id, 30)->block(3, fn () => LockedWrite::run(fn () => DB::transaction(
            fn () => app(VoiceLiveStreamService::class)->projectSavedTranscript($call->fresh())
        ), 'voice.transcript.rebuild'));
    }

    public function state(VoiceCall $call): array
    {
        $metadata = $call->metadata ?? [];
        $last = $call->transcriptRows()->latest('created_at')->latest('id')->first();
        $count = $call->transcriptRows()->where('transcript_type', 'final')->count();
        $text = trim((string) $call->transcript);
        $enabled = (bool) ($metadata['browser_transcription_enabled'] ?? false);
        $pending = (bool) ($metadata['browser_transcription_pending'] ?? false);
        $human = ($metadata['source'] ?? '') === 'browser_staff' || in_array($call->handled_by, ['human', 'mixed'], true);
        $ended = $call->ended_at !== null;
        $quiet = $last?->created_at ?? $call->ended_at;
        if ($ended) {
            // Restoring an old final snapshot creates new rows. Their insertion time
            // must not restart the delivery grace period once all final history is saved.
            $finalHistorySaved = data_get($metadata, 'provider_history.final') === true && $text !== ''
                && ($expected = (int) data_get($metadata, 'provider_history.message_count', 0)) > 0 && $count === $expected
                && $call->transcriptRows()->where('transcript_type', 'final')
                    ->where('provider_message_id', 'like', 'telnyx-history:'.$call->id.':%')->count() === $expected;
            $finalizing = ! $finalHistorySaved && ($call->ended_at->gt(now()->subSeconds(90)) || $quiet?->gt(now()->subSeconds(90)));
            $state = $finalizing && ($enabled || $text !== '' || ! $human) ? 'finalizing' : ($text !== '' ? ($pending ? 'partial' : 'ready') : 'unavailable');
        } elseif ($text !== '') {
            $state = $pending || ($enabled && $last?->created_at->lt(now()->subSeconds(60))) ? 'delayed' : 'live';
        } elseif ($human && ! $enabled && ! $pending) {
            $state = 'off';
        } else {
            $state = $pending ? 'delayed' : 'starting';
        }
        $stale = (bool) ($metadata['transcript_projection']['summary_stale'] ?? false)
            || ($call->summary_generated_at && $last?->created_at->gt($call->summary_generated_at));
        $message = match ($state) {
            'off' => 'Transcription is off. Capture requires caller consent and an explicit start.',
            'starting' => 'Waiting for the provider to send a final speech segment.',
            'live' => 'Saved speech segments are appearing. Silence does not create transcript text.',
            'delayed' => 'No recent final segment or capture confirmation. Check the call audio and capture status.',
            'finalizing' => 'The call ended. Final segments may still arrive; refresh this conversation.',
            'ready' => 'Saved transcript available. Provider delivery does not guarantee every word was captured.',
            'partial' => 'Saved segments are available, but capture reported a problem. The transcript may be incomplete.',
            default => 'No transcript was received. Refresh can restore saved segments, but cannot recover audio that was never captured.',
        };
        $recoveries = app(VoiceTranscriptRecoveryService::class);
        $recovery = $recoveries->latest($call);
        $source = ($metadata['transcript_projection']['source'] ?? 'live_segments');
        if ($source === 'recording_recovery') {
            $state = 'ready';
            $message = 'Recovered from recorded customer audio. Recording may cover only part of the call; speakers are not identified.';
        }
        $canRetry = $ended && $recoveries->source($call) !== null && filled(config('services.telnyx.api_key'))
            && (! $recovery || $recovery->recording_id !== data_get($metadata, 'recording_id')
                || (in_array($recovery->status, ['failed', 'uncertain'], true) && $recovery->attempt < 3));

        return ['transcript' => $text, 'display_transcript' => $this->displayTranscript($call, $text, $source),
            'state' => $state, 'last_chunk_at' => $last?->created_at?->toIso8601String(),
            'segment_count' => $count, 'summary_stale' => (bool) $stale, 'can_rebuild' => $count > 0 || $source === 'recording_recovery',
            'completeness' => 'provider_unverified', 'message' => $message,
            'source' => $source, 'can_retry_recording' => $canRetry,
            'recovery' => $recovery ? ['id' => $recovery->id, 'status' => $recovery->status, 'attempt' => $recovery->attempt,
                'error' => $recovery->error, 'source' => 'customer_recording'] : null,
            'recording_available' => (bool) ($call->recording_consent_given && ($call->recording_url || ! empty($metadata['recording_id'])))];
    }

    private function displayTranscript(VoiceCall $call, string $text, string $source): string
    {
        if ($source === 'recording_recovery' || (! str_contains($text, '<break') && ! str_contains($text, '<emotion'))) {
            return $text;
        }
        $rows = $call->transcriptRows()->where('transcript_type', 'final')->orderBy('occurred_at')->orderBy('id')
            ->get(['speaker', 'text', 'provider_message_id']);
        $prefixSpeakers = $rows->contains(fn ($row) => str_starts_with((string) $row->provider_message_id, 'telnyx:'));
        $raw = $rows->map(fn ($row) => ($prefixSpeakers ? $row->speaker.': ' : '').$row->text)->implode("\n");
        // Never infer speakers from a legacy string or replace a different projection.
        if ($rows->isEmpty() || $raw !== $text) {
            return $text;
        }

        return $rows->map(function ($row) use ($prefixSpeakers) {
            $display = $row->text;
            if ($row->speaker === 'assistant') {
                // Strip only recognized provider directives, not arbitrary HTML or
                // customer literals. Durable rows and provider event payloads stay raw.
                $withoutDirectives = preg_replace([
                    '~[ \t]*<break\s+time\s*=\s*([\'"])(?:\d+(?:\.\d+)?|\.\d+)(?:ms|s)\1\s*/>[ \t]*~',
                    '~[ \t]*(?:<emotion\s+value\s*=\s*([\'"])(?:happy|calm)\1\s*/>[ \t]*)+~',
                ], ' ', $display, -1, $replaced);
                if ($replaced > 0 && $withoutDirectives !== null) {
                    $display = trim($withoutDirectives);
                }
            }

            return ($prefixSpeakers ? $row->speaker.': ' : '').$display;
        })->implode("\n");
    }
}
