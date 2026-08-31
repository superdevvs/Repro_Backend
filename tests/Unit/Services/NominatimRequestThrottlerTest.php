<?php

namespace Tests\Unit\Services;

use App\Services\NominatimRequestThrottler;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class NominatimRequestThrottlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('services.nominatim.throttle_cache_store', 'array');
        config()->set('services.nominatim.min_interval_milliseconds', 1000);
        config()->set('services.nominatim.lock_seconds', 20);
        config()->set('services.nominatim.lock_wait_seconds', 1);

        Carbon::setTestNow('2026-08-31 12:00:00');
        Sleep::fake(syncWithCarbon: true);
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_spaces_successive_requests_using_the_shared_timestamp(): void
    {
        $throttler = app(NominatimRequestThrottler::class);
        $startedAt = [];

        $throttler->run(function () use (&$startedAt): void {
            $startedAt[] = now()->getTimestampMs();
        });
        $throttler->run(function () use (&$startedAt): void {
            $startedAt[] = now()->getTimestampMs();
        });

        $this->assertSame(1000, $startedAt[1] - $startedAt[0]);
        $this->assertSame(
            $startedAt[1],
            Cache::store('array')->get(NominatimRequestThrottler::LAST_REQUEST_STARTED_AT_KEY)
        );
        Sleep::assertSlept(
            fn ($duration) => (int) $duration->totalMilliseconds === 1000
        );
    }

    #[Test]
    public function a_failed_request_consumes_a_slot_and_releases_the_lock(): void
    {
        $throttler = app(NominatimRequestThrottler::class);

        try {
            $throttler->run(fn () => throw new RuntimeException('provider failed'));
            $this->fail('Expected the provider failure to be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider failed', $exception->getMessage());
        }

        $secondRequestRan = false;
        $throttler->run(function () use (&$secondRequestRan): void {
            $secondRequestRan = true;
        });

        $this->assertTrue($secondRequestRan);
        Sleep::assertSlept(
            fn ($duration) => (int) $duration->totalMilliseconds === 1000
        );
    }

    #[Test]
    public function it_fails_closed_when_the_shared_lock_cannot_be_acquired(): void
    {
        $cache = Cache::store('array');
        $heldLock = $cache->lock(NominatimRequestThrottler::LOCK_KEY, 20);
        $this->assertTrue($heldLock->acquire());

        $requestRan = false;

        try {
            $this->expectException(LockTimeoutException::class);

            app(NominatimRequestThrottler::class)->run(function () use (&$requestRan): void {
                $requestRan = true;
            });
        } finally {
            $heldLock->release();
            $this->assertFalse($requestRan);
        }
    }
}
