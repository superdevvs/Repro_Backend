<?php

namespace App\Services\Voice;

use App\Models\VoiceCall;
use App\Models\VoiceCallEvent;
use App\Models\VoiceCallTranscript;
use App\Services\TelnyxAi\VoiceLiveStreamService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class VapiWebhookHandler
{
    public function __construct(
        private readonly VoiceCallService $calls,
        private readonly VoiceCallStateMapper $states,
        private readonly VoiceLiveStreamService $liveStream,
        private readonly VoiceToolBridge $tools,
    ) {
    }

    public function process(array $payload, string $rawBody): array
    {
        $message = $this->message($payload);
        $type = (string) ($message['type'] ?? $payload['type'] ?? 'unknown');
        $event = VoiceCallEvent::query()->firstOrCreate(
            ['idempotency_key' => $this->idempotencyKey($message, $rawBody, $type)],
            [
                'provider' => 'vapi',
                'event_type' => $type,
                'normalized_type' => $this->normalizedType($type),
                'raw_payload' => $payload,
                'received_at' => now(),
            ]
        );

        if ($event->processed_at && !$this->isToolEvent($type)) {
            return ['status' => 'duplicate', 'event_id' => $event->id];
        }

        try {
            $call = $this->calls->upsertFromVapiCall((array) ($message['call'] ?? []));
            $result = $this->handleMessage($type, $message, $call);

            $event->forceFill([
                'voice_call_id' => $call?->id,
                'processed_at' => now(),
                'processing_error' => null,
            ])->save();

            return array_merge([
                'status' => 'processed',
                'event_id' => $event->id,
                'voice_call_id' => $call?->id,
            ], $result);
        } catch (Throwable $exception) {
            $event->forceFill(['processing_error' => $exception->getMessage()])->save();
            throw $exception;
        }
    }

    private function handleMessage(string $type, array $message, ?VoiceCall $call): array
    {
        $normalized = strtolower($type);

        if ($call && $normalized === 'status-update') {
            $this->handleStatusUpdate($call, $message);
            return [];
        }

        if ($call && $normalized === 'transcript') {
            $this->handleTranscript($call, $message);
            return [];
        }

        if ($call && in_array($normalized, ['assistant-started', 'assistant.started', 'assistant-speech-started', 'speech-update', 'model-output'], true)) {
            $this->handleAssistantState($call, $message);
            return [];
        }

        if ($call && $this->isToolEvent($normalized)) {
            return ['tool_response' => $this->handleToolCalls($call, $message)];
        }

        if ($call && $normalized === 'end-of-call-report') {
            $this->handleEndOfCallReport($call, $message);
            return [];
        }

        if ($call && $normalized === 'hang') {
            $this->handleHang($call, $message);
            return [];
        }

        if ($call && ($mapped = $this->states->fromVapiMessageType($type))) {
            $call->forceFill([
                'status' => $mapped,
                'provider_event_last_seen_at' => now(),
            ])->save();
        }

        return [];
    }

    private function handleStatusUpdate(VoiceCall $call, array $message): void
    {
        $providerStatus = (string) ($message['status'] ?? Arr::get($message, 'call.status', ''));
        $status = $this->states->fromVapiStatus($providerStatus);
        $fields = [
            'status' => $status,
            'external_provider_status' => $providerStatus ?: null,
            'provider_event_last_seen_at' => now(),
        ];

        if (in_array($status, ['answered', 'ai_active'], true)) {
            $fields['answered_at'] = $call->answered_at ?: now();
            $fields['started_at'] = $call->started_at ?: now();
        }
        if ($this->states->isClosed($status)) {
            $fields['ended_at'] = $call->ended_at ?: now();
        }

        $call->forceFill($fields)->save();
    }

    private function handleAssistantState(VoiceCall $call, array $message): void
    {
        $role = strtolower((string) ($message['role'] ?? $message['speaker'] ?? 'assistant'));
        $state = (string) ($message['status'] ?? $message['state'] ?? 'active');

        $call->forceFill([
            'status' => 'ai_active',
            'handled_by' => 'ai',
            'answered_at' => $call->answered_at ?: now(),
            'started_at' => $call->started_at ?: now(),
            'ai_current_state' => $state,
            'ai_current_speaker' => str_contains($role, 'user') ? 'user' : 'assistant',
            'provider_event_last_seen_at' => now(),
        ])->save();
    }

    private function handleTranscript(VoiceCall $call, array $message): void
    {
        $text = trim((string) ($message['transcript'] ?? $message['text'] ?? $message['message'] ?? ''));
        if ($text === '') {
            return;
        }

        $speaker = $this->speaker($message['role'] ?? $message['speaker'] ?? null);
        $type = strtolower((string) ($message['transcriptType'] ?? $message['transcript_type'] ?? 'final'));
        $confidence = $this->confidence($message['confidence'] ?? null);

        VoiceCallTranscript::query()->create([
            'voice_call_id' => $call->id,
            'provider_message_id' => $message['id'] ?? $message['messageId'] ?? null,
            'speaker' => $speaker === 'customer' ? 'user' : $speaker,
            'transcript_type' => in_array($type, ['partial', 'final'], true) ? $type : 'final',
            'text' => $text,
            'confidence' => $confidence,
            'occurred_at' => now(),
        ]);

        $call = $this->liveStream->recordTranscriptChunk($call, [
            'text' => $text,
            'speaker' => $speaker,
            'confidence' => $confidence,
            'sentiment' => $message['sentiment'] ?? null,
        ]);

        $call->forceFill([
            'status' => $call->status === 'tool_running' ? $call->status : 'ai_active',
            'live_transcript_preview' => Str::limit($text, 500, ''),
            'ai_current_speaker' => $speaker === 'customer' ? 'user' : 'assistant',
            'provider_event_last_seen_at' => now(),
        ])->save();
    }

    private function handleToolCalls(VoiceCall $call, array $message): array
    {
        $call->forceFill([
            'status' => 'tool_running',
            'ai_current_state' => 'tool_running',
            'provider_event_last_seen_at' => now(),
        ])->save();

        $responses = [];
        foreach ($this->toolCalls($message) as $toolCall) {
            $responses[] = [
                'toolCallId' => $toolCall['id'] ?? null,
                'result' => $this->tools->handle(
                    $call,
                    (string) $toolCall['name'],
                    (array) ($toolCall['arguments'] ?? []),
                    $toolCall['id'] ?? null
                ),
            ];
        }

        return count($responses) === 1
            ? ['result' => $responses[0]['result'], 'results' => $responses]
            : ['results' => $responses];
    }

    private function handleEndOfCallReport(VoiceCall $call, array $message): void
    {
        $artifact = (array) ($message['artifact'] ?? []);
        $analysis = (array) ($message['analysis'] ?? []);
        $structured = (array) (
            $analysis['structuredData']
            ?? $analysis['structuredOutput']
            ?? $message['structuredData']
            ?? $message['structuredOutput']
            ?? []
        );
        $endedReason = (string) ($message['endedReason'] ?? $message['ended_reason'] ?? '');
        $summary = $message['summary'] ?? $analysis['summary'] ?? $structured['summary'] ?? Arr::get($artifact, 'summary');
        $transcript = $artifact['transcript'] ?? $message['transcript'] ?? null;
        $recordingUrl = $this->recordingUrl($artifact, $message);
        $endedAt = now();
        $startedAt = $call->started_at;

        $call->forceFill([
            'status' => 'completed',
            'handled_by' => $call->handled_by ?: 'ai',
            'external_provider_status' => 'ended',
            'vapi_ended_reason' => $endedReason ?: null,
            'summary' => $summary ?: $call->summary,
            'transcript' => $transcript ?: $call->transcript,
            'recording_url' => $recordingUrl ?: $call->recording_url,
            'recording_provider' => $recordingUrl ? 'vapi' : $call->recording_provider,
            'intent' => $structured['intent'] ?? $analysis['intent'] ?? $call->intent,
            'sentiment' => $structured['sentiment'] ?? $analysis['sentiment'] ?? $call->sentiment,
            'booking_probability' => $structured['booking_probability'] ?? $structured['bookingProbability'] ?? $call->booking_probability,
            'needs_follow_up' => (bool) ($structured['needs_follow_up'] ?? $structured['needsFollowUp'] ?? $call->needs_follow_up),
            'summary_generated_at' => $summary ? now() : $call->summary_generated_at,
            'ended_at' => $call->ended_at ?: $endedAt,
            'duration_seconds' => $message['durationSeconds'] ?? $message['duration_seconds'] ?? ($startedAt ? $startedAt->diffInSeconds($endedAt) : $call->duration_seconds),
            'provider_event_last_seen_at' => now(),
            'metadata' => $this->calls->mergeMetadata($call, [
                'vapi_end_report' => $message,
                'intel_final' => array_filter([
                    'customer_mood' => $structured['sentiment'] ?? $analysis['sentiment'] ?? null,
                    'intent' => $structured['intent'] ?? $analysis['intent'] ?? null,
                    'summary_text' => $summary,
                    'follow_up_at' => $structured['follow_up_at'] ?? $structured['followUpAt'] ?? null,
                    'human_takeover_recommended' => $structured['human_takeover_recommended'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''),
            ]),
        ])->save();
    }

    private function handleHang(VoiceCall $call, array $message): void
    {
        $endedReason = (string) ($message['endedReason'] ?? $message['reason'] ?? '');
        $status = $this->states->endedStatus((bool) $call->answered_at, $endedReason);

        $call->forceFill([
            'status' => $status,
            'vapi_ended_reason' => $endedReason ?: $call->vapi_ended_reason,
            'ended_at' => $call->ended_at ?: now(),
            'provider_event_last_seen_at' => now(),
        ])->save();
    }

    private function toolCalls(array $message): array
    {
        $calls = $message['toolCallList'] ?? $message['toolCalls'] ?? $message['tool_calls'] ?? null;
        if (is_array($calls)) {
            return array_values(array_filter(array_map(fn ($call) => $this->normalizeToolCall((array) $call), $calls)));
        }

        $function = $message['functionCall'] ?? $message['function_call'] ?? null;
        if (is_array($function)) {
            return array_filter([$this->normalizeToolCall($function)]);
        }

        return [];
    }

    private function normalizeToolCall(array $toolCall): ?array
    {
        $function = (array) ($toolCall['function'] ?? $toolCall);
        $name = $function['name'] ?? $toolCall['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        $arguments = $function['arguments'] ?? $toolCall['arguments'] ?? $function['parameters'] ?? $toolCall['parameters'] ?? [];
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => $toolCall['id'] ?? $toolCall['toolCallId'] ?? $function['id'] ?? null,
            'name' => $name,
            'arguments' => is_array($arguments) ? $arguments : [],
        ];
    }

    private function message(array $payload): array
    {
        $message = $payload['message'] ?? $payload;
        return is_array($message) ? $message : [];
    }

    private function idempotencyKey(array $message, string $rawBody, string $type): string
    {
        $explicit = $message['id'] ?? $message['eventId'] ?? $message['messageId'] ?? null;
        $callId = Arr::get($message, 'call.id', 'no-call');
        return 'vapi:' . ($explicit ?: "{$callId}:{$type}:" . hash('sha256', $rawBody));
    }

    private function normalizedType(string $type): string
    {
        return match (strtolower($type)) {
            'status-update' => 'status',
            'transcript' => 'transcript',
            'function-call', 'function_call', 'tool-call', 'tool-calls' => 'tool_call',
            'end-of-call-report' => 'end_report',
            'hang' => 'hang',
            default => strtolower($type),
        };
    }

    private function isToolEvent(string $type): bool
    {
        return in_array(strtolower($type), ['function-call', 'function_call', 'tool-call', 'tool-calls'], true);
    }

    private function speaker(mixed $role): string
    {
        $normalized = strtolower((string) $role);
        if (str_contains($normalized, 'assistant') || str_contains($normalized, 'bot') || str_contains($normalized, 'agent')) {
            return 'assistant';
        }

        return 'customer';
    }

    private function confidence(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;
        return $float > 1 ? min(1, $float / 100) : max(0, min(1, $float));
    }

    private function recordingUrl(array $artifact, array $message): ?string
    {
        $url = $message['recordingUrl']
            ?? $artifact['recordingUrl']
            ?? Arr::get($artifact, 'recording.url')
            ?? Arr::get($artifact, 'recording.stereoUrl')
            ?? Arr::get($artifact, 'recording.mono.combinedUrl')
            ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
