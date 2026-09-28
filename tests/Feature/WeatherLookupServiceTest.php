<?php

namespace Tests\Feature;

use App\Services\AddressLookupService;
use App\Services\WeatherLookupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WeatherLookupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('services.google.places_api_key', 'test-google-key');
        config()->set('services.google.maps_api_key', null);
        Cache::flush();
        Http::preventStrayRequests();
    }

    #[Test]
    public function it_uses_open_meteo_for_coordinates_when_google_keys_are_missing(): void
    {
        config()->set('services.google.places_api_key', null);
        config()->set('services.google.maps_api_key', null);

        Http::fake([
            'api.open-meteo.com/v1/forecast*' => Http::response([
                'current' => [
                    'temperature_2m' => 18.2,
                    'weather_code' => 3,
                    'precipitation' => 0,
                    'rain' => 0,
                    'showers' => 0,
                    'snowfall' => 0,
                    'cloud_cover' => 90,
                ],
            ]),
        ]);

        $weather = app(WeatherLookupService::class)->lookup([
            'latitude' => 40.7128,
            'longitude' => -74.006,
        ]);

        $this->assertSame('open_meteo', $weather['provider']);
        $this->assertSame(18, $weather['temperatureC']);
        $this->assertSame(65, $weather['temperatureF']);
        $this->assertSame('Overcast', $weather['description']);
        $this->assertNull($weather['location']);
    }

    #[Test]
    public function it_falls_back_to_open_meteo_when_google_weather_is_denied(): void
    {
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'New York, NY, USA',
                    'address_components' => [
                        [
                            'long_name' => 'New York',
                            'short_name' => 'New York',
                            'types' => ['locality'],
                        ],
                        [
                            'long_name' => 'New York',
                            'short_name' => 'NY',
                            'types' => ['administrative_area_level_1'],
                        ],
                    ],
                ]],
            ]),
            'weather.googleapis.com/v1/currentConditions:lookup*' => Http::response([
                'error' => [
                    'code' => 403,
                    'status' => 'PERMISSION_DENIED',
                    'message' => 'The caller does not have permission',
                ],
            ], 403),
            'api.open-meteo.com/v1/forecast*' => Http::response([
                'current' => [
                    'temperature_2m' => 22.4,
                    'weather_code' => 1,
                    'precipitation' => 0,
                    'rain' => 0,
                    'showers' => 0,
                    'snowfall' => 0,
                    'cloud_cover' => 12,
                ],
            ]),
        ]);

        $weather = app(WeatherLookupService::class)->lookup([
            'latitude' => 40.7128,
            'longitude' => -74.006,
        ]);

        $this->assertSame('open_meteo', $weather['provider']);
        $this->assertSame(22, $weather['temperatureC']);
        $this->assertSame(72, $weather['temperatureF']);
        $this->assertSame('Mainly clear', $weather['description']);
        $this->assertSame('sunny', $weather['icon']);
        $this->assertSame('New York, NY', $weather['location']);
    }

    #[Test]
    #[DataProvider('unavailableGoogleGeocoding')]
    public function it_uses_shared_address_geocoding_when_google_cannot_resolve_a_location(
        string $failure,
    ): void {
        $location = '123 Main Street, New York, NY';
        $googleCalls = 0;
        $this->mock(AddressLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('geocodeAddress')->once()->with([
                'address' => '123 Main Street',
                'city' => 'New York, NY',
            ])
                ->andReturn(['latitude' => 40.7128, 'longitude' => -74.006]);
        });

        Http::fake([
            'maps.googleapis.com/maps/api/geocode/json*' => function () use ($failure, &$googleCalls) {
                $googleCalls++;
                if ($failure === 'connection') {
                    throw new ConnectionException('Geocoding connection failed');
                }

                return Http::response([
                    'status' => $failure,
                    'results' => [],
                ], $failure === 'http' ? 503 : 200);
            },
            'weather.googleapis.com/*' => Http::response([], 403),
            'api.open-meteo.com/v1/forecast*' => Http::response([
                'current' => ['temperature_2m' => 22.4, 'weather_code' => 1],
            ]),
        ]);

        $service = app(WeatherLookupService::class);
        $weather = $service->lookup(['location' => $location]);

        $this->assertSame('open_meteo', $weather['provider']);
        $this->assertSame(22, $weather['temperatureC']);
        $this->assertSame(40.7128, $weather['latitude']);
        $this->assertSame(-74.006, $weather['longitude']);
        $this->assertSame($location, $weather['location']);
        $this->assertSame($weather, $service->lookup(['location' => $location]));
        // Provider exceptions must be handled before Cache::remember can retry its callback.
        $this->assertSame(1, $googleCalls);

        // Weather expires after 15 minutes; the successful geocode is still cached.
        $this->travel(16)->minutes();
        $this->assertSame($weather, $service->lookup(['location' => $location]));
        $this->assertSame(1, $googleCalls);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.open-meteo.com')
            && (float) $request['latitude'] === 40.7128
            && (float) $request['longitude'] === -74.006);
    }

    public static function unavailableGoogleGeocoding(): array
    {
        return [
            'billing or permission denied' => ['REQUEST_DENIED'],
            'no results' => ['ZERO_RESULTS'],
            'http failure' => ['http'],
            'connection failure' => ['connection'],
        ];
    }

    #[Test]
    #[DataProvider('fallbackAddresses')]
    public function it_preserves_locality_for_numbered_streets_without_broadening_city_queries(
        string $location,
        array $address,
    ): void {
        config()->set('services.google.places_api_key', null);
        $this->mock(AddressLookupService::class, function (MockInterface $mock) use ($address) {
            $mock->shouldReceive('geocodeAddress')->once()->with($address)
                ->andReturn(['latitude' => 38.8, 'longitude' => -77.3]);
        });
        Http::fake([
            'api.open-meteo.com/v1/forecast*' => Http::response([
                'current' => ['temperature_2m' => 18.2, 'weather_code' => 3],
            ]),
        ]);

        $weather = app(WeatherLookupService::class)->lookup(['location' => $location]);

        $this->assertSame('open_meteo', $weather['provider']);
        $this->assertSame($location, $weather['location']);
    }

    public static function fallbackAddresses(): array
    {
        return [
            'Springfield shoot' => [
                '9019 Golden Leaf Court, Springfield, Virginia, 22153',
                ['address' => '9019 Golden Leaf Court', 'city' => 'Springfield, Virginia, 22153'],
            ],
            'Burke shoot' => [
                '5629 Herberts Crossing Drive, Burke, Virginia, 22015',
                ['address' => '5629 Herberts Crossing Drive', 'city' => 'Burke, Virginia, 22015'],
            ],
            'two component street address' => [
                '9019 Golden Leaf Court, Springfield VA 22153',
                ['address' => '9019 Golden Leaf Court', 'city' => 'Springfield VA 22153'],
            ],
            'city and state' => ['Arlington, VA', ['address' => 'Arlington, VA']],
            'city state and country' => ['Arlington, Virginia, USA', ['address' => 'Arlington, Virginia, USA']],
        ];
    }

    #[Test]
    public function it_resolves_location_weather_without_a_google_key(): void
    {
        config()->set('services.google.places_api_key', null);
        $this->mock(AddressLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('geocodeAddress')->once()->with(['address' => 'New York, NY'])
                ->andReturn(['latitude' => 40.7128, 'longitude' => -74.006]);
        });
        Http::fake([
            'api.open-meteo.com/v1/forecast*' => Http::response([
                'current' => ['temperature_2m' => 18.2, 'weather_code' => 3],
            ]),
        ]);

        $weather = app(WeatherLookupService::class)->lookup(['location' => 'New York, NY']);

        $this->assertSame('open_meteo', $weather['provider']);
        $this->assertSame(18, $weather['temperatureC']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_keeps_google_geocoding_when_successful(): void
    {
        $this->mock(AddressLookupService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('geocodeAddress');
        });
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'New York, NY, USA',
                    'geometry' => ['location' => ['lat' => 40.7128, 'lng' => -74.006]],
                ]],
            ]),
            'weather.googleapis.com/*' => Http::response([
                'temperature' => ['degrees' => 20, 'unit' => 'CELSIUS'],
                'weatherCondition' => ['type' => 'CLEAR', 'description' => ['text' => 'Clear']],
            ]),
        ]);

        $service = app(WeatherLookupService::class);
        $weather = $service->lookup(['location' => 'New York, NY']);

        $this->assertSame('google_weather', $weather['provider']);
        $this->assertSame(20, $weather['temperatureC']);
        $this->assertSame('New York, NY, USA', $weather['location']);
        $this->assertSame($weather, $service->lookup(['location' => 'New York, NY']));
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_uses_the_requested_future_hour_after_geocoding_and_weather_fallbacks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T12:00:00Z'));
        $target = CarbonImmutable::parse('2026-10-05T14:00:00Z');
        $location = '5629 Herberts Crossing Drive, Burke, Virginia, 22015';
        $this->mock(AddressLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('geocodeAddress')->once()->with([
                'address' => '5629 Herberts Crossing Drive',
                'city' => 'Burke, Virginia, 22015',
            ])->andReturn(['latitude' => 38.8, 'longitude' => -77.3]);
        });
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/json*' => Http::response(['status' => 'REQUEST_DENIED']),
            'weather.googleapis.com/*' => Http::response([], 403),
            'api.open-meteo.com/v1/forecast*' => Http::response([
                'hourly' => [
                    'time' => ['2026-10-05T13:00', '2026-10-05T14:00', '2026-10-05T15:00'],
                    'temperature_2m' => [10, 22.4, 17],
                    'weather_code' => [3, 1, 61],
                ],
            ]),
        ]);

        $weather = app(WeatherLookupService::class)->lookup([
            'location' => $location,
            'dateTime' => $target->toIso8601String(),
        ]);

        $this->assertSame('open_meteo', $weather['provider']);
        $this->assertSame(22, $weather['temperatureC']);
        $this->assertSame('sunny', $weather['icon']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.open-meteo.com')
            && (int) $request['forecast_days'] === 16
            && str_contains($request['hourly'], 'temperature_2m')
            && $request['timezone'] === 'UTC');
        Http::assertSentCount(4);
    }

    #[Test]
    public function reverse_geocoding_outage_does_not_block_weather_for_coordinates(): void
    {
        $reverseCalls = 0;
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/json*' => function () use (&$reverseCalls) {
                $reverseCalls++;
                throw new ConnectionException('Geocoding connection failed');
            },
            'weather.googleapis.com/*' => Http::response([], 503),
            'api.open-meteo.com/v1/forecast*' => Http::response([
                'current' => ['temperature_2m' => 18.2, 'weather_code' => 3],
            ]),
        ]);

        $weather = app(WeatherLookupService::class)->lookup([
            'latitude' => 40.7128,
            'longitude' => -74.006,
        ]);

        $this->assertSame('open_meteo', $weather['provider']);
        $this->assertSame(18, $weather['temperatureC']);
        $this->assertNull($weather['location']);
        $this->assertSame(1, $reverseCalls);
    }

    #[Test]
    public function it_negative_caches_unavailable_geocoding_without_repeating_provider_failures(): void
    {
        $googleCalls = 0;
        $this->mock(AddressLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('geocodeAddress')->once()->with(['address' => 'Unknown Location'])
                ->andThrow(new \RuntimeException('Address provider unavailable'));
        });
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/json*' => function () use (&$googleCalls) {
                $googleCalls++;
                throw new ConnectionException('Google unavailable');
            },
        ]);

        $service = app(WeatherLookupService::class);

        $this->assertNull($service->lookup(['location' => 'Unknown Location']));
        $this->assertNull($service->lookup(['location' => 'Unknown Location']));
        $this->assertSame(1, $googleCalls);
    }

    #[Test]
    public function it_returns_null_when_geocoding_succeeds_but_all_weather_providers_are_unavailable(): void
    {
        $this->mock(AddressLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('geocodeAddress')->once()->with(['address' => 'New York, NY'])
                ->andReturn(['latitude' => 40.7128, 'longitude' => -74.006]);
        });
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/json*' => Http::response(['status' => 'REQUEST_DENIED']),
            'weather.googleapis.com/*' => Http::response([], 403),
            'api.open-meteo.com/v1/forecast*' => Http::response([], 503),
        ]);

        $service = app(WeatherLookupService::class);

        $this->assertNull($service->lookup(['location' => 'New York, NY']));
        $this->assertNull($service->lookup(['location' => 'New York, NY']));
        Http::assertSentCount(3);
    }
}
