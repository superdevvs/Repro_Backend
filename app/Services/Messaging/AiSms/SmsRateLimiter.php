<?php

namespace App\Services\Messaging\AiSms;

use Illuminate\Support\Facades\Cache;

class SmsRateLimiter
{
    private const KEY_PREFIX = 'sms-ai:thread:';
    private const WINDOW_SECONDS = 3600;

    public function attempt(int $threadId): bool
    {
        $key = self::KEY_PREFIX . $threadId;
        $current = (int) Cache::get($key, 0);
        $max = $this->maxPerHour();

        if ($current >= $max) {
            return false;
        }

        $remaining = $this->windowTtlSeconds($key);
        Cache::put($key, $current + 1, $remaining);

        return true;
    }

    public function maxPerHour(): int
    {
        return max(1, (int) config('services.telnyx.ai_max_replies_per_hour', 20));
    }

    public function clear(int $threadId): void
    {
        Cache::forget(self::KEY_PREFIX . $threadId);
    }

    private function windowTtlSeconds(string $key): int
    {
        // Cache::get does not return TTL; we re-use the window each call. This is the
        // simple fixed-window approach (good enough for SMS reply limits).
        if (Cache::has($key)) {
            return self::WINDOW_SECONDS;
        }
        return self::WINDOW_SECONDS;
    }
}
