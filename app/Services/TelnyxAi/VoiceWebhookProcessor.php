<?php

namespace App\Services\TelnyxAi;

use App\Models\AiChatSession;
use App\Models\AiMessage;
use App\Models\TelnyxWebhookEvent;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallTranscript;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class VoiceWebhookProcessor
{
    public function __construct(
        private readonly TelnyxVoiceCallService $calls,
        private readonly VoiceRoutingService $routing,
        private readonly VoiceLiveStreamService $liveStream,
        private readonly VoiceIntelligenceService $intelligence,
    ) {}

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
            'call.ai_assistant.message_history.updated',
            'call.ai_assistant.message_history_updated',
            'call.assistant.message_history.updated' => $this->handleMessageHistory($data),
            'call.recording.saved' => $this->handleRecording($data),
            'call.summary.created' => $this->handleSummary($data),
            'call.conversation.ended' => $this->handleConversationEnded($data),
            'call.conversation_insights.generated' => $this->handleConversationInsights($data),
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
        $voiceCall = $this->findCall($data);
        $direction = strtoupper((string) ($voiceCall?->direction ?? $this->directionFrom($payload)));
        $callerPhone = $direction === 'OUTBOUND' ? $to : $from;
        $resolved = $this->calls->resolveCaller($callerPhone);

        if (! $voiceCall) {
            $voiceCall = VoiceCall::query()->create([
                'provider' => 'telnyx',
                'direction' => $direction,
                'status' => $direction === 'INBOUND' ? 'active' : 'dialing',
                'external_provider_status' => 'initiated',
                'handled_by' => 'ai',
                'from_phone' => $from,
                'to_phone' => $to,
                'caller_user_id' => $resolved['user']?->id,
                'caller_contact_id' => $resolved['contact']?->id,
                'assistant_id' => config('services.telnyx.voice.assistant_id'),
                'call_control_id' => $payload['call_control_id'] ?? null,
                'telnyx_conversation_id' => $payload['conversation_id'] ?? $payload['telnyx_conversation_id'] ?? null,
                'client_state' => $payload['client_state'] ?? null,
                'started_at' => now(),
                'provider_event_last_seen_at' => now(),
                'metadata' => ['raw_initiated' => $payload],
            ]);
        } else {
            $voiceCall->forceFill([
                'provider' => 'telnyx',
                'call_control_id' => $payload['call_control_id'] ?? $voiceCall->call_control_id,
                'external_provider_status' => 'initiated',
                'provider_event_last_seen_at' => now(),
                'caller_user_id' => $voiceCall->caller_user_id ?: $resolved['user']?->id,
                'caller_contact_id' => $voiceCall->caller_contact_id ?: $resolved['contact']?->id,
                'metadata' => array_merge($voiceCall->metadata ?? [], ['raw_initiated' => $payload]),
            ])->save();
        }

        $this->ensureSession($voiceCall, $resolved, $callerPhone);
        if ($direction === 'INBOUND' && empty(($voiceCall->metadata ?? [])['inbound_routing_started_at'])) {
            $voiceCall->forceFill([
                'metadata' => array_merge($voiceCall->metadata ?? [], ['inbound_routing_started_at' => now()->toIso8601String()]),
            ])->save();
            $this->routing->beginInboundCall($voiceCall, $resolved);
        }

        return $voiceCall->fresh();
    }

    private function handleMenuInput(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (! $voiceCall) {
            return null;
        }

        $resolved = $this->calls->resolveCaller((string) $voiceCall->from_phone);

        return $this->routing->routeMenuInput($voiceCall, $this->digitFrom($data), $resolved);
    }

    private function handleTranscript(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (! $voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        $line = trim((string) ($payload['transcript'] ?? $payload['text'] ?? ''));
        if ($line !== '') {
            $voiceCall = $this->persistTranscript($voiceCall, [
                'id' => $payload['message_id'] ?? $payload['id'] ?? null,
                'text' => $line,
                'speaker' => $this->speakerFrom($payload),
                'confidence' => $payload['confidence'] ?? $payload['telnyx_confidence'] ?? null,
                'occurred_at' => $payload['occurred_at'] ?? null,
                'sentiment' => $payload['sentiment'] ?? null,
            ]);
        }

        return $voiceCall;
    }

    private function handleRecording(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (! $voiceCall) {
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
        if (! $voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        $voiceCall->forceFill(['summary' => $payload['summary'] ?? $payload['text'] ?? null])->save();

        return $voiceCall;
    }

    private function handleMessageHistory(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (! $voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        $messages = $payload['message_history'] ?? $payload['messages'] ?? $payload['message'] ?? [];
        if (isset($messages['role']) || isset($messages['content'])) {
            $messages = [$messages];
        }

        foreach (is_array($messages) ? $messages : [] as $message) {
            if (! is_array($message)) {
                continue;
            }
            $text = $this->messageText($message['content'] ?? $message['text'] ?? '');
            $role = strtolower((string) ($message['role'] ?? $message['speaker'] ?? ''));
            if ($text === '' || ! in_array($role, ['user', 'customer', 'assistant', 'agent'], true)) {
                continue;
            }
            $voiceCall = $this->persistTranscript($voiceCall, [
                'id' => $message['id'] ?? $message['message_id'] ?? null,
                'text' => $text,
                'speaker' => in_array($role, ['assistant', 'agent'], true) ? 'assistant' : 'customer',
                'confidence' => $message['confidence'] ?? null,
                'occurred_at' => $message['created_at'] ?? $message['occurred_at'] ?? null,
            ]);
        }

        return $voiceCall->fresh();
    }

    private function handleConversationEnded(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (! $voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        $voiceCall->forceFill([
            'status' => 'completed',
            'external_provider_status' => 'conversation_ended',
            'provider_event_last_seen_at' => now(),
            'telnyx_conversation_id' => $payload['conversation_id'] ?? $voiceCall->telnyx_conversation_id,
            'duration_seconds' => $payload['duration_sec'] ?? $payload['duration_seconds'] ?? $voiceCall->duration_seconds,
            'ended_at' => $voiceCall->ended_at ?: now(),
            'metadata' => array_merge($voiceCall->metadata ?? [], [
                'telnyx_conversation' => array_filter([
                    'llm_model' => $payload['llm_model'] ?? null,
                    'stt_model' => $payload['stt_model'] ?? null,
                    'tts_provider' => $payload['tts_provider'] ?? null,
                    'tts_model_id' => $payload['tts_model_id'] ?? null,
                    'tts_voice_id' => $payload['tts_voice_id'] ?? null,
                ], static fn ($value) => $value !== null),
            ]),
        ])->save();

        $this->mergeSessionMeta($voiceCall, [
            'telnyx_conversation_id' => $payload['conversation_id'] ?? $voiceCall->telnyx_conversation_id,
            'conversation_ended_at' => now()->toIso8601String(),
            'closed_at' => now()->toIso8601String(),
            'duration_seconds' => $payload['duration_sec'] ?? $payload['duration_seconds'] ?? $voiceCall->duration_seconds,
            'conversation_end_reason' => $payload['reason'] ?? null,
        ]);

        return $voiceCall->fresh();
    }

    private function handleConversationInsights(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (! $voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $summary = collect($results)
            ->pluck('result')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->implode("\n");
        $voiceCall->forceFill([
            'summary' => $summary !== '' ? $summary : $voiceCall->summary,
            'summary_generated_at' => now(),
            'provider_event_last_seen_at' => now(),
            'metadata' => array_merge($voiceCall->metadata ?? [], ['telnyx_insights' => $results]),
        ])->save();

        $this->mergeSessionMeta($voiceCall, [
            'summary' => $summary !== '' ? $summary : $voiceCall->summary,
            'telnyx_insights' => $results,
            'insight_group_id' => $payload['insight_group_id'] ?? null,
            'insights_generated_at' => now()->toIso8601String(),
        ]);

        return $voiceCall->fresh();
    }

    private function handleHangup(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (! $voiceCall) {
            return null;
        }

        $payload = $this->payload($data);
        $started = $voiceCall->started_at;
        $ended = now();
        $disposition = $voiceCall->disposition ?: 'caller_hangup';
        if ($voiceCall->intent === 'routing' && $voiceCall->menu_digit === null && ! $voiceCall->ai_chat_session_id) {
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
        if (! $voiceCall) {
            return null;
        }

        $voiceCall->forceFill([
            'status' => 'active',
            'external_provider_status' => 'answered',
            'provider_event_last_seen_at' => now(),
            'started_at' => $voiceCall->started_at ?: now(),
            'answered_at' => $voiceCall->answered_at ?: now(),
        ])->save();

        // Telnyx's POST /v2/calls does NOT auto-start the AI assistant when
        // the called party answers an outbound call. Without an explicit
        // assistant_start the audio leg is silent for the recipient. Trigger
        // the assistant here for outbound calls that have an assistant
        // configured. Inbound calls go through VoiceRoutingService at
        // call.initiated time and do not need this step.
        if (
            strtoupper((string) $voiceCall->direction) === 'OUTBOUND'
            && ! empty($voiceCall->assistant_id)
            && ! empty($voiceCall->call_control_id)
        ) {
            $resolved = $this->calls->resolveCaller((string) ($voiceCall->to_phone ?: $voiceCall->from_phone));
            $dynamicVariables = $this->calls->buildDynamicVariables($voiceCall, $resolved);
            $existing = (array) ($voiceCall->metadata['dynamic_variables'] ?? []);
            $merged = array_merge($existing, $dynamicVariables);

            if (! $this->calls->startAssistant($voiceCall, $merged)) {
                // Leave this webhook unprocessed so Telnyx can retry the same
                // answered event after a transient Call Control failure.
                throw new RuntimeException('Telnyx AI assistant start failed.');
            }
        }

        return $voiceCall->fresh();
    }

    private function handleTransferFailed(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (! $voiceCall) {
            return null;
        }

        return $this->routing->createCallback($voiceCall, 'transfer_failed');
    }

    private function handleMissed(array $data): ?VoiceCall
    {
        $voiceCall = $this->findCall($data);
        if (! $voiceCall) {
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

        if (! $callControlId && ! $conversationId && ! $clientState) {
            return null;
        }

        return VoiceCall::query()->where(function ($query) use ($callControlId, $conversationId, $clientState): void {
            if ($callControlId) {
                $query->orWhere('call_control_id', $callControlId);
            }
            if ($conversationId) {
                $query->orWhere('telnyx_conversation_id', $conversationId);
            }
            if ($clientState) {
                $query->orWhere('client_state', $clientState);
            }
        })->first();
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

    private function ensureSession(VoiceCall $voiceCall, array $resolved, string $phone): void
    {
        if ($voiceCall->ai_chat_session_id) {
            return;
        }

        $session = AiChatSession::query()->create([
            'user_id' => $voiceCall->caller_user_id ?: $resolved['contact']?->user_id ?: $this->fallbackSessionUserId(),
            'title' => 'Voice call '.$voiceCall->id,
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
            'phone_e164' => $resolved['phone_e164'] ?? $phone,
            'contact_id' => $voiceCall->caller_contact_id,
            'last_inbound_at' => now(),
        ]);

        $voiceCall->forceFill(['ai_chat_session_id' => $session->id])->save();
    }

    private function directionFrom(array $payload): string
    {
        $direction = strtolower((string) ($payload['direction'] ?? $payload['call_direction'] ?? ''));

        return str_contains($direction, 'out') ? 'OUTBOUND' : 'INBOUND';
    }

    private function persistTranscript(VoiceCall $voiceCall, array $message): VoiceCall
    {
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            return $voiceCall;
        }

        $providerId = (string) ($message['id'] ?? '');
        if ($providerId === '') {
            $providerId = hash('sha256', implode('|', [
                (string) $voiceCall->telnyx_conversation_id,
                (string) ($message['speaker'] ?? ''),
                $text,
                (string) ($message['occurred_at'] ?? ''),
            ]));
        }

        $exists = VoiceCallTranscript::query()
            ->where('voice_call_id', $voiceCall->id)
            ->where('provider_message_id', $providerId)
            ->exists();
        if ($exists) {
            $this->persistSessionMessage($voiceCall, $providerId, $message, $text);

            return $voiceCall;
        }

        VoiceCallTranscript::query()->create([
            'voice_call_id' => $voiceCall->id,
            'provider_message_id' => $providerId,
            'speaker' => $message['speaker'] ?? 'customer',
            'transcript_type' => 'final',
            'text' => $text,
            'confidence' => $message['confidence'] ?? null,
            'occurred_at' => $message['occurred_at'] ?? now(),
        ]);

        $this->persistSessionMessage($voiceCall, $providerId, $message, $text);

        return $this->liveStream->recordTranscriptChunk($voiceCall, [
            'text' => $text,
            'speaker' => $message['speaker'] ?? 'customer',
            'confidence' => $message['confidence'] ?? null,
            'sentiment' => $message['sentiment'] ?? null,
        ]);
    }

    private function persistSessionMessage(VoiceCall $voiceCall, string $providerId, array $message, string $text): void
    {
        if (! $voiceCall->ai_chat_session_id) {
            return;
        }

        $sender = ($message['speaker'] ?? null) === 'assistant' ? 'assistant' : 'user';
        $exists = AiMessage::query()
            ->where('chat_session_id', $voiceCall->ai_chat_session_id)
            ->where('metadata->provider_message_id', $providerId)
            ->exists();
        if ($exists) {
            return;
        }

        AiMessage::query()->create([
            'chat_session_id' => $voiceCall->ai_chat_session_id,
            'sender' => $sender,
            'content' => $text,
            'metadata' => [
                'channel' => 'VOICE',
                'provider' => 'telnyx',
                'provider_message_id' => $providerId,
                'voice_call_id' => $voiceCall->id,
                'confidence' => $message['confidence'] ?? null,
                'occurred_at' => $message['occurred_at'] ?? null,
            ],
        ]);
    }

    private function mergeSessionMeta(VoiceCall $voiceCall, array $values): void
    {
        $session = $voiceCall->aiChatSession;
        if (! $session) {
            return;
        }

        $session->forceFill([
            'meta' => array_merge(
                $session->meta ?? [],
                array_filter($values, static fn ($value) => $value !== null),
            ),
        ])->save();
    }

    private function messageText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (! is_array($content)) {
            return '';
        }

        return trim(collect($content)->map(function ($part): string {
            if (is_string($part)) {
                return $part;
            }

            return is_array($part) ? (string) ($part['text'] ?? $part['content'] ?? '') : '';
        })->filter()->implode(' '));
    }
}
