<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherLookupService
{
    private const GEOCODE_API = 'https://maps.googleapis.com/maps/api/geocode/json';
    private const CURRENT_CONDITIONS_API = 'https://weather.googleapis.com/v1/currentConditions:lookup';
    private const HOURLY_FORECAST_API = 'https://weather.googleapis.com/v1/forecast/hours:lookup';
    private const REQUEST_TIMEOUT_SECONDS = 3;
    private const DEFAULT_FORECAST_HOURS = 24;
    private const MAX_FORECAST_HOURS = 240;
    private const RESULT_CACHE_TTL_SECONDS = 900; // 15 minutes
    private const NEGATIVE_CACHE_TTL_SECONDS = 300; // 5 minutes
    private const GEOCODE_CACHE_TTL_SECONDS = 30 * 24 * 60 * 60; // 30 days

    private ?string $googleApiKey;

    public function __construct()
    {
        $this->googleApiKey = config('services.google.maps_api_key')
            ?: config('services.google.places_api_key');
    }

    public function lookup(array $params): ?array
    {
        if (empty($this->googleApiKey)) {
            throw new \RuntimeException('Google API key is not configured for weather lookups.');
        }

        $cacheKey = $this->buildResultCacheKey($params);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            // Negative cache sentinel
            if ($cached === '__none__') {
                return null;
            }
            return $cached;
        }

        $target = $this->parseTargetDateTime($params['dateTime'] ?? null);
        $resolved = $this->resolveCoordinates($params);

        if (!$resolved) {
            Cache::put($cacheKey, '__none__', self::NEGATIVE_CACHE_TTL_SECONDS);
            return null;
        }

        $weather = $this->resolveWeather($resolved['latitude'], $resolved['longitude'], $target);

        if (!$weather) {
            Cache::put($cacheKey, '__none__', self::NEGATIVE_CACHE_TTL_SECONDS);
            return null;
        }

        $result = array_merge($weather, [
            'location' => $resolved['location'],
            'latitude' => $resolved['latitude'],
            'longitude' => $resolved['longitude'],
            'provider' => 'google_weather',
        ]);

        Cache::put($cacheKey, $result, self::RESULT_CACHE_TTL_SECONDS);

        return $result;
    }

    private function buildResultCacheKey(array $params): string
    {
        $lat = isset($params['latitude']) ? (float) $params['latitude'] : null;
        $lon = isset($params['longitude']) ? (float) $params['longitude'] : null;
        $location = isset($params['location']) ? strtolower(trim((string) $params['location'])) : '';
        $dateTime = $params['dateTime'] ?? null;

        // Round coordinates to ~100m precision so nearby requests share cache.
        $latPart = $lat !== null ? number_format($lat, 3, '.', '') : '';
        $lonPart = $lon !== null ? number_format($lon, 3, '.', '') : '';

        // Bucket dateTime by hour to maximize cache hits.
        $timeBucket = 'now';
        if ($dateTime) {
            try {
                $timeBucket = CarbonImmutable::parse($dateTime)->utc()->format('Y-m-d-H');
            } catch (\Throwable $e) {
                $timeBucket = 'now';
            }
        }

        return 'weather:lookup:' . md5(implode('|', [$latPart, $lonPart, $location, $timeBucket]));
    }

    private function parseTargetDateTime(?string $value): ?CarbonImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable $e) {
            Log::warning('Invalid weather target datetime provided', [
                'dateTime' => $value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveCoordinates(array $params): ?array
    {
        $latitude = isset($params['latitude']) ? (float) $params['latitude'] : null;
        $longitude = isset($params['longitude']) ? (float) $params['longitude'] : null;

        if ($latitude !== null && $longitude !== null) {
            return [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location' => $this->reverseGeocode($latitude, $longitude),
            ];
        }

        $location = trim((string) ($params['location'] ?? ''));
        if ($location === '') {
            return null;
        }

        return $this->geocode($location);
    }

    private function geocode(string $location): ?array
    {
        $cacheKey = 'weather:geocode:fwd:' . md5(strtolower(trim($location)));

        return Cache::remember($cacheKey, self::GEOCODE_CACHE_TTL_SECONDS, function () use ($location) {
            $response = Http::acceptJson()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(self::GEOCODE_API, [
                    'address' => $location,
                    'key' => $this->googleApiKey,
                ]);

            if (!$response->ok()) {
                Log::warning('Google geocoding request failed', [
                    'location' => $location,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $payload = $response->json();
            $result = $payload['results'][0] ?? null;
            $coords = $result['geometry']['location'] ?? null;

            if (($payload['status'] ?? null) !== 'OK' || !$coords) {
                Log::warning('Google geocoding returned no results', [
                    'location' => $location,
                    'status' => $payload['status'] ?? null,
                    'error_message' => $payload['error_message'] ?? null,
                ]);

                return null;
            }

            return [
                'latitude' => (float) $coords['lat'],
                'longitude' => (float) $coords['lng'],
                'location' => $this->formatLocationLabel($result) ?: ($result['formatted_address'] ?? $location),
            ];
        });
    }

    private function reverseGeocode(float $latitude, float $longitude): ?string
    {
        $cacheKey = 'weather:geocode:rev:' . md5(number_format($latitude, 3, '.', '') . ',' . number_format($longitude, 3, '.', ''));

        return Cache::remember($cacheKey, self::GEOCODE_CACHE_TTL_SECONDS, function () use ($latitude, $longitude) {
            $response = Http::acceptJson()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(self::GEOCODE_API, [
                    'latlng' => sprintf('%s,%s', $latitude, $longitude),
                    'key' => $this->googleApiKey,
                ]);

            if (!$response->ok()) {
                return null;
            }

            $payload = $response->json();
            $results = $payload['results'] ?? [];
            $result = $results[0] ?? null;

            if (($payload['status'] ?? null) !== 'OK' || !$result) {
                return null;
            }

            return $this->formatCoordinateLocationLabel($results)
                ?: $this->formatLocationLabel($result)
                ?: ($result['formatted_address'] ?? null);
        });
    }

    private function formatLocationLabel(array $result): ?string
    {
        $components = $result['address_components'] ?? [];
        $city = $this->findAddressComponent($components, [
            'locality',
            'postal_town',
            'administrative_area_level_2',
            'sublocality',
        ]);
        $state = $this->findAddressComponent($components, ['administrative_area_level_1'], true);

        if ($city && $state) {
            return sprintf('%s, %s', $city, $state);
        }

        return $city ?: ($result['formatted_address'] ?? null);
    }

    private function formatCoordinateLocationLabel(array $results): ?string
    {
        $componentPriorityGroups = [
            ['locality', 'postal_town'],
            ['administrative_area_level_3'],
            ['administrative_area_level_2'],
            ['sublocality_level_1', 'sublocality', 'neighborhood'],
        ];

        foreach ($componentPriorityGroups as $types) {
            foreach ($results as $result) {
                $components = $result['address_components'] ?? [];
                $city = $this->findAddressComponent($components, $types);

                if (!$city) {
                    continue;
                }

                $state = $this->findAddressComponent($components, ['administrative_area_level_1'], true);

                if ($state) {
                    return sprintf('%s, %s', $city, $state);
                }

                return $city;
            }
        }

        return null;
    }

    private function findAddressComponent(array $components, array $types, bool $shortName = false): ?string
    {
        foreach ($components as $component) {
            $componentTypes = $component['types'] ?? [];
            foreach ($types as $type) {
                if (in_array($type, $componentTypes, true)) {
                    return $shortName
                        ? ($component['short_name'] ?? null)
                        : ($component['long_name'] ?? null);
                }
            }
        }

        return null;
    }

    private function resolveWeather(
        float $latitude,
        float $longitude,
        ?CarbonImmutable $target,
    ): ?array {
        $now = CarbonImmutable::now('UTC');

        if ($target && $target->greaterThan($now->addMinutes(30))) {
            $hoursAhead = max(0, (int) ceil(($target->getTimestamp() - $now->getTimestamp()) / 3600));
            $hoursToRequest = min(
                self::MAX_FORECAST_HOURS,
                max(self::DEFAULT_FORECAST_HOURS, $hoursAhead + 6)
            );

            $forecastHours = $this->fetchHourlyForecast($latitude, $longitude, $hoursToRequest);
            $closestHour = $this->pickClosestForecastHour($forecastHours, $target);

            if ($closestHour) {
                return $this->formatForecastHour($closestHour);
            }
        }

        $currentConditions = $this->fetchCurrentConditions($latitude, $longitude);

        return $currentConditions ? $this->formatCurrentConditions($currentConditions) : null;
    }

    private function fetchCurrentConditions(float $latitude, float $longitude): ?array
    {
        $response = Http::acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->get(self::CURRENT_CONDITIONS_API, [
                'key' => $this->googleApiKey,
                'location.latitude' => $latitude,
                'location.longitude' => $longitude,
                'unitsSystem' => 'METRIC',
                'languageCode' => 'en',
            ]);

        if (!$response->ok()) {
            Log::warning('Google current weather lookup failed', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }

    private function fetchHourlyForecast(float $latitude, float $longitude, int $hours): array
    {
        $entries = [];
        $pageToken = null;
        $remaining = max(1, $hours);

        do {
            $pageSize = min(24, $remaining);

            $params = [
                'key' => $this->googleApiKey,
                'location.latitude' => $latitude,
                'location.longitude' => $longitude,
                'unitsSystem' => 'METRIC',
                'languageCode' => 'en',
                'hours' => $hours,
                'pageSize' => $pageSize,
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::acceptJson()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(self::HOURLY_FORECAST_API, $params);

            if (!$response->ok()) {
                Log::warning('Google hourly weather lookup failed', [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'hours' => $hours,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $payload = $response->json();
            $chunk = $payload['forecastHours'] ?? [];
            $entries = array_merge($entries, $chunk);
            $remaining = $hours - count($entries);
            $pageToken = $payload['nextPageToken'] ?? null;
        } while ($pageToken && $remaining > 0);

        return $entries;
    }

    private function pickClosestForecastHour(array $entries, CarbonImmutable $target): ?array
    {
        $bestEntry = null;
        $bestDiff = null;

        foreach ($entries as $entry) {
            $startTime = $entry['interval']['startTime'] ?? null;
            if (!$startTime) {
                continue;
            }

            try {
                $entryTime = CarbonImmutable::parse($startTime)->utc();
            } catch (\Throwable $e) {
                continue;
            }

            $diff = abs($entryTime->getTimestamp() - $target->getTimestamp());
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $bestEntry = $entry;
            }
        }

        return $bestEntry;
    }

    private function formatCurrentConditions(array $payload): array
    {
        $tempC = $this->extractTemperatureCelsius($payload['temperature'] ?? null);
        $description = $payload['weatherCondition']['description']['text'] ?? null;
        $type = $payload['weatherCondition']['type'] ?? null;

        return $this->formatWeatherPayload($tempC, $description, $type);
    }

    private function formatForecastHour(array $payload): array
    {
        $tempC = $this->extractTemperatureCelsius($payload['temperature'] ?? null);
        $description = $payload['weatherCondition']['description']['text'] ?? null;
        $type = $payload['weatherCondition']['type'] ?? null;

        return $this->formatWeatherPayload($tempC, $description, $type);
    }

    private function extractTemperatureCelsius(?array $temperature): ?float
    {
        $degrees = $temperature['degrees'] ?? null;
        if (!is_numeric($degrees)) {
            return null;
        }

        $unit = strtoupper((string) ($temperature['unit'] ?? 'CELSIUS'));
        $value = (float) $degrees;

        if ($unit === 'FAHRENHEIT') {
            return ($value - 32) * 5 / 9;
        }

        return $value;
    }

    private function formatWeatherPayload(?float $tempC, ?string $description, ?string $type): array
    {
        $temperatureF = $tempC !== null ? (int) round(($tempC * 9 / 5) + 32) : null;

        return [
            'temperature' => $tempC !== null ? sprintf('%d°', (int) round($tempC)) : null,
            'temperatureC' => $tempC !== null ? (int) round($tempC) : null,
            'temperatureF' => $temperatureF,
            'description' => $description,
            'icon' => $this->mapIcon($description, $type),
        ];
    }

    private function mapIcon(?string $description, ?string $type): string
    {
        $value = strtolower(trim(implode(' ', array_filter([$type, $description]))));

        if ($value === '') {
            return 'cloudy';
        }

        if (
            str_contains($value, 'snow')
            || str_contains($value, 'sleet')
            || str_contains($value, 'blizzard')
            || str_contains($value, 'ice')
        ) {
            return 'snowy';
        }

        if (
            str_contains($value, 'rain')
            || str_contains($value, 'drizzle')
            || str_contains($value, 'shower')
            || str_contains($value, 'storm')
            || str_contains($value, 'thunder')
        ) {
            return 'rainy';
        }

        if (
            str_contains($value, 'clear')
            || str_contains($value, 'sun')
            || str_contains($value, 'fair')
        ) {
            return 'sunny';
        }

        return 'cloudy';
    }
}
