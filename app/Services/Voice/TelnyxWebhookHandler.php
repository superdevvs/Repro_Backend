<?php

namespace App\Services\Voice;

use App\Models\VoiceCall;
use App\Models\VoiceCallEvent;
use App\Services\TelnyxAi\VoiceWebhookProcessor;
use Illuminate\Support\Arr;

class TelnyxWebhookHandler
{
    public function __construct(private readonly VoiceWebhookProcessor $legacyProcessor) {}

    public function process(array $payload, string $rawBody): array
    {
        $data = $payload['data'] ?? $payload;
        $eventType = (string) ($data['event_type'] ?? $payload['event_type'] ?? $data['type'] ?? $payload['type'] ?? 'unknown');
        $eventId = (string) ($data['id'] ?? $payload['id'] ?? hash('sha256', $rawBody));

        $event = VoiceCallEvent::query()->firstOrCreate(
            ['idempotency_key' => 'telnyx:'.$eventId],
            [
                'provider' => 'telnyx',
                'event_type' => $eventType,
                'normalized_type' => $this->normalizedType($eventType),
                'raw_payload' => $payload,
                'received_at' => now(),
            ]
        );

        $result = $this->legacyProcessor->process($payload, $rawBody);
        $call = $this->findCall($data, $result['voice_call_id'] ?? null);

        if ($call) {
            $this->applyCarrierDiagnostics($call, $eventType, $data);
        }

        $event->forceFill([
            'voice_call_id' => $call?->id,
            'processed_at' => now(),
            'processing_error' => null,
        ])->save();

        return $result;
    }

    private function applyCarrierDiagnostics(VoiceCall $call, string $eventType, array $data): void
    {
        $payload = Arr::get($data, 'payload', Arr::get($data, 'data.payload', $data));
        $fields = [
            'provider_event_last_seen_at' => now(),
            'call_control_id' => $payload['call_control_id'] ?? $call->call_control_id,
            'telnyx_conversation_id' => $payload['conversation_id'] ?? $payload['telnyx_conversation_id'] ?? $call->telnyx_conversation_id,
        ];

        if (in_array($eventType, ['call.failed', 'call.no_answer', 'call.hangup'], true) && ! in_array($call->status, ['completed'], true)) {
            $fields['status'] = $eventType === 'call.no_answer' ? 'missed' : 'failed';
            $fields['telnyx_failure_code'] = $payload['failure_code'] ?? $payload['hangup_cause'] ?? $payload['sip_hangup_cause'] ?? null;
            $fields['carrier_failure_reason'] = $payload['failure_reason'] ?? $payload['hangup_source'] ?? $payload['cause'] ?? null;
        }

        $call->forceFill(array_filter($fields, static fn ($value) => $value !== null))->save();
    }

    private function findCall(array $data, mixed $voiceCallId): ?VoiceCall
    {
        if ($voiceCallId) {
            return VoiceCall::query()->find($voiceCallId);
        }

        $payload = Arr::get($data, 'payload', Arr::get($data, 'data.payload', $data));
        $callControlId = $payload['call_control_id'] ?? null;
        $conversationId = $payload['conversation_id'] ?? $payload['telnyx_conversation_id'] ?? null;

        if (! $callControlId && ! $conversationId) {
            return null;
        }

        return VoiceCall::query()
            ->when($callControlId, fn ($query) => $query->orWhere('call_control_id', $callControlId))
            ->when($conversationId, fn ($query) => $query->orWhere('telnyx_conversation_id', $conversationId))
            ->first();
    }

    private function normalizedType(string $eventType): string
    {
        return match ($eventType) {
            'call.failed', 'call.no_answer' => 'carrier_failure',
            'call.initiated', 'call.answered', 'call.bridged' => 'carrier_status',
            default => 'carrier_event',
        };
    }
}
