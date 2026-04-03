<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IpLocationLookupService
{
    private const PRIMARY_IP_LOOKUP_URL = 'https://ipapi.co/%s/json/';
    private const FALLBACK_IP_LOOKUP_URL = 'http://ip-api.com/json/%s?fields=status,city,region,regionName,country,countryCode,zip,lat,lon';
    private const GEOCODE_API = 'https://maps.googleapis.com/maps/api/geocode/json';
    private const REQUEST_TIMEOUT_SECONDS = 5;
    private const CACHE_TTL_SECONDS = 86400;

    private ?string $googleApiKey;

    public function __construct()
    {
        $this->googleApiKey = config('services.google.maps_api_key')
            ?: config('services.google.places_api_key');
    }

    public function lookup(?string $ip): ?array
    {
        $ip = trim((string) $ip);

        if (!$this->isPublicIp($ip)) {
            return null;
        }

        return Cache::remember(
            'ip_location_' . md5($ip),
            self::CACHE_TTL_SECONDS,
            fn () => $this->lookupUncached($ip),
        );
    }

    private function lookupUncached(string $ip): ?array
    {
        $providerLocation = $this->resolveIpProviderLocation($ip);

        if (!$providerLocation) {
            return null;
        }

        $googleRefined = $this->refineWithGoogle($providerLocation);

        if ($googleRefined) {
            return $googleRefined;
        }

        return [
            'latitude' => $providerLocation['latitude'],
            'longitude' => $providerLocation['longitude'],
            'location' => $providerLocation['label'],
            'postalCode' => $providerLocation['postalCode'],
            'provider' => $providerLocation['provider'],
        ];
    }

    private function resolveIpProviderLocation(string $ip): ?array
    {
        return $this->lookupViaIpApiCo($ip) ?: $this->lookupViaIpApi($ip);
    }

    private function lookupViaIpApiCo(string $ip): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(sprintf(self::PRIMARY_IP_LOOKUP_URL, $ip));

            if (!$response->ok()) {
                return null;
            }

            $data = $response->json();

            if (
                !is_numeric($data['latitude'] ?? null)
                || !is_numeric($data['longitude'] ?? null)
            ) {
                return null;
            }

            return [
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'label' => $this->buildProviderLabel(
                    $data['city'] ?? null,
                    $data['region_code'] ?? ($data['region'] ?? null),
                ),
                'postalCode' => $data['postal'] ?? null,
                'countryCode' => $data['country_code'] ?? null,
                'provider' => 'ipapi',
            ];
        } catch (\Throwable $e) {
            Log::debug('ipapi IP lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function lookupViaIpApi(string $ip): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(sprintf(self::FALLBACK_IP_LOOKUP_URL, $ip));

            if (!$response->ok()) {
                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? null) !== 'success') {
                return null;
            }

            if (
                !is_numeric($data['lat'] ?? null)
                || !is_numeric($data['lon'] ?? null)
            ) {
                return null;
            }

            return [
                'latitude' => (float) $data['lat'],
                'longitude' => (float) $data['lon'],
                'label' => $this->buildProviderLabel(
                    $data['city'] ?? null,
                    $data['region'] ?? ($data['regionName'] ?? null),
                ),
                'postalCode' => $data['zip'] ?? null,
                'countryCode' => $data['countryCode'] ?? null,
                'provider' => 'ip-api',
            ];
        } catch (\Throwable $e) {
            Log::debug('ip-api IP lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function refineWithGoogle(array $providerLocation): ?array
    {
        if (!$this->googleApiKey) {
            return null;
        }

        $postalRefined = $this->refinePostalLocation(
            $providerLocation['postalCode'] ?? null,
            $providerLocation['countryCode'] ?? null,
        );

        if ($postalRefined) {
            return $postalRefined;
        }

        return $this->refineCoordinateLocation(
            $providerLocation['latitude'],
            $providerLocation['longitude'],
        );
    }

    private function refinePostalLocation(?string $postalCode, ?string $countryCode): ?array
    {
        $postalCode = trim((string) $postalCode);

        if ($postalCode === '') {
            return null;
        }

        $query = [
            'key' => $this->googleApiKey,
            'address' => $countryCode
                ? sprintf('%s, %s', $postalCode, strtoupper($countryCode))
                : $postalCode,
        ];

        if ($countryCode) {
            $query['components'] = sprintf('postal_code:%s|country:%s', $postalCode, strtoupper($countryCode));
        }

        $response = Http::acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->get(self::GEOCODE_API, $query);

        if (!$response->ok()) {
            return null;
        }

        $payload = $response->json();
        $result = $payload['results'][0] ?? null;
        $coords = $result['geometry']['location'] ?? null;

        if (($payload['status'] ?? null) !== 'OK' || !$result || !$coords) {
            return null;
        }

        return [
            'latitude' => (float) $coords['lat'],
            'longitude' => (float) $coords['lng'],
            'location' => $this->formatPostalLocationLabel($result)
                ?: $this->formatLocationLabel($result)
                ?: ($result['formatted_address'] ?? null),
            'postalCode' => $postalCode,
            'provider' => 'google_ip_postal',
        ];
    }

    private function refineCoordinateLocation(float $latitude, float $longitude): ?array
    {
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

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location' => $this->formatCoordinateLocationLabel($results)
                ?: $this->formatLocationLabel($result)
                ?: ($result['formatted_address'] ?? null),
            'postalCode' => $this->findAddressComponent($result['address_components'] ?? [], ['postal_code']),
            'provider' => 'google_ip_reverse',
        ];
    }

    private function formatPostalLocationLabel(array $result): ?string
    {
        $components = $result['address_components'] ?? [];
        $locality = $this->findAddressComponent($components, [
            'sublocality_level_1',
            'sublocality',
            'neighborhood',
            'locality',
            'administrative_area_level_3',
            'administrative_area_level_2',
        ]);
        $state = $this->findAddressComponent($components, ['administrative_area_level_1'], true);

        if ($locality && $state) {
            return sprintf('%s, %s', $locality, $state);
        }

        return $locality ?: null;
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

    private function buildProviderLabel(?string $city, ?string $region): ?string
    {
        $parts = array_values(array_filter([
            is_string($city) ? trim($city) : null,
            is_string($region) ? trim($region) : null,
        ]));

        return count($parts) > 0 ? implode(', ', $parts) : null;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
