<?php

namespace App\Services\TelnyxAi;

use App\Models\VoiceCall;
use App\Services\Messaging\AiSms\SmsContextResolverService;
use App\Support\LockedWrite;
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
        private readonly VoiceNumberSettingsResolver $numbers,
    ) {}

    public function dial(array $data, int $createdByUserId): VoiceCall
    {
        $to = $this->resolver->normalize((string) ($data['to'] ?? ''));
        $from = $this->resolver->normalize((string) ($data['from'] ?? config('services.telnyx.from_number', '')));
        $numberPolicy = $this->numbers->forNumber($from);
        $assistantId = (string) ($data['assistant_id'] ?? $numberPolicy['assistant_id'] ?? '');
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
                'voice_routing' => $numberPolicy,
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
        $numberPolicy = $this->numbers->forNumber($from ?? (string) config('services.telnyx.from_number', ''));
        $blockers = [];

        if (strtolower((string) config('services.voice.provider', 'telnyx')) !== 'telnyx') {
            $blockers[] = 'Direct Telnyx is not the selected voice provider.';
        }
        if (! ($settings['enabled'] ?? false)) {
            $blockers[] = 'Telnyx voice is disabled.';
        } elseif (! $numberPolicy['enabled']) {
            $blockers[] = 'AI calling is disabled for the selected phone number.';
        }
        if ($this->apiKey() === '') {
            $blockers[] = 'TELNYX_API_KEY is not configured.';
        }
        if (! filled(config('services.telnyx.voice.connection_id'))) {
            $blockers[] = 'TELNYX_VOICE_CONNECTION_ID is not configured.';
        }
        if (($assistantId ?? (string) ($numberPolicy['assistant_id'] ?? '')) === '') {
            $blockers[] = 'TELNYX_VOICE_ASSISTANT_ID is not configured.';
        }
        if (($from ?? $this->resolver->normalize((string) config('services.telnyx.from_number', ''))) === '') {
            $blockers[] = 'TELNYX_FROM_NUMBER is not configured.';
        }
        if (! filled($settings['webhook_url'] ?? null)) {
            $blockers[] = 'TELNYX_VOICE_WEBHOOK_URL is not configured.';
        }

        $mode = $this->settings->outboundMode($settings);
        if ($mode === 'none') {
            $blockers[] = 'Outbound calling is turned off.';
        } elseif ($mode === 'canary') {
            $allowed = $this->canaryNumbers();
            if ($allowed === []) {
                $blockers[] = 'No canary numbers are allowlisted.';
            } elseif ($to !== null && $to !== '' && ! in_array($this->resolver->normalize($to), $allowed, true)) {
                $blockers[] = 'The destination is not allowlisted for canary outbound.';
            }
        }

        return array_values(array_unique($blockers));
    }

    /** @return list<string> */
    public function canaryNumbers(): array
    {
        $settings = $this->settings->all();

        return array_values(array_unique(array_filter(array_map(
            fn ($number) => $this->resolver->normalize((string) $number),
            (array) ($settings['canary_numbers'] ?? config('services.voice.canary_numbers', [])),
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
            if (! $this->numbers->aiEnabledForCall($voiceCall)) {
                $this->recordCommandStatus($voiceCall, 'ai_assistant_start', false, error: 'voice_ai_disabled');

                return false;
            }
            if (! filled($voiceCall->assistant_id)) {
                $this->recordCommandStatus($voiceCall, 'ai_assistant_start', false, error: 'missing_assistant_id');

                return false;
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
                    'handled_by' => 'ai',
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

    public function setRecordingConsent(VoiceCall $voiceCall, bool $consented, ?string $operationId = null): array
    {
        return Cache::lock('voice-recording-control:'.$voiceCall->id, 75)->block(5, function () use ($voiceCall, $consented, $operationId): array {
            $voiceCall->refresh();
            $this->updateRecordingState($voiceCall, function (array $metadata) use ($consented, $operationId): array {
                if (($metadata['recording_consent']['consented'] ?? null) !== $consented) {
                    $metadata['recording_consent'] = ['consented' => $consented, 'recorded_at' => now()->toIso8601String()];
                }
                if ($operationId) {
                    $metadata['recording_consent']['operation_id'] = $operationId;
                }

                return $metadata;
            }, ['recording_consent_given' => $consented, 'recording_provider' => $consented ? 'telnyx' : null,
                'recording_url' => $consented ? $voiceCall->recording_url : null]);
            if (! $consented) {
                return $this->performRecordingStop($voiceCall);
            }
            $metadata = $voiceCall->metadata ?? [];
            if (! empty($metadata['recording_stop_pending'])) {
                throw new RuntimeException('The previous stop is not confirmed. Retry stopping before starting a new recording.');
            }
            if (! empty($metadata['recording_started_at'])) {
                return ['recording' => true, 'consented' => true, 'reason' => null];
            }
            if (! $this->recordingEnabled()) {
                return ['recording' => false, 'consented' => true, 'reason' => 'recording_disabled'];
            }
            abort_if($voiceCall->ended_at, 409, 'This call has ended.');
            $generation = (int) ($metadata['recording_generation'] ?? 0);
            $commandId = $this->commandIdFor($voiceCall, 'record_start:'.$generation);
            $this->updateRecordingState($voiceCall, fn (array $state): array => array_merge($state, ['recording_start_pending' => true]));
            $result = $this->callActionResult($voiceCall, 'record_start', ['format' => 'mp3', 'channels' => 'dual'], $commandId);
            if ($result['ok']) {
                $this->updateRecordingState($voiceCall, fn (array $state): array => array_merge($state, [
                    'recording_started_at' => now()->toIso8601String(), 'recording_start_pending' => false,
                ]));
            }

            return ['recording' => $result['ok'], 'consented' => true, 'reason' => $result['ok'] ? null : 'recording_start_failed'];
        });
    }

    /** Stop both media captures independently; a failed stop must remain retryable. */
    public function stopRecording(VoiceCall $voiceCall, ?string $operationId = null): array
    {
        return Cache::lock('voice-recording-control:'.$voiceCall->id, 75)->block(5, fn (): array => $this->performRecordingStop($voiceCall));
    }

    private function performRecordingStop(VoiceCall $voiceCall): array
    {
        $voiceCall->refresh();
        $metadata = $voiceCall->metadata ?? [];
        $recording = ! empty($metadata['recording_started_at']) || ! empty($metadata['recording_start_pending']);
        $transcribing = ! empty($metadata['browser_transcription_enabled']) || ! empty($metadata['browser_transcription_pending']);
        $pending = ! empty($metadata['recording_stop_pending']);
        $generation = (int) ($metadata['recording_generation'] ?? 0);
        if (! $recording && ! $transcribing && ! $pending) {
            return ['recording' => false, 'consented' => $voiceCall->recording_consent_given, 'reason' => null];
        }
        $this->updateRecordingState($voiceCall, fn (array $state): array => array_merge($state, ['recording_stop_pending' => true]));
        $failures = [];
        foreach (['record_stop' => $recording, 'transcription_stop' => $transcribing] as $action => $needed) {
            if (! $needed) {
                continue;
            }
            $result = $this->callActionResult($voiceCall, $action, [], $this->commandIdFor($voiceCall, $action.':'.$generation));
            if (! $result['ok']) {
                $failures[] = $action;

                continue;
            }
            $this->updateRecordingState($voiceCall, function (array $state) use ($action): array {
                if ($action === 'record_stop') {
                    unset($state['recording_started_at']);
                    $state['recording_start_pending'] = false;
                    $state['recording_stopped_at'] = now()->toIso8601String();
                } else {
                    $state['browser_transcription_enabled'] = false;
                    $state['browser_transcription_pending'] = false;
                }

                return $state;
            });
        }
        if ($failures !== []) {
            throw new RuntimeException('The provider did not confirm '.implode(' and ', array_map(fn ($action) => $action === 'record_stop' ? 'recording stopped' : 'transcription stopped', $failures)).'. Retry stopping the capture.');
        }
        $this->updateRecordingState($voiceCall, fn (array $state): array => array_merge($state, [
            'recording_stop_pending' => false, 'recording_generation' => $generation + 1,
        ]));

        return ['recording' => false, 'consented' => $voiceCall->recording_consent_given, 'reason' => null];
    }

    private function updateRecordingState(VoiceCall $voiceCall, callable $change, array $fields = []): void
    {
        LockedWrite::run(function () use ($voiceCall, $change, $fields): void {
            $voiceCall->refresh();
            $voiceCall->forceFill(array_merge($fields, ['metadata' => $change($voiceCall->metadata ?? [])]))->save();
        }, 'voice-recording-state');
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
