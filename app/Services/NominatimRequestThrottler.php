<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use RuntimeException;

class NominatimRequestThrottler
{
    /**
     * These keys are intentionally global to the application. All web and queue
     * workers using the configured shared cache store reserve the same slot.
     */
    public const LOCK_KEY = 'nominatim:request-lock';

    public const LAST_REQUEST_STARTED_AT_KEY = 'nominatim:last-request-started-at-ms';

    public const DEFAULT_MIN_INTERVAL_MILLISECONDS = 1000;

    public const DEFAULT_LOCK_SECONDS = 20;

    public const DEFAULT_LOCK_WAIT_SECONDS = 15;

    /**
     * Run one Nominatim request while enforcing the provider's application-wide
     * maximum of one request per second.
     *
     * The timestamp is reserved before invoking the request callback, so failed
     * requests consume a slot too. Laravel releases the lock in a finally block,
     * while its lease provides recovery if a worker terminates unexpectedly.
     */
    public function run(Closure $request): mixed
    {
        $storeName = config('services.nominatim.throttle_cache_store');
        $cache = Cache::store(is_string($storeName) && $storeName !== '' ? $storeName : null);

        if (! $cache->getStore() instanceof LockProvider) {
            throw new RuntimeException('The configured Nominatim throttle cache store must support atomic locks.');
        }

        $minimumInterval = max(
            0,
            (int) config('services.nominatim.min_interval_milliseconds', self::DEFAULT_MIN_INTERVAL_MILLISECONDS)
        );
        $requestTimeout = max(
            1,
            (int) config('services.nominatim.request_timeout_seconds', 10)
        );
        $lockSeconds = max(
            1,
            (int) config('services.nominatim.lock_seconds', self::DEFAULT_LOCK_SECONDS),
            $requestTimeout + (int) ceil($minimumInterval / 1000) + 5
        );
        $lockWaitSeconds = max(
            0,
            (int) config('services.nominatim.lock_wait_seconds', self::DEFAULT_LOCK_WAIT_SECONDS)
        );

        return $cache
            ->lock(self::LOCK_KEY, $lockSeconds)
            ->block($lockWaitSeconds, function () use ($cache, $minimumInterval, $request) {
                $lastStartedAt = (int) $cache->get(self::LAST_REQUEST_STARTED_AT_KEY, 0);
                $now = $this->currentTimeMilliseconds();
                $elapsed = max(0, $now - $lastStartedAt);
                $remaining = $lastStartedAt > 0
                    ? max(0, $minimumInterval - $elapsed)
                    : 0;

                if ($remaining > 0) {
                    Sleep::usleep($remaining * 1000);
                }

                $cache->forever(
                    self::LAST_REQUEST_STARTED_AT_KEY,
                    $this->currentTimeMilliseconds()
                );

                return $request();
            });
    }

    private function currentTimeMilliseconds(): int
    {
        return (int) now()->getTimestampMs();
    }
}
