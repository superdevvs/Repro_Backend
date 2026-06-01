<?php

namespace Tests\Feature;

use App\Services\IpLocationLookupService;
use App\Services\WeatherLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes
 *
 * Example/feature test for the weather and IP-location throttle adjustment.
 *
 * Validates: Requirements 6.1, 6.2, 6.3, 7.4
 *
 * Two complementary assertions:
 *  1. Configuration: the throttle limit declared on both the `/weather` and
 *     `/ip-location` routes exceeds the previous 60 req/min (expected 300),
 *     read directly from the registered route middleware. (Req 6.1, 6.2)
 *  2. Behaviour: a run of requests below the new limit but above the old 60
 *     limit returns no HTTP 429. The upstream lookup services are replaced with
 *     fast in-memory spies so the endpoints respond without real network calls,
 *     isolating the rate-limiter behaviour under test. (Req 6.3, 7.4)
 */
class ThrottleConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /** The previous throttle ceiling the fix must exceed. */
    private const OLD_LIMIT = 60;

    /**
     * Number of sequential requests fired per endpoint. Deliberately above the
     * old 60 limit and below the new 300 limit so the run would have produced a
     * 429 under the old configuration but must not under the new one.
     */
    private const REQUEST_COUNT = 65;

    /**
     * Req 6.1 / 6.2: the configured throttle limit on each route exceeds 60.
     */
    public function test_weather_and_ip_location_routes_throttle_above_sixty(): void
    {
        $weatherLimit = $this->throttleLimitForUri('api/weather');
        $ipLocationLimit = $this->throttleLimitForUri('api/ip-location');

        $this->assertGreaterThan(
            self::OLD_LIMIT,
            $weatherLimit,
            'The /weather route throttle limit must exceed the previous 60 req/min.'
        );

        $this->assertGreaterThan(
            self::OLD_LIMIT,
            $ipLocationLimit,
            'The /ip-location route throttle limit must exceed the previous 60 req/min.'
        );

        // The design specifies 300; assert the concrete configured value too.
        $this->assertSame(300, $weatherLimit, 'The /weather route should be throttled at 300 req/min.');
        $this->assertSame(300, $ipLocationLimit, 'The /ip-location route should be throttled at 300 req/min.');
    }

    /**
     * Req 6.3 / 7.4: a run of requests within the new limit (but above the old
     * 60 limit) returns no HTTP 429 for either endpoint.
     */
    public function test_requests_under_the_limit_return_no_too_many_requests(): void
    {
        $this->bindFastUpstreamSpies();

        // /weather is unauthenticated and requires a location (or coordinates).
        for ($i = 0; $i < self::REQUEST_COUNT; $i++) {
            $response = $this->getJson('/api/weather?location=Test City, TS');

            $this->assertNotSame(
                Response::HTTP_TOO_MANY_REQUESTS,
                $response->getStatusCode(),
                sprintf('Weather request #%d returned HTTP 429 within the throttle limit.', $i + 1)
            );
        }

        // /ip-location is also unauthenticated; the spy supplies a fast payload.
        for ($i = 0; $i < self::REQUEST_COUNT; $i++) {
            $response = $this->getJson('/api/ip-location');

            $this->assertNotSame(
                Response::HTTP_TOO_MANY_REQUESTS,
                $response->getStatusCode(),
                sprintf('IP-location request #%d returned HTTP 429 within the throttle limit.', $i + 1)
            );
        }
    }

    /**
     * Resolve the registered route by its URI and return the integer limit `N`
     * parsed from its `throttle:N,1` middleware entry.
     */
    private function throttleLimitForUri(string $uri): int
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn (Route $route): bool => $route->uri() === $uri);

        $this->assertNotNull($route, "Route for URI [{$uri}] is not registered.");

        $throttle = collect($route->gatherMiddleware())
            ->first(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        $this->assertNotNull($throttle, "Route [{$uri}] has no throttle middleware.");

        // Expected form: throttle:<maxAttempts>,<decayMinutes>
        $this->assertMatchesRegularExpression(
            '/^throttle:\d+,\d+$/',
            $throttle,
            "Route [{$uri}] throttle middleware [{$throttle}] is not in the expected throttle:N,M form."
        );

        [, $limitAndDecay] = explode(':', $throttle, 2);
        [$maxAttempts] = explode(',', $limitAndDecay, 2);

        return (int) $maxAttempts;
    }

    /**
     * Replace the upstream lookup services with fast in-memory spies so the
     * endpoints respond immediately without real network calls. Each spy skips
     * the parent constructor (which would require Google API configuration) and
     * returns a fixed non-null payload, yielding a 200 response.
     */
    private function bindFastUpstreamSpies(): void
    {
        $weatherSpy = new class extends WeatherLookupService {
            public function __construct()
            {
                // Skip the parent constructor: no Google API key needed for the spy.
            }

            public function lookup(array $params): ?array
            {
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

        $ipSpy = new class extends IpLocationLookupService {
            public function __construct()
            {
                // Skip the parent constructor: no Google API key needed for the spy.
            }

            public function lookup(?string $ip): ?array
            {
                return [
                    'latitude' => 37.7749,
                    'longitude' => -122.4194,
                    'location' => 'Test City, TS',
                    'postalCode' => '94103',
                    'provider' => 'test_spy',
                ];
            }

            public function refine(array $hint): ?array
            {
                return $this->lookup(null);
            }
        };

        $this->app->instance(WeatherLookupService::class, $weatherSpy);
        $this->app->instance(IpLocationLookupService::class, $ipSpy);
    }
}
