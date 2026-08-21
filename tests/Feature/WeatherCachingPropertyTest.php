<?php

namespace Tests\Feature;

use App\Services\WeatherLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Property-based test for weather response caching.
 *
 * Feature: production-qa-fixes, Property 1: Weather caching serves repeats from
 * cache within TTL and refreshes after TTL
 *
 * Validates: Requirements 5.1, 5.3, 5.4
 *
 * Approach: a generative, randomized-input loop (min 100 iterations). Each
 * iteration generates a distinct, valid weather request param-set, drives the
 * cached WeatherController endpoint through a counting spy that stands in for
 * the upstream WeatherLookupService, and asserts the caching invariants across
 * the TTL boundary using Laravel time travel against the `array` cache store.
 */
class WeatherCachingPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Iterations for the property loop (min 100 required by the design). */
    private const ITERATIONS = 120;

    /** Mirrors WeatherController::CACHE_TTL_MINUTES. */
    private const TTL_MINUTES = 10;

    /**
     * Counting spy bound in place of the upstream WeatherLookupService. Bound
     * once in setUp because the router resolves and retains the controller (and
     * thus its injected lookup service) for the lifetime of the test; rebinding
     * a fresh instance per iteration would not reach the already-resolved
     * controller. The call counter is reset at the start of each iteration so
     * counts stay isolated per param-set.
     */
    private WeatherLookupService $spy;

    protected function setUp(): void
    {
        parent::setUp();

        // Caching behaviour is the property under test. The route throttle limit
        // (Requirement 6, covered separately) would otherwise reject the high
        // request volume this property generates, so disable only the limiter.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->spy = new class extends WeatherLookupService
        {
            public int $calls = 0;

            public bool $available = true;

            public function __construct()
            {
                // Intentionally skip the parent constructor so the spy does not
                // depend on a configured Google API key.
            }

            public function lookup(array $params): ?array
            {
                $this->calls++;

                if (! $this->available) {
                    return null;
                }

                return [
                    'temperature' => '21°',
                    'temperatureC' => 21,
                    'temperatureF' => 70,
                    'description' => 'Clear',
                    'icon' => 'sunny',
                    'location' => 'Test City, TS',
                    'latitude' => 1.0,
                    'longitude' => 2.0,
                    'provider' => 'test_spy',
                ];
            }
        };

        $this->app->instance(WeatherLookupService::class, $this->spy);
    }

    public function test_unavailable_weather_is_an_empty_successful_result(): void
    {
        $this->spy->available = false;

        $this->getJson('/api/weather?location=Unknown%20Location')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Feature: production-qa-fixes, Property 1: Weather caching serves repeats
     * from cache within TTL and refreshes after TTL
     *
     * Validates: Requirements 5.1, 5.3, 5.4
     */
    public function test_weather_caching_serves_repeats_within_ttl_and_refreshes_after_ttl(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Isolate each iteration so call counts and cache entries are not
            // influenced by a previous param-set.
            Cache::flush();
            $this->spy->calls = 0;

            // Pin an absolute base time so the TTL window is unambiguous.
            $base = Carbon::create(2026, 1, 1, 12, 0, 0);
            Carbon::setTestNow($base);

            $params = $this->randomWeatherParams($i);
            $query = http_build_query($params);
            $context = 'Params: '.json_encode($params);

            // (1) First request for the param-set invokes the upstream lookup
            //     exactly once and stores the result. (Req 5.1, 5.3)
            $this->getJson('/api/weather?'.$query)
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame(
                1,
                $this->spy->calls,
                "First request must invoke the upstream lookup exactly once. {$context}"
            );

            // (2) Subsequent requests with the SAME params within the TTL are
            //     served from cache; the upstream is NOT called again. (Req 5.1)
            $repeats = random_int(1, 4);
            for ($r = 0; $r < $repeats; $r++) {
                // Move forward but stay strictly inside the TTL window.
                Carbon::setTestNow($base->copy()->addMinutes(random_int(0, self::TTL_MINUTES - 1)));

                $this->getJson('/api/weather?'.$query)
                    ->assertOk()
                    ->assertJsonPath('success', true);
            }

            $this->assertSame(
                1,
                $this->spy->calls,
                "Repeats within the TTL must be served from cache (no re-invocation). {$context}"
            );

            // (3) The first request AFTER the TTL elapses re-invokes the upstream
            //     lookup. (Req 5.4)
            Carbon::setTestNow($base->copy()->addMinutes(self::TTL_MINUTES + 1));

            $this->getJson('/api/weather?'.$query)
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame(
                2,
                $this->spy->calls,
                "The first request after the TTL elapses must re-invoke the upstream lookup. {$context}"
            );
        }
    }

    /**
     * Generate a randomized but valid weather request param-set. Each call
     * produces a distinct param-set (the iteration seed is folded into the
     * location string) so cache keys do not collide across iterations.
     *
     * @return array<string, string>
     */
    private function randomWeatherParams(int $seed): array
    {
        $params = [];

        switch (random_int(0, 2)) {
            case 0:
                // Location string only.
                $params['location'] = 'City '.random_int(1, 1_000_000).'-'.$seed;
                break;
            case 1:
                // Coordinates only (within valid lat/long ranges).
                $params['latitude'] = (string) round(random_int(-9000, 9000) / 100, 4);
                $params['longitude'] = (string) round(random_int(-18000, 18000) / 100, 4);
                break;
            default:
                // Both a location string and coordinates.
                $params['location'] = 'City '.random_int(1, 1_000_000).'-'.$seed;
                $params['latitude'] = (string) round(random_int(-9000, 9000) / 100, 4);
                $params['longitude'] = (string) round(random_int(-18000, 18000) / 100, 4);
                break;
        }

        // The optional dateTime participates in the cache key.
        if (random_int(0, 1) === 1) {
            $params['dateTime'] = Carbon::create(
                2026,
                random_int(1, 12),
                random_int(1, 28),
                random_int(0, 23),
                0,
                0,
            )->toIso8601String();
        }

        return $params;
    }
}
