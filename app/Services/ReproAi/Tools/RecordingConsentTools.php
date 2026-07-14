<?php

namespace App\Services\ReproAi\Tools;

use App\Models\VoiceCall;
use App\Services\TelnyxAi\TelnyxVoiceCallService;

class RecordingConsentTools
{
    public function __construct(private readonly TelnyxVoiceCallService $calls) {}

    public function setConsent(array $params, array $context = []): array
    {
        if (strtoupper((string) ($context['channel'] ?? '')) !== 'VOICE') {
            return ['ok' => false, 'error' => 'tool_blocked'];
        }

        $voiceCall = VoiceCall::query()->find($context['voice_call_id'] ?? null);
        if (! $voiceCall || $voiceCall->call_control_id !== ($context['call_control_id'] ?? null)) {
            return ['ok' => false, 'error' => 'trusted_call_not_found'];
        }

        $result = $this->calls->setRecordingConsent($voiceCall, (bool) ($params['consented'] ?? false));

        return [
            'ok' => true,
            'result' => $result,
            'message' => $result['recording']
                ? 'Recording started after the caller consented.'
                : ((bool) ($params['consented'] ?? false)
                    ? 'Consent was recorded, but recording is unavailable.'
                    : 'The call will continue without recording.'),
        ];
    }
}
