<?php

namespace Tests\Feature;

use App\Services\WeatherLookupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
}
