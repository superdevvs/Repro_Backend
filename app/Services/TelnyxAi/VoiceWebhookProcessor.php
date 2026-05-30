<?php

namespace App\Services\TelnyxAi;

use App\Models\AiChatSession;
use App\Models\TelnyxWebhookEvent;
use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

class VoiceWebhookProcessor
{
    public function __construct(
        private readonly TelnyxVoiceCallService $calls,
        private readonly VoiceRoutingService $routing,
        private readonly VoiceLiveStreamService $liveStream,
        private readonly VoiceIntelligenceService $intelligence,
    ) {
    }

    public function process(array $payload, string $rawBody): array
    {
        $data = $payload['data'] ?? $payload;
        $eventId = (string) ($data['id'] ?? $payload['id'] ?? '');
        $eventType = (string) ($data['event_type'] ?? $payload['event_type'] ?? $data['type'] ?? $payload['type'] ?? 'unknown');

        if ($eventId === '') {
            $eventId = hash('sha256', $rawBody);
        }

        $event = TelnyxWebhookEvent::query()->firstOrCreate(
            ['provider' => 'TELNYX', 'telnyx_event_id' => $eventId],
            [
                'channel' => 'VOICE',
                'event_type' => $eventType,
                'event_received_at' => now(),
                'raw_event_json' => $rawBody,
            ]
        );

        if ($event->processed_at) {
            return ['status' => 'duplicate', 'event_id' => $eventId];
        }

        try {
            $voiceCall = $this->handleEvent($eventType, $data);
            $event->forceFill([
                'related_voice_call_id' => $voiceCall?->id,
                'processed_at' => now(),
                'processing_error' => null,
            ])->save();

            return ['status' => 'processed', 'event_id' => $eventId, 'voice_call_id' => $voiceCall?->id];
        } catch (Throwable $e) {
            $event->forceFill(['processing_error' => $e->getMessage()])->save();
            throw $e;
        }
    }

    private function handleEvent(string $eventType, array $data): ?VoiceCall
    {
        return match ($eventType) {
            'call.initiated' => $this->handleInitiated($data),
            'call.gather.ended', 'call.dtmf.received' => $this->handleMenuInput($data),
            'call.assistant.transcript' => $this->handleTranscript($data),
            'call.recording.saved' => $this->handleRecording($data),
            'call.summary.created' => $this->handleSummary($data),
            'call.transfer.failed' => $this->handleTransferFailed($data),
            'call.hangup', 'call.ended' => $this->handleHangup($data),
            'call.failed', 'call.no_answer' => $this->handleMissed($data),
            'call.answered' => $this->handleAnswered($data),
            'call.bridged' => $this->updateStatus($data, 'active'),
            'call.transferred' => $this->updateDisposition($data, 'transferred'),
            default => $this->findCall($data),
        };
    }

    private function handleInitiated(array $data): VoiceCall
    {
        $payload = $this->payload($data);
        $from = (string) ($payload['from'] ?? $payload['from_phone_number'] ?? '');
        $to = (string) ($payload['to'] ?? $payload['to_phone_number'] ?? '');
        $resolved = $this->calls->resolveCaller($from);

        $voiceCall = VoiceCall::query()->firstOrCreate(
            ['call_control_id' => $payload['call_control_id'] ?? null],
            [
                'direction' => 'INBOUND',
                'status' => 'active',
                'from_phone' => $from,
                'to_phone' => $to,
                'caller_user_id' => $resolved['user']?->id,
                'caller_contact_id' => $resolved['contact']?->id,
                'assistant_id' => config('services.telnyx.voice.assistant_id'),
                'telnyx_conversation_id' => $payload['conversation_id'] ?? $payload['telnyx_conversation_id'] ?? null,
                'started_at' => now(),
                'metadata' => ['raw_initiated' => $payload],
            ]
        );

        $session = AiChatSession::query()->create([
            'user_id' => $voiceCall->caller_user_id ?: $this->fallbackSessionUserId(),
            'title' => 'Voice call ' . $voiceCall->id,
            'topic' => 'general',
            'state' => [],
            'meta' => [
                'assistant_id' => $voiceCall->assistant_id,
                'telnyx_conversation_id' => $voiceCall->telnyx_conversation_id,
                'call_control_id' => $voiceCall->call_control_id,
                'voice_call_id' => $voiceCall->id,
                'verified' => false,
            ],
            'engine' => 'telnyx_voice',
            'channel' => 'VOICE',
            'phone_e164' => $resolved['phone_e164'] ?? $from,
            'contact_id' => $voiceCall->caller_contact_id,
            'last_inbound_at' => now(),
        ]);

        $voiceCall->forceFill(['ai_chat_session_id' => $session->id])->save();
        $this->routing->beginInboundCall($voiceCall, $resolved);

        return $voiceCall->fresh();
    }

    private function handleMenuInput(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (!$voiceCall) {
            return null;
        }

        $resolved = $this->calls->resolveCaller((string) $voiceCall->from_phone);

        return $this->routing->routeMenuInput($voiceCall, $this->digitFrom($data), $resolved);
    }

    private function handleTranscript(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (!$voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        $line = trim((string) ($payload['transcript'] ?? $payload['text'] ?? ''));
        if ($line !== '') {
            // Layer 1: persist a structured realtime chunk (also keeps the flat
            // transcript column in sync) and let intelligence triggers evaluate.
            $voiceCall = $this->liveStream->recordTranscriptChunk($voiceCall, [
                'text' => $line,
                'speaker' => $this->speakerFrom($payload),
                'confidence' => $payload['confidence'] ?? $payload['telnyx_confidence'] ?? null,
                'sentiment' => $payload['sentiment'] ?? null,
            ]);
        }

        return $voiceCall;
    }

    private function handleRecording(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (!$voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        if ($voiceCall->recording_consent_given) {
            $voiceCall->forceFill(['recording_url' => $payload['recording_urls'][0] ?? $payload['recording_url'] ?? null])->save();
        }

        return $voiceCall;
    }

    private function handleSummary(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (!$voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        $voiceCall->forceFill(['summary' => $payload['summary'] ?? $payload['text'] ?? null])->save();

        return $voiceCall;
    }

    private function handleHangup(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (!$voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        $started = $voiceCall->started_at;
        $ended = now();
        $disposition = $voiceCall->disposition ?: 'caller_hangup';
        if ($voiceCall->intent === 'routing' && $voiceCall->menu_digit === null && !$voiceCall->ai_chat_session_id) {
            $disposition = 'missed';
        }

        $voiceCall->forceFill([
            'status' => 'completed',
            'disposition' => $disposition,
            'ended_at' => $ended,
            'duration_seconds' => $payload['duration_seconds'] ?? ($started ? $started->diffInSeconds($ended) : null),
        ])->save();

        if ($voiceCall->aiChatSession) {
            $voiceCall->aiChatSession->forceFill([
                'meta' => array_merge($voiceCall->aiChatSession->meta ?? [], ['closed_at' => now()->toIso8601String()]),
            ])->save();
        }

        // Layer 2: always-on final enrichment when the call ends.
        try {
            $this->intelligence->finalize($voiceCall->fresh());
        } catch (Throwable $e) {
            \Log::warning('Voice final enrichment failed', ['error' => $e->getMessage(), 'call' => $voiceCall->id]);
        }

        return $voiceCall->fresh();
    }

    private function handleAnswered(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (!$voiceCall) {
            return null;
        }

        $voiceCall->forceFill([
            'status' => 'active',
            'started_at' => $voiceCall->started_at ?: now(),
        ])->save();

        // Telnyx's POST /v2/calls does NOT auto-start the AI assistant when
        // the called party answers an outbound call. Without an explicit
        // assistant_start the audio leg is silent for the recipient. Trigger
        // the assistant here for outbound calls that have an assistant
        // configured. Inbound calls go through VoiceRoutingService at
        // call.initiated time and do not need this step.
        if (
            strtoupper((string) $voiceCall->direction) === 'OUTBOUND'
            && !empty($voiceCall->assistant_id)
            && !empty($voiceCall->call_control_id)
        ) {
            $resolved = $this->calls->resolveCaller((string) ($voiceCall->to_phone ?: $voiceCall->from_phone));
            $dynamicVariables = $this->calls->buildDynamicVariables($voiceCall, $resolved);
            $existing = (array) ($voiceCall->metadata['dynamic_variables'] ?? []);
            $merged = array_merge($existing, $dynamicVariables);

            $this->calls->startAssistant($voiceCall, $merged);
        }

        return $voiceCall->fresh();
    }

    private function handleTransferFailed(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (!$voiceCall) {
            return null;
        }

        return $this->routing->createCallback($voiceCall, 'transfer_failed');
    }

    private function handleMissed(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (!$voiceCall) {
            return null;
        }

        return $this->routing->createCallback($voiceCall, 'missed_call');
    }

    private function updateStatus(array $data, string $status): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        $voiceCall?->forceFill(['status' => $status])->save();
        return $voiceCall;
    }

    private function updateDisposition(array $data, string $disposition): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        $voiceCall?->forceFill(['status' => 'transferred', 'disposition' => $disposition])->save();
        return $voiceCall;
    }

    private function findCall(array $data): ?VoiceCall
    {
        $payload = $this->payload($data);
        $callControlId = $payload['call_control_id'] ?? null;
        $conversationId = $payload['conversation_id'] ?? $payload['telnyx_conversation_id'] ?? null;
        $clientState = $payload['client_state'] ?? null;

        return VoiceCall::query()
            ->when($callControlId, fn ($q) => $q->orWhere('call_control_id', $callControlId))
            ->when($conversationId, fn ($q) => $q->orWhere('telnyx_conversation_id', $conversationId))
            ->when($clientState, fn ($q) => $q->orWhere('client_state', $clientState))
            ->first();
    }

    private function payload(array $data): array
    {
        return Arr::get($data, 'payload', Arr::get($data, 'data.payload', $data));
    }

    private function speakerFrom(array $payload): string
    {
        $role = strtolower((string) ($payload['speaker'] ?? $payload['role'] ?? $payload['participant'] ?? ''));
        if (str_contains($role, 'assistant') || str_contains($role, 'agent') || str_contains($role, 'bot')) {
            return 'assistant';
        }
        return 'customer';
    }

    private function digitFrom(array $data): ?string
    {
        $payload = $this->payload($data);
        $digits = $payload['digits'] ?? $payload['digit'] ?? $payload['dtmf_digit'] ?? null;

        if (is_array($digits)) {
            $digits = implode('', array_map('strval', $digits));
        }

        $digits = trim((string) $digits);

        return $digits !== '' ? mb_substr($digits, 0, 1) : null;
    }

    private function fallbackSessionUserId(): int
    {
        $userId = User::query()
            ->whereIn('role', ['superadmin', 'admin'])
            ->orderBy('id')
            ->value('id');

        return (int) $userId;
    }
}
