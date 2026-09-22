<?php

namespace Tests\Unit\Services;

use App\Services\AddressLookupService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AddressLookupDistanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        config()->set('services.google.places_api_key', null);
        config()->set('services.nominatim.throttle_cache_store', 'array');
        config()->set('services.nominatim.min_interval_milliseconds', 0);
        Cache::flush();
        Http::preventStrayRequests();
    }

    #[Test]
    public function saved_coordinates_provide_distance_without_an_external_lookup(): void
    {
        Http::fake();
        $result = app(AddressLookupService::class)->getDistance(
            ['latitude' => 0, 'longitude' => 0], ['latitude' => 0, 'longitude' => 1]
        );
        $this->assertGreaterThan(111000, $result['distance_value']);
        $this->assertLessThan(112000, $result['distance_value']);
        Http::assertNothingSent();
    }

    public static function invalidCoordinates(): array
    {
        return [[91, 0], [0, -181], ['invalid', 0], [INF, 0]];
    }

    #[Test]
    #[DataProvider('invalidCoordinates')]
    public function invalid_coordinates_never_produce_a_distance(mixed $latitude, mixed $longitude): void
    {
        Http::fake();
        $this->assertNull(app(AddressLookupService::class)->getDistance(
            ['latitude' => $latitude, 'longitude' => $longitude], ['latitude' => 0, 'longitude' => 0]
        ));
        Http::assertNothingSent();
    }

    public static function googleFailures(): array
    {
        return [
            'http error' => [503, []],
            'rejected request' => [200, ['status' => 'REQUEST_DENIED']],
            'no route' => [200, ['status' => 'OK', 'rows' => [['elements' => [['status' => 'ZERO_RESULTS']]]]]],
            'malformed response' => [200, []],
        ];
    }

    #[Test]
    #[DataProvider('googleFailures')]
    public function google_failure_falls_back_to_the_correct_saved_coordinates(int $status, array $body): void
    {
        config()->set('services.google.places_api_key', 'test-key');
        Http::fake(['maps.googleapis.com/*' => Http::response($body, $status)]);
        $result = app(AddressLookupService::class)->getDistance(
            ['latitude' => 38.85, 'longitude' => -77.3], ['latitude' => 38.85, 'longitude' => -77.3]
        );
        $this->assertSame(0, $result['distance_value']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_connection_failure_also_uses_saved_coordinates(): void
    {
        config()->set('services.google.places_api_key', 'test-key');
        Http::fake(fn () => throw new ConnectionException('Synthetic provider outage'));
        $result = app(AddressLookupService::class)->getDistance(
            ['latitude' => 0, 'longitude' => 0], ['latitude' => 0, 'longitude' => 0]
        );
        $this->assertSame(0, $result['distance_value']);
    }

    #[Test]
    public function unknown_street_falls_back_to_locality_geocoding(): void
    {
        Http::fakeSequence()->push([], 200)->push([['lat' => '39.084', 'lon' => '-77.1528']], 200);
        $result = app(AddressLookupService::class)->getDistance(
            ['address' => 'Unknown Street', 'city' => 'Rockville', 'state' => 'MD'],
            ['latitude' => 39.084, 'longitude' => -77.1528]
        );
        $this->assertSame(0, $result['distance_value']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['q'] === 'Rockville, MD');
    }

    #[Test]
    public function failed_lookup_is_retried_and_success_is_reused(): void
    {
        Http::fakeSequence()->push([], 503)->push([['lat' => '39.084', 'lon' => '-77.1528']], 200);
        $origin = ['address' => 'Unknown Street'];
        $destination = ['latitude' => 39.084, 'longitude' => -77.1528];
        $service = app(AddressLookupService::class);

        $this->assertNull($service->getDistance($origin, $destination));
        $result = $service->getDistance($origin, $destination);
        $this->assertSame(0, $result['distance_value']);
        $this->assertSame($result, $service->getDistance($origin, $destination));
        Http::assertSentCount(2);
    }
}
