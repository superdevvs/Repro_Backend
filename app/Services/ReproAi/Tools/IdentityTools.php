<?php

namespace App\Services\ReproAi\Tools;

use App\Models\VoiceCall;
use App\Models\VoiceCallVerification;
use App\Services\Messaging\AiSms\SmsIdentityVerificationService;

class IdentityTools
{
    public function verifyCaller(array $params, array $context): array
    {
        $phone = (string) ($context['phone_e164'] ?? $params['phone_e164'] ?? '');
        if ($phone === '') {
            return ['ok' => false, 'error' => 'missing_phone'];
        }

        $service = app(SmsIdentityVerificationService::class);

        if (!empty($params['request_otp'])) {
            $code = $service->issueCode($phone);

            return [
                'ok' => true,
                'result' => [
                    'otp_sent' => true,
                    'channel' => $params['method'] ?? 'sms_otp',
                    'debug_code' => app()->environment(['local', 'testing']) ? $code : null,
                ],
            ];
        }

        $code = (string) ($params['otp_code'] ?? $params['value'] ?? '');
        if ($code === '') {
            return ['ok' => false, 'error' => 'missing_verification_value'];
        }

        $success = $service->verifyCode($phone, $code);

        if (strtoupper((string) ($context['channel'] ?? '')) === 'VOICE') {
            VoiceCallVerification::query()->create([
                'phone_e164' => $phone,
                'voice_call_id' => $context['voice_call_id'] ?? null,
                'user_id' => $context['user_id'] ?? null,
                'contact_id' => $context['contact_id'] ?? null,
                'method' => (string) ($params['method'] ?? 'sms_otp'),
                'success' => $success,
                'attempts' => 1,
                'metadata' => ['tool_context' => 'verify_caller'],
            ]);

            if ($success && !empty($context['voice_call_id'])) {
                VoiceCall::query()->whereKey($context['voice_call_id'])->update(['verified_at' => now()]);
            }
        }

        if (!$success) {
            return ['ok' => false, 'error' => 'verification_failed'];
        }

        return [
            'ok' => true,
            'result' => [
                'verified' => true,
                'scope' => 'session',
            ],
        ];
    }
}
