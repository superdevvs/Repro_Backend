<?php

namespace App\Services\TelnyxAi;

use App\Models\VoiceCall;
use App\Services\Messaging\AiSms\SmsContextResolverService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TelnyxVoiceCallService
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
        private readonly SmsContextResolverService $resolver,
    ) {}

    public function dial(array $data, int $createdByUserId): VoiceCall
    {
        $to = $this->resolver->normalize((string) ($data['to'] ?? ''));
        $from = $this->resolver->normalize((string) ($data['from'] ?? config('services.telnyx.from_number', '')));
        $assistantId = (string) ($data['assistant_id'] ?? $this->settings->all()['assistant_id'] ?? '');
        $blockers = $this->outboundBlockers($to, $from, $assistantId);

        if ($blockers !== []) {
            throw new RuntimeException(implode(' ', $blockers));
        }

        $resolved = $this->resolveCaller($to);
        $clientState = base64_encode(Str::uuid()->toString());
        $dialCommandId = Str::uuid()->toString();
        $dynamicVariables = is_array($data['dynamic_variables'] ?? null) ? $data['dynamic_variables'] : [];

        $voiceCall = VoiceCall::query()->create([
            'provider' => 'telnyx',
            'direction' => 'OUTBOUND',
            'status' => 'dialing',
            'external_provider_status' => 'dialing',
            'handled_by' => 'ai',
            'from_phone' => $from,
            'to_phone' => $to,
            'assistant_id' => $assistantId,
            'related_shoot_id' => $data['related_shoot_id'] ?? null,
            'caller_user_id' => $resolved['user']?->id,
            'caller_contact_id' => $resolved['contact']?->id,
            'client_state' => $clientState,
            'created_by_user_id' => $createdByUserId,
            'metadata' => [
                'dynamic_variables' => $dynamicVariables,
                'assistant_mode' => $data['assistant_mode'] ?? 'robbie_ai',
                'source' => $data['source'] ?? ($dynamicVariables['source'] ?? 'voice_outbound'),
                'telnyx_command_ids' => ['dial' => $dialCommandId],
            ],
        ]);

        $base = $this->apiBase();
        try {
            $response = Http::withToken($this->apiKey())
                ->connectTimeout(5)
                ->timeout(15)
                ->post("{$base}/calls", array_filter([
                    'connection_id' => config('services.telnyx.voice.connection_id'),
                    'to' => $to,
                    'from' => $from,
                    'webhook_url' => $this->settings->all()['webhook_url'] ?? null,
                    'client_state' => $clientState,
                    'command_id' => $dialCommandId,
                ], static fn ($value) => $value !== null && $value !== ''));

            $json = $response->json('data') ?? $response->json() ?? [];
            if (! $response->successful()) {
                throw new RuntimeException(
                    'Telnyx dial failed ('.$response->status().'): '
                    .($json['errors'][0]['detail'] ?? $json['error'] ?? $response->body() ?: 'unknown error')
                );
            }

            $voiceCall->forceFill([
                'call_control_id' => $json['call_control_id'] ?? null,
                'status' => 'dialing',
                'external_provider_status' => $json['result'] ?? 'dialing',
                'provider_event_last_seen_at' => now(),
                'last_telnyx_command_status' => $this->commandStatus('dial', true, $response->status(), $json),
                'metadata' => array_merge($voiceCall->metadata ?? [], ['dial_response' => $json]),
            ])->save();
        } catch (Throwable $exception) {
            $voiceCall->forceFill([
                'status' => 'failed',
                'disposition' => 'dial_failed',
                'external_provider_status' => 'failed',
                'carrier_failure_reason' => $exception->getMessage(),
                'provider_event_last_seen_at' => now(),
                'last_telnyx_command_status' => $this->commandStatus('dial', false, error: $exception->getMessage()),
            ])->save();

            throw $exception;
        }

        return $voiceCall->fresh();
    }

    /** @return list<string> */
    public function outboundBlockers(?string $to = null, ?string $from = null, ?string $assistantId = null): array
    {
        $settings = $this->settings->all();
        $blockers = [];

        if (strtolower((string) config('services.voice.provider', 'telnyx')) !== 'telnyx') {
            $blockers[] = 'Direct Telnyx is not the selected voice provider.';
        }
        if (! ($settings['enabled'] ?? false)) {
            $blockers[] = 'Telnyx voice is disabled.';
        }
        if ($this->apiKey() === '') {
            $blockers[] = 'TELNYX_API_KEY is not configured.';
        }
        if (! filled(config('services.telnyx.voice.connection_id'))) {
            $blockers[] = 'TELNYX_VOICE_CONNECTION_ID is not configured.';
        }
        if (($assistantId ?? (string) ($settings['assistant_id'] ?? '')) === '') {
            $blockers[] = 'TELNYX_VOICE_ASSISTANT_ID is not configured.';
        }
        if (($from ?? $this->resolver->normalize((string) config('services.telnyx.from_number', ''))) === '') {
            $blockers[] = 'TELNYX_FROM_NUMBER is not configured.';
        }
        if (! filled($settings['webhook_url'] ?? null)) {
            $blockers[] = 'TELNYX_VOICE_WEBHOOK_URL is not configured.';
        }

        if ((bool) config('services.voice.canary_mode', true)) {
            $allowed = $this->canaryNumbers();
            if ($allowed === []) {
                $blockers[] = 'VOICE_CANARY_NUMBERS is empty while canary mode is enabled.';
            } elseif ($to !== null && $to !== '' && ! in_array($this->resolver->normalize($to), $allowed, true)) {
                $blockers[] = 'The destination is not allowlisted in VOICE_CANARY_NUMBERS.';
            }
        }

        return array_values(array_unique($blockers));
    }

    /** @return list<string> */
    public function canaryNumbers(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($number) => $this->resolver->normalize((string) $number),
            (array) config('services.voice.canary_numbers', []),
        ))));
    }

    public function buildDynamicVariables(?VoiceCall $voiceCall, array $resolved = []): array
    {
        $settings = $this->settings->all();
        $callerPhone = strtoupper((string) $voiceCall?->direction) === 'OUTBOUND'
            ? $voiceCall?->to_phone
            : $voiceCall?->from_phone;
        $variables = [
            'caller_phone_e164' => $callerPhone,
            'support_handoff_number' => $settings['support_handoff_number'] ?? null,
            'is_known_caller' => (bool) ($resolved['identified'] ?? false),
            'recording_disclosure_text' => $settings['disclosure_text'] ?? '',
            'recording_consent_given' => (bool) $voiceCall?->recording_consent_given,
            'voice_call_id' => $voiceCall?->id,
        ];

        if (($resolved['identified'] ?? false) && ! ($resolved['ambiguous'] ?? false)) {
            $user = $resolved['user'] ?? null;
            $contact = $resolved['contact'] ?? null;
            $variables['caller_first_name'] = $this->firstName((string) ($user?->name ?? $contact?->name ?? ''));
            $variables['role'] = $resolved['role'] ?? 'contact';
            $variables['verification_methods'] = ['sms_otp'];
        }

        if ($voiceCall?->verified_at) {
            $variables['caller_name'] = $voiceCall->callerUser?->name ?? $voiceCall->callerContact?->name;
        }

        return array_filter($variables, static fn ($value) => $value !== null && $value !== '');
    }

    public function startAssistant(VoiceCall $voiceCall, array $dynamicVariables): bool
    {
        return Cache::lock("telnyx:assistant-start:{$voiceCall->id}", 20)->block(5, function () use ($voiceCall, $dynamicVariables): bool {
            $voiceCall->refresh();
            $metadata = $voiceCall->metadata ?? [];
            if (! empty($metadata['assistant_started_at'])) {
                return true;
            }

            $commandId = $this->commandIdFor($voiceCall, 'ai_assistant_start');
            $result = $this->callActionResult($voiceCall, 'ai_assistant_start', [
                'assistant' => [
                    'id' => $voiceCall->assistant_id,
                    'dynamic_variables' => $dynamicVariables,
                ],
                'send_message_history_updates' => true,
                'client_state' => $voiceCall->client_state,
            ], $commandId);

            if ($result['ok']) {
                $metadata = $voiceCall->fresh()->metadata ?? [];
                $voiceCall->forceFill([
                    'telnyx_conversation_id' => $result['data']['conversation_id'] ?? $voiceCall->telnyx_conversation_id,
                    'ai_current_state' => 'active',
                    'metadata' => array_merge($metadata, [
                        'assistant_started_at' => now()->toIso8601String(),
                        'assistant_dynamic_variables' => $dynamicVariables,
                    ]),
                ])->save();
            }

            return $result['ok'];
        });
    }

    public function answer(VoiceCall $voiceCall): bool
    {
        return $this->callAction($voiceCall, 'answer');
    }

    public function hangup(VoiceCall $voiceCall): bool
    {
        $ok = $this->callAction($voiceCall, 'hangup');
        $voiceCall->forceFill([
            'status' => $ok ? 'completed' : $voiceCall->status,
            'disposition' => $voiceCall->disposition ?: ($ok ? 'hung_up_by_agent' : null),
            'ended_at' => $ok ? now() : $voiceCall->ended_at,
        ])->save();

        return $ok;
    }

    public function transfer(VoiceCall $voiceCall, string $to): bool
    {
        return $this->callAction($voiceCall, 'transfer', ['to' => $this->resolver->normalize($to)]);
    }

    public function setRecordingConsent(VoiceCall $voiceCall, bool $consented): array
    {
        $metadata = $voiceCall->metadata ?? [];
        $metadata['recording_consent'] = [
            'consented' => $consented,
            'recorded_at' => now()->toIso8601String(),
        ];
        $wasRecording = ! empty($metadata['recording_started_at']);

        $voiceCall->forceFill([
            'recording_consent_given' => $consented,
            'recording_provider' => $consented ? 'telnyx' : null,
            'recording_url' => $consented ? $voiceCall->recording_url : null,
            'metadata' => $metadata,
        ])->save();

        if (! $consented) {
            if ($wasRecording) {
                $stopped = $this->callAction($voiceCall, 'record_stop');
                if ($stopped) {
                    $voiceCall->refresh();
                    $metadata = $voiceCall->metadata ?? [];
                    unset($metadata['recording_started_at']);
                    $metadata['recording_stopped_at'] = now()->toIso8601String();
                    $voiceCall->forceFill(['metadata' => $metadata])->save();
                }
            }

            return ['recording' => false, 'consented' => false];
        }

        if ($wasRecording) {
            return ['recording' => true, 'consented' => true, 'reason' => null];
        }

        if (! $this->recordingEnabled()) {
            return ['recording' => false, 'consented' => true, 'reason' => 'recording_disabled'];
        }

        $ok = $this->callAction($voiceCall, 'record_start', [
            'format' => 'mp3',
            'channels' => 'dual',
        ]);

        if ($ok) {
            $voiceCall->refresh();
            $voiceCall->forceFill([
                'recording_provider' => 'telnyx',
                'metadata' => array_merge($voiceCall->metadata ?? [], [
                    'recording_started_at' => now()->toIso8601String(),
                ]),
            ])->save();
        }

        return ['recording' => $ok, 'consented' => true, 'reason' => $ok ? null : 'recording_start_failed'];
    }

    public function gatherUsingSpeak(VoiceCall $voiceCall, ?string $prompt = null): bool
    {
        $settings = $this->settings->all();
        $text = $prompt ?: (string) ($settings['gather_prompt'] ?? 'Tell me what you need, or press 1 for booking, 2 for order status, 3 for billing, or 0 for a person.');

        return $this->callAction($voiceCall, 'gather_using_speak', [
            'payload' => $text,
            'language' => 'en-US',
            'voice' => 'female',
            'valid_digits' => '1230',
            'maximum_digits' => 1,
            'timeout_millis' => 3500,
            'inter_digit_timeout_millis' => 1000,
        ]);
    }

    public function resolveCaller(string $phone): array
    {
        return $this->resolver->resolveByE164($phone);
    }

    public function recordingEnabled(): bool
    {
        return (bool) ($this->settings->all()['recording_enabled'] ?? false);
    }

    public function recordCommandStatus(VoiceCall $voiceCall, string $action, bool $ok, ?int $status = null, ?array $response = null, ?string $error = null): void
    {
        $voiceCall->forceFill([
            'last_telnyx_command_status' => $this->commandStatus($action, $ok, $status, $response, $error),
        ])->save();
    }

    private function callAction(VoiceCall $voiceCall, string $action, array $payload = []): bool
    {
        return $this->callActionResult($voiceCall, $action, $payload)['ok'];
    }

    /** @return array{ok:bool,status:?int,data:array} */
    private function callActionResult(VoiceCall $voiceCall, string $action, array $payload = [], ?string $commandId = null): array
    {
        $callControlId = (string) $voiceCall->call_control_id;
        if ($this->apiKey() === '' || $callControlId === '') {
            $this->recordCommandStatus($voiceCall, $action, false, error: 'missing_api_key_or_call_control_id');

            return ['ok' => false, 'status' => null, 'data' => []];
        }

        $commandId ??= $this->commandIdFor($voiceCall, $action);
        $payload['command_id'] = $commandId;

        try {
            $response = Http::withToken($this->apiKey())
                ->connectTimeout(5)
                ->timeout(15)
                ->post($this->apiBase()."/calls/{$callControlId}/actions/{$action}", $payload);
            $data = $response->json('data') ?? $response->json() ?? [];
            $this->recordCommandStatus($voiceCall, $action, $response->successful(), $response->status(), $data);

            return ['ok' => $response->successful(), 'status' => $response->status(), 'data' => is_array($data) ? $data : []];
        } catch (Throwable $exception) {
            $this->recordCommandStatus($voiceCall, $action, false, error: $exception->getMessage());

            return ['ok' => false, 'status' => null, 'data' => []];
        }
    }

    private function commandIdFor(VoiceCall $voiceCall, string $action): string
    {
        $voiceCall->refresh();
        $metadata = $voiceCall->metadata ?? [];
        $ids = is_array($metadata['telnyx_command_ids'] ?? null) ? $metadata['telnyx_command_ids'] : [];
        if (! empty($ids[$action])) {
            return (string) $ids[$action];
        }

        $ids[$action] = Str::uuid()->toString();
        $voiceCall->forceFill(['metadata' => array_merge($metadata, ['telnyx_command_ids' => $ids])])->save();

        return $ids[$action];
    }

    private function commandStatus(string $action, bool $ok, ?int $status = null, ?array $response = null, ?string $error = null): array
    {
        return array_filter([
            'action' => $action,
            'ok' => $ok,
            'status' => $status,
            'error' => $error,
            'response' => $response,
            'at' => now()->toIso8601String(),
        ], static fn ($value) => $value !== null);
    }

    private function apiKey(): string
    {
        return (string) config('services.telnyx.api_key', '');
    }

    private function apiBase(): string
    {
        return rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/');
    }

    private function firstName(string $name): string
    {
        return trim(explode(' ', trim($name))[0] ?? '');
    }
}
