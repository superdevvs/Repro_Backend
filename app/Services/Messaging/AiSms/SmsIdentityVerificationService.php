<?php

namespace App\Services\Messaging\AiSms;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SmsIdentityVerificationService
{
    private const CACHE_PREFIX = 'sms-ai:verify:';
    private const ATTEMPTS_PREFIX = 'sms-ai:verify-attempts:';
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TTL_SECONDS = 900; // 15 min

    /**
     * Issue a 6-digit code, store its hash, and return the plain code (for delivery).
     */
    public function issueCode(string $phoneE164): string
    {
        $code = (string) random_int(100000, 999999);
        $ttl = (int) config('services.telnyx.ai_verification_ttl_minutes', 10) * 60;

        Cache::put(self::CACHE_PREFIX . $phoneE164, Hash::make($code), $ttl);
        Cache::forget(self::ATTEMPTS_PREFIX . $phoneE164);

        return $code;
    }

    /**
     * Verify a submitted code. Increments attempt counter; locks out after MAX_ATTEMPTS.
     */
    public function verifyCode(string $phoneE164, string $submittedCode): bool
    {
        $hash = Cache::get(self::CACHE_PREFIX . $phoneE164);
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        $attemptsKey = self::ATTEMPTS_PREFIX . $phoneE164;
        $attempts = (int) Cache::get($attemptsKey, 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $matches = Hash::check(trim($submittedCode), $hash);

        if (!$matches) {
            Cache::put($attemptsKey, $attempts + 1, self::LOCKOUT_TTL_SECONDS);
            return false;
        }

        Cache::forget(self::CACHE_PREFIX . $phoneE164);
        Cache::forget($attemptsKey);

        return true;
    }

    public function isLockedOut(string $phoneE164): bool
    {
        return (int) Cache::get(self::ATTEMPTS_PREFIX . $phoneE164, 0) >= self::MAX_ATTEMPTS;
    }

    public function clear(string $phoneE164): void
    {
        Cache::forget(self::CACHE_PREFIX . $phoneE164);
        Cache::forget(self::ATTEMPTS_PREFIX . $phoneE164);
    }
}
