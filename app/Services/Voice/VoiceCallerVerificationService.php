<?php

namespace App\Services\Voice;

use App\Models\VoiceCall;
use App\Models\VoiceCallVerification;
use App\Services\Messaging\MessagingService;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Support\LockedWrite;
use App\Support\VoiceCache;
use App\Support\VoiceLocks;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Hash;
use Throwable;

class VoiceCallerVerificationService
{
    private const TERMINAL = ['completed', 'failed', 'missed', 'cancelled', 'canceled', 'busy', 'no-answer', 'no_answer', 'ended'];

    public function verify(VoiceCall $call, array $params): array
    {
        $call = $this->activeCall($call);
        if (! $call) {
            return $this->failure('trusted_call_not_found');
        }
        $phone = $this->phone($call);
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            return $this->failure('missing_phone');
        }
        // This shared phone lease bounds sends/guesses across calls and outlives
        // the SMS provider's three 15-second attempts. No DB transaction spans HTTP.
        try {
            return VoiceLocks::lock('voice:caller-verification:'.hash('sha256', $phone), 90)->block(3,
                fn () => $this->execute($call, $phone, $params));
        } catch (LockTimeoutException) {
            return $this->failure('verification_busy');
        }
    }

    private function execute(VoiceCall $original, string $phone, array $params): array
    {
        $call = $this->activeCall($original);
        if (! $call || $this->phone($call) !== $phone) {
            return $this->failure('trusted_call_not_found');
        }
        $binding = $this->binding($call, $phone);
        if ($binding === null) {
            return $this->failure('verification_account_unavailable');
        }
        if (($params['method'] ?? 'sms_otp') !== 'sms_otp') {
            return $this->failure('unsupported_verification_method');
        }
        $cache = VoiceCache::store();
        $codeKey = 'voice:caller-otp:'.$call->id;
        $limitKey = 'voice:caller-otp-limits:'.hash('sha256', $phone);
        $limits = $cache->get($limitKey, ['sends' => 0, 'attempts' => 0, 'next_send_at' => 0, 'reset_at' => now()->timestamp + 900]);
        if (($limits['attempts'] ?? 0) >= 5) {
            return $this->failure('verification_rate_limited');
        }

        if (! empty($params['request_otp'])) {
            if ($limits['sends'] >= 3 || $limits['next_send_at'] > now()->timestamp) {
                return $this->failure('verification_rate_limited');
            }
            $limits['sends']++;
            $limits['next_send_at'] = now()->timestamp + 60;
            $cache->put($limitKey, $limits, max(1, $limits['reset_at'] - now()->timestamp));
            $cache->forget($codeKey);
            $code = (string) random_int(100000, 999999);
            try {
                $message = app(MessagingService::class)->sendSms([
                    'to' => $phone,
                    'body_text' => 'REPro caller verification code: '.$code.'. Expires in 10 minutes. Share it only with Robbie on your current call.',
                    'send_source' => 'VOICE_CALLER_VERIFICATION',
                    'hidden_from_inbox' => true,
                ]);
                if ($message->status !== 'SENT' || ! filled($message->provider_message_id)) {
                    return $this->failure('verification_delivery_failed');
                }
            } catch (Throwable) {
                // MessagingService records diagnostics. Never expose codes or raw
                // provider errors in assistant responses or invocation logs.
                return $this->failure('verification_delivery_failed');
            }
            $current = $this->activeCall($call);
            if (! $current || $this->binding($current, $this->phone($current)) !== $binding) {
                return $this->failure('trusted_call_not_found');
            }
            $cache->put($codeKey, ['hash' => Hash::make($code), 'binding' => $binding], 600);

            return ['ok' => true, 'result' => ['otp_sent' => true, 'channel' => 'sms_otp', 'delivery_status' => 'accepted',
                'message' => 'The SMS provider accepted the code for delivery. Ask for it when it arrives; delivery is not guaranteed.']];
        }

        $code = trim((string) ($params['otp_code'] ?? $params['value'] ?? ''));
        if ($code === '') {
            return $this->failure('missing_verification_value');
        }
        $pending = $cache->get($codeKey);
        $limits['attempts']++;
        $cache->put($limitKey, $limits, max(1, $limits['reset_at'] - now()->timestamp));
        $success = is_array($pending) && ($pending['binding'] ?? null) === $binding
            && preg_match('/^\d{6}$/', $code) && Hash::check($code, (string) ($pending['hash'] ?? ''));
        if ($success) {
            // Consume once; update only this still-active, unchanged identity.
            $cache->forget($codeKey);
            $success = LockedWrite::run(fn () => VoiceCall::whereKey($call->id)
                ->whereNull('ended_at')->whereNotIn('status', self::TERMINAL)
                ->where('from_phone', $call->from_phone)->where('to_phone', $call->to_phone)
                ->where('caller_user_id', $call->caller_user_id)->where('caller_contact_id', $call->caller_contact_id)
                ->update(['verified_at' => now()])) === 1;
        }
        LockedWrite::run(fn () => VoiceCallVerification::create([
            'phone_e164' => $phone, 'voice_call_id' => $call->id,
            'user_id' => $call->caller_user_id, 'contact_id' => $call->caller_contact_id,
            'method' => 'sms_otp', 'success' => (bool) $success, 'attempts' => 1,
            'metadata' => ['tool_context' => 'verify_caller'],
        ]));

        return $success ? ['ok' => true, 'result' => ['verified' => true, 'scope' => 'session']]
            : $this->failure('verification_failed');
    }

    private function activeCall(VoiceCall $call): ?VoiceCall
    {
        $current = VoiceCall::with(['callerUser', 'callerContact.user'])->find($call->id);

        return $current && $current->ended_at === null && ! in_array(strtolower((string) $current->status), self::TERMINAL, true)
            ? $current : null;
    }

    private function phone(VoiceCall $call): string
    {
        return app(VoiceNumberSettingsResolver::class)->normalize((string) (strtoupper((string) $call->direction) === 'OUTBOUND' ? $call->to_phone : $call->from_phone));
    }

    private function binding(VoiceCall $call, string $phone): ?string
    {
        $user = $call->callerUser ?: $call->callerContact?->user;
        if (($call->caller_user_id && ! $call->callerUser) || ($user && (! $user->isAccountEligibleForAuthentication()
            || ! in_array($phone, array_map(fn ($value) => app(VoiceNumberSettingsResolver::class)->normalize((string) $value), [$user->phone, $user->phonenumber]), true)))) {
            return null;
        }

        // No account lookup, reassignment, role grant, or login-state change occurs.
        return hash('sha256', json_encode([$call->id, $phone, $call->caller_user_id, $call->caller_contact_id, $user?->id, $user?->role]));
    }

    private function failure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'result' => ['otp_sent' => false, 'verified' => false,
            'message' => 'Verification could not be completed. Do not claim a code was sent or access private records. Offer general help or staff assistance.']];
    }
}
