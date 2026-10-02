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

    public function test_booking_deadline_never_waits_for_a_busy_global_provider_lock(): void
    {
        $held = Cache::store('array')->lock(NominatimRequestThrottler::LOCK_KEY, 20);
        $this->assertTrue($held->get());
        $ran = false;
        try {
            app(NominatimRequestThrottler::class)->run(function () use (&$ran) {
                $ran = true;
            }, microtime(true) + 3);
            $this->fail('A busy provider must remain unknown within the booking deadline.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('busy', $exception->getMessage());
        } finally {
            $held->release();
        }
        $this->assertFalse($ran);
        Sleep::assertNeverSlept();
    }

    public function test_booking_deadline_preserves_rate_limit_when_next_provider_slot_does_not_fit(): void
    {
        Cache::store('array')->forever(NominatimRequestThrottler::LAST_REQUEST_STARTED_AT_KEY, now()->getTimestampMs());
        $ran = false;
        try {
            app(NominatimRequestThrottler::class)->run(function () use (&$ran) {
                $ran = true;
            }, microtime(true) + 0.1);
            $this->fail('Do not exceed the request budget or bypass the provider interval.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('budget', $exception->getMessage());
        }
        $this->assertFalse($ran);
        Sleep::assertNeverSlept();
        $lock = Cache::store('array')->lock(NominatimRequestThrottler::LOCK_KEY, 20);
        $this->assertTrue($lock->get());
        $lock->release();
    }
}
