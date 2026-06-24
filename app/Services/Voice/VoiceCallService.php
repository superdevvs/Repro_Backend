<?php

namespace App\Services\Voice;

use App\Models\VoiceCall;
use App\Services\Messaging\AiSms\SmsContextResolverService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class VoiceCallService
{
    public function __construct(
        private readonly VapiClient $vapi,
        private readonly SmsContextResolverService $resolver,
    ) {
    }

    public function startOutbound(array $data, int $createdByUserId): VoiceCall
    {
        $to = (string) $data['to'];
        $from = (string) ($data['from'] ?? config('services.telnyx.from_number', ''));
        $assistantId = (string) ($data['assistant_id'] ?? config('services.vapi.assistant_id', ''));
        $phoneNumberId = (string) ($data['vapi_phone_number_id'] ?? config('services.vapi.phone_number_id', ''));
        $dynamicVariables = $data['dynamic_variables'] ?? [];

        $call = VoiceCall::query()->create([
            'provider' => 'vapi_telnyx',
            'direction' => 'OUTBOUND',
            'status' => 'queued',
            'handled_by' => 'ai',
            'from_phone' => $from,
            'to_phone' => $to,
            'assistant_id' => $assistantId ?: null,
            'vapi_phone_number_id' => $phoneNumberId ?: null,
            'related_shoot_id' => $data['related_shoot_id'] ?? null,
            'caller_contact_id' => $data['contact_id'] ?? null,
            'created_by_user_id' => $createdByUserId,
            'metadata' => [
                'dynamic_variables' => $dynamicVariables,
                'assistant_mode' => $data['assistant_mode'] ?? 'robbie_ai',
                'source' => $data['source'] ?? ($dynamicVariables['source'] ?? 'voice_outbound'),
            ],
        ]);

        $payload = array_filter([
            'assistantId' => $assistantId ?: null,
            'phoneNumberId' => $phoneNumberId ?: null,
            'customer' => ['number' => $to],
            'metadata' => [
                'voice_call_id' => $call->id,
                'source' => $data['source'] ?? ($dynamicVariables['source'] ?? 'voice_outbound'),
            ],
            'assistantOverrides' => $dynamicVariables ? ['variableValues' => $dynamicVariables] : null,
        ], static fn ($value) => $value !== null && $value !== '');

        try {
            $response = $this->vapi->createOutboundCall($payload);
        } catch (Throwable $exception) {
            $call->forceFill([
                'status' => 'failed',
                'disposition' => 'dial_failed',
                'external_provider_status' => 'failed',
                'carrier_failure_reason' => $exception->getMessage(),
                'provider_event_last_seen_at' => now(),
                'metadata' => $this->mergeMetadata($call, [
                    'vapi_request' => $payload,
                    'vapi_error' => $exception->getMessage(),
                ]),
            ])->save();

            throw $exception;
        }

        $call->forceFill([
            'status' => 'initiating',
            'external_provider_status' => $response['status'] ?? 'created',
            'vapi_call_id' => $response['id'] ?? $response['callId'] ?? $call->vapi_call_id,
            'vapi_phone_number_id' => $response['phoneNumberId'] ?? $phoneNumberId ?: $call->vapi_phone_number_id,
            'provider_event_last_seen_at' => now(),
            'metadata' => $this->mergeMetadata($call, [
                'vapi_request' => $payload,
                'vapi_create_response' => $response,
            ]),
        ])->save();

        return $call->fresh();
    }

    public function upsertFromVapiCall(array $vapiCall, array $attributes = []): ?VoiceCall
    {
        $vapiCallId = $this->vapiCallId($vapiCall);
        if ($vapiCallId === null) {
            return null;
        }

        $direction = $this->directionFromVapiCall($vapiCall);
        [$from, $to] = $this->phonePairFromVapiCall($vapiCall, $direction);
        $resolved = $this->resolveCaller($direction === 'OUTBOUND' ? $to : $from);

        $call = VoiceCall::query()->firstOrNew(['vapi_call_id' => $vapiCallId]);
        if (!$call->exists) {
            $call->fill([
                'provider' => 'vapi_telnyx',
                'direction' => $direction,
                'status' => 'initiating',
                'handled_by' => 'ai',
                'from_phone' => $from,
                'to_phone' => $to,
                'assistant_id' => $vapiCall['assistantId'] ?? Arr::get($vapiCall, 'assistant.id') ?? config('services.vapi.assistant_id'),
                'vapi_phone_number_id' => $vapiCall['phoneNumberId'] ?? Arr::get($vapiCall, 'phoneNumber.id') ?? null,
                'caller_user_id' => $resolved['user']?->id ?? null,
                'caller_contact_id' => $resolved['contact']?->id ?? null,
                'started_at' => now(),
                'metadata' => ['vapi_call' => $vapiCall],
            ]);
        }

        $call->fill(array_filter([
            'provider' => 'vapi_telnyx',
            'external_provider_status' => $vapiCall['status'] ?? null,
            'provider_event_last_seen_at' => now(),
            'vapi_phone_number_id' => $vapiCall['phoneNumberId'] ?? Arr::get($vapiCall, 'phoneNumber.id') ?? $call->vapi_phone_number_id,
            'from_phone' => $from ?: $call->from_phone,
            'to_phone' => $to ?: $call->to_phone,
            'assistant_id' => $vapiCall['assistantId'] ?? Arr::get($vapiCall, 'assistant.id') ?? $call->assistant_id,
            'metadata' => $this->mergeMetadata($call, ['vapi_call' => $vapiCall]),
        ], static fn ($value) => $value !== null));

        if ($attributes) {
            $call->fill($attributes);
        }

        $call->save();

        return $call->fresh();
    }

    public function mergeMetadata(VoiceCall $call, array $values): array
    {
        return array_merge($call->metadata ?? [], $values);
    }

    private function vapiCallId(array $vapiCall): ?string
    {
        $id = $vapiCall['id'] ?? $vapiCall['callId'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    private function directionFromVapiCall(array $vapiCall): string
    {
        $type = strtolower((string) ($vapiCall['type'] ?? $vapiCall['direction'] ?? ''));
        if (str_contains($type, 'outbound')) {
            return 'OUTBOUND';
        }
        if (str_contains($type, 'inbound')) {
            return 'INBOUND';
        }

        return Arr::get($vapiCall, 'metadata.source') ? 'OUTBOUND' : 'INBOUND';
    }

    private function phonePairFromVapiCall(array $vapiCall, string $direction): array
    {
        $customerNumber = (string) (
            Arr::get($vapiCall, 'customer.number')
            ?? Arr::get($vapiCall, 'customer.phoneNumber')
            ?? $vapiCall['customerNumber']
            ?? ''
        );
        $providerNumber = (string) (
            Arr::get($vapiCall, 'phoneNumber.number')
            ?? $vapiCall['phoneNumber']
            ?? config('services.telnyx.from_number', '')
        );

        return $direction === 'OUTBOUND'
            ? [$providerNumber, $customerNumber]
            : [$customerNumber, $providerNumber];
    }

    private function resolveCaller(string $phone): array
    {
        if ($phone === '') {
            return ['identified' => false, 'user' => null, 'contact' => null];
        }

        try {
            return $this->resolver->resolveByE164($phone);
        } catch (Throwable) {
            return ['identified' => false, 'user' => null, 'contact' => null, 'phone_e164' => $phone];
        }
    }
}
