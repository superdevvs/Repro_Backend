<?php

namespace Tests\Feature;

use App\Http\Controllers\API\IpLocationController;
use App\Services\IpLocationLookupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes
 * Property 2: IP-location caching serves repeats from cache within TTL and refreshes after TTL.
 *
 * Validates: Requirements 5.2, 5.3, 5.4
 *
 * For any IP-location request keyed by the requesting IP address (or by an explicit
 * location hint), the first request invokes the upstream IP-location lookup exactly once
 * and stores the result; every subsequent request with the same key within the TTL is
 * served from the cache without re-invoking the upstream lookup; and the first request
 * after the TTL elapses re-invokes the upstream lookup.
 *
 * This is a property-based (generative) test: it drives the cached controller across
 * many randomized valid IP addresses and location hints, asserting the caching invariant
 * holds for every generated input. Minimum 100 iterations.
 */
class IpLocationCachingPropertyTest extends TestCase
{
    /**
     * Number of generative iterations. The design mandates a minimum of 100.
     */
    private const ITERATIONS = 150;

    /**
     * A counting spy bound in place of the real upstream lookup service. It returns a
     * fixed non-null payload and records how many times each upstream method was called
     * so the test can assert cache hits (no extra calls) and TTL refreshes (a new call).
     */
    private function makeUpstreamSpy(): IpLocationLookupService
    {
        return new class extends IpLocationLookupService {
            public int $lookupCalls = 0;

            public int $refineCalls = 0;

            public array $payload = [
                'latitude' => 37.7749,
                'longitude' => -122.4194,
                'location' => 'Test City, TS',
                'postalCode' => '94103',
                'provider' => 'test_spy',
            ];

            public function __construct()
            {
                // Skip the real constructor (which reads Google config); not needed for the spy.
            }

            public function lookup(?string $ip): ?array
            {
                $this->lookupCalls++;

                return $this->payload;
            }

            public function refine(array $hint): ?array
            {
                $this->refineCalls++;

                return $this->payload;
            }

            public function totalCalls(): int
            {
                return $this->lookupCalls + $this->refineCalls;
            }
        };
    }

    /**
     * Generate a randomized, public-looking IPv4 address. The exact value only needs to
     * be a stable cache key for the property; the spy ignores the address content.
     */
    private function randomPublicIp(): string
    {
        $publicFirstOctets = [8, 23, 45, 51, 64, 72, 99, 104, 142, 151, 203, 208, 216];

        return sprintf(
            '%d.%d.%d.%d',
            $publicFirstOctets[array_rand($publicFirstOctets)],
            mt_rand(0, 255),
            mt_rand(0, 255),
            mt_rand(1, 254),
        );
    }

    /**
     * Build a generated request spec. Roughly half are keyed by the requesting IP and
     * half by an explicit location hint (coordinates or postal code), exercising both
     * cache-key branches of the controller.
     */
    private function generateRequestSpec(int $i): array
    {
        $mode = $i % 3; // 0 => IP, 1 => coordinate hint, 2 => postal hint

        if ($mode === 0) {
            return [
                'kind' => 'ip',
                'ip' => $this->randomPublicIp(),
                'query' => [],
            ];
        }

        if ($mode === 1) {
            return [
                'kind' => 'hint',
                'ip' => $this->randomPublicIp(),
                'query' => [
                    'latitude' => round(mt_rand(-89999, 89999) / 1000, 3),
                    'longitude' => round(mt_rand(-179999, 179999) / 1000, 3),
                    'city' => 'City' . mt_rand(1, 9999),
                    'region' => 'Region' . mt_rand(1, 99),
                ],
            ];
        }

        return [
            'kind' => 'hint',
            'ip' => $this->randomPublicIp(),
            'query' => [
                'postalCode' => str_pad((string) mt_rand(0, 99999), 5, '0', STR_PAD_LEFT),
                'countryCode' => ['US', 'CA', 'GB', 'AU'][mt_rand(0, 3)],
            ],
        ];
    }

    private function buildRequest(array $spec): Request
    {
        return Request::create(
            '/api/ip-location',
            'GET',
            $spec['query'],
            [],
            [],
            ['REMOTE_ADDR' => $spec['ip']],
        );
    }

    public function test_ip_location_caching_serves_repeats_within_ttl_and_refreshes_after_ttl(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Isolate each iteration: clear cache and reset the clock to real time so a
            // previous iteration's stored entry or time-travel cannot leak in.
            Cache::flush();
            $this->travelBack();

            $spec = $this->generateRequestSpec($i);
            $context = sprintf('iteration %d (%s, ip=%s, query=%s)', $i, $spec['kind'], $spec['ip'], json_encode($spec['query']));

            // Fresh spy per iteration so call counts start at zero, bound into the
            // container so the resolved controller receives it via constructor injection.
            $spy = $this->makeUpstreamSpy();
            $this->app->instance(IpLocationLookupService::class, $spy);
            $controller = $this->app->make(IpLocationController::class);

            // --- 1. First request: upstream invoked exactly once and the result stored. ---
            $first = $controller->show($this->buildRequest($spec));
            $firstData = $first->getData(true);

            $this->assertSame(200, $first->getStatusCode(), "First response should be 200 for {$context}");
            $this->assertTrue($firstData['success'] ?? false, "First response should be successful for {$context}");
            $this->assertSame($spy->payload, $firstData['data'] ?? null, "First response should return the upstream payload for {$context}");
            $this->assertSame(1, $spy->totalCalls(), "First request must invoke upstream exactly once for {$context}");

            // The IP-keyed branch has a trivially computable key, so assert storage directly.
            if ($spec['kind'] === 'ip') {
                $this->assertTrue(
                    Cache::has('iploc:ip:' . sha1($spec['ip'])),
                    "First IP request must store the result under the IP cache key for {$context}"
                );
            }

            // --- 2. Repeat within TTL (and partway through TTL): served from cache. ---
            $second = $controller->show($this->buildRequest($spec));
            $this->assertSame(1, $spy->totalCalls(), "Repeat within TTL must be served from cache (no extra upstream call) for {$context}");
            $this->assertSame($firstData, $second->getData(true), "Cached repeat must return the same payload for {$context}");

            // Advance to just before the TTL boundary; still a cache hit.
            $this->travel(IpLocationController::CACHE_TTL_MINUTES - 1)->minutes();
            $controller->show($this->buildRequest($spec));
            $this->assertSame(1, $spy->totalCalls(), "Request just before TTL expiry must still be a cache hit for {$context}");

            // --- 3. First request after TTL elapses: upstream re-invoked. ---
            $this->travel(2)->minutes(); // now past CACHE_TTL_MINUTES since the first store
            $afterTtl = $controller->show($this->buildRequest($spec));

            $this->assertSame(2, $spy->totalCalls(), "First request after TTL must re-invoke upstream for {$context}");
            $this->assertSame($spy->payload, $afterTtl->getData(true)['data'] ?? null, "Refreshed response should return the upstream payload for {$context}");
        }

        // Reset the clock for any subsequent tests.
        $this->travelBack();
    }
}
