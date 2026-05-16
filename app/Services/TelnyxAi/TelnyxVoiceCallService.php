<?php

namespace App\Services\TelnyxAi;

use App\Models\AiChatSession;
use App\Models\VoiceCall;
use App\Services\Messaging\AiSms\SmsContextResolverService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TelnyxVoiceCallService
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
        private readonly SmsContextResolverService $resolver,
    ) {
    }

    public function dial(array $data, int $createdByUserId): VoiceCall
    {
        $assistantId = (string) ($data['assistant_id'] ?? $this->settings->all()['assistant_id'] ?? '');
        $from = (string) ($data['from'] ?? config('services.telnyx.from_number', ''));
        $to = (string) $data['to'];
        $clientState = Str::uuid()->toString();
        $dynamicVariables = $data['dynamic_variables'] ?? [];

        $voiceCall = VoiceCall::query()->create([
            'direction' => 'OUTBOUND',
            'status' => 'dialing',
            'from_phone' => $from,
            'to_phone' => $to,
            'assistant_id' => $assistantId,
            'related_shoot_id' => $data['related_shoot_id'] ?? null,
            'client_state' => $clientState,
            'created_by_user_id' => $createdByUserId,
            'metadata' => ['dynamic_variables' => $dynamicVariables],
        ]);

        $apiKey = (string) config('services.telnyx.api_key', '');
        if ($apiKey !== '') {
            $base = rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/');
            $response = Http::withToken($apiKey)->post("{$base}/calls", [
                'connection_id' => config('services.telnyx.voice.connection_id'),
                'to' => $to,
                'from' => $from,
                'webhook_url' => $this->settings->all()['webhook_url'] ?? null,
                'client_state' => $clientState,
                'assistant_id' => $assistantId,
                'dynamic_variables' => $dynamicVariables,
            ]);

            $json = $response->json('data') ?? $response->json();
            $voiceCall->forceFill([
                'call_control_id' => $json['call_control_id'] ?? $voiceCall->call_control_id,
                'telnyx_conversation_id' => $json['conversation_id'] ?? $voiceCall->telnyx_conversation_id,
                'last_telnyx_command_status' => [
                    'action' => 'dial',
                    'ok' => $response->successful(),
                    'status' => $response->status(),
                    'at' => now()->toIso8601String(),
                ],
                'metadata' => array_merge($voiceCall->metadata ?? [], ['dial_response' => $json]),
            ])->save();
        }

        return $voiceCall->fresh();
    }

    public function buildDynamicVariables(?VoiceCall $voiceCall, array $resolved = []): array
    {
        $settings = $this->settings->all();
        $variables = [
            'caller_phone_e164' => $voiceCall?->from_phone,
            'support_handoff_number' => $settings['support_handoff_number'] ?? null,
            'is_known_caller' => (bool) ($resolved['identified'] ?? false),
            'recording_disclosure_text' => $settings['disclosure_text'] ?? '',
        ];

        if (($resolved['identified'] ?? false) && !($resolved['ambiguous'] ?? false)) {
            $user = $resolved['user'] ?? null;
            $contact = $resolved['contact'] ?? null;
            $variables['caller_first_name'] = $this->firstName((string) ($user?->name ?? $contact?->name ?? ''));
            $variables['role'] = $resolved['role'] ?? 'contact';
            $variables['verification_methods'] = ['sms_otp', 'last4_phone'];
        }

        if ($voiceCall?->verified_at) {
            $variables['caller_name'] = $voiceCall->callerUser?->name ?? $voiceCall->callerContact?->name;
        }

        return array_filter($variables, static fn ($value) => $value !== null && $value !== '');
    }

    public function startAssistant(VoiceCall $voiceCall, array $dynamicVariables): bool
    {
        return $this->callAction($voiceCall, 'assistant_start', [
            'assistant_id' => $voiceCall->assistant_id,
            'dynamic_variables' => $dynamicVariables,
        ]);
    }

    public function answer(VoiceCall $voiceCall): bool
    {
        return $this->callAction($voiceCall, 'answer');
    }

    public function transfer(VoiceCall $voiceCall, string $to): bool
    {
        return $this->callAction($voiceCall, 'transfer', ['to' => $to]);
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
            'last_telnyx_command_status' => array_filter([
                'action' => $action,
                'ok' => $ok,
                'status' => $status,
                'error' => $error,
                'response' => $response,
                'at' => now()->toIso8601String(),
            ], static fn ($value) => $value !== null),
        ])->save();
    }

    private function callAction(VoiceCall $voiceCall, string $action, array $payload = []): bool
    {
        $apiKey = (string) config('services.telnyx.api_key', '');
        $callControlId = (string) $voiceCall->call_control_id;

        if ($apiKey === '' || $callControlId === '') {
            $this->recordCommandStatus($voiceCall, $action, false, error: 'missing_api_key_or_call_control_id');
            return false;
        }

        $base = rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/');
        try {
            $response = Http::withToken($apiKey)->post("{$base}/calls/{$callControlId}/actions/{$action}", $payload);
            $this->recordCommandStatus($voiceCall, $action, $response->successful(), $response->status(), $response->json() ?: []);

            return $response->successful();
        } catch (Throwable $exception) {
            $this->recordCommandStatus($voiceCall, $action, false, error: $exception->getMessage());

            return false;
        }
    }

    private function firstName(string $name): string
    {
        return trim(explode(' ', trim($name))[0] ?? '');
    }
}
