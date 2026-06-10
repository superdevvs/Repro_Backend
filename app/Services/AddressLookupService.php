<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AddressLookupService
{
    private $provider;
    private $googleApiKey;
    private $googleBaseUrl = 'https://maps.googleapis.com/maps/api';
    private ZillowPropertyService $zillowPropertyService;

    private $locationIqKey;
    private $locationIqBaseUrl;
    
    private $geoapifyKey;
    private $geoapifyBaseUrl;
    
    private $zillowClientId;
    private $zillowClientSecret;
    private $zillowServerToken;
    private $zillowBaseUrl;

    public function __construct(ZillowPropertyService $zillowPropertyService)
    {
        $this->zillowPropertyService = $zillowPropertyService;
        // Try to get provider from database settings, fallback to config
        $this->provider = $this->getProviderFromSettings() ?? config('services.address.provider', 'google');
        $this->googleApiKey = config('services.google.places_api_key');

        $this->locationIqKey = config('services.locationiq.key');
        $this->locationIqBaseUrl = rtrim(config('services.locationiq.base_url', 'https://api.locationiq.com/v1'), '/');
        
        $this->geoapifyKey = config('services.geoapify.key');
        $this->geoapifyBaseUrl = rtrim(config('services.geoapify.base_url', 'https://api.geoapify.com/v1'), '/');
        
        $this->zillowClientId = config('services.zillow.client_id');
        $this->zillowClientSecret = config('services.zillow.client_secret');
        $this->zillowServerToken = config('services.zillow.server_token');
        $this->zillowBaseUrl = rtrim(config('services.zillow.base_url', 'https://api.bridgedataoutput.com/api/v2'), '/');
    }

    /**
     * Get address provider from database settings
     */
    private function getProviderFromSettings(): ?string
    {
        try {
            $setting = \DB::table('settings')
                ->where('key', 'address_provider')
                ->value('value');
            if ($setting) {
                $decoded = json_decode($setting, true);
                return is_string($decoded) ? $decoded : $setting;
            }
            return null;
        } catch (\Exception $e) {
            // If settings table doesn't exist yet, return null
            return null;
        }
    }

    /**
     * Search for address suggestions using Google Places Autocomplete
     */
    public function searchAddresses(string $query, array $options = []): array
    {
        if (strlen(trim($query)) < 1) {
            return [];
        }

        $cacheKey = 'address_search_' . md5($this->provider . '|' . $query . serialize($options));

        return Cache::remember($cacheKey, 120, function () use ($query, $options) {
            if (!empty($this->googleApiKey)) {
                try {
                    $googleResults = $this->googleAutocomplete($query, $options);
                    if (!empty($googleResults)) {
                        return $googleResults;
                    }
                } catch (\Exception $e) {
                    Log::warning('Google autocomplete failed, falling back to configured provider', [
                        'query' => $query,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                return $this->searchWithConfiguredProvider($query, $options);
            } catch (\Exception $e) {
                Log::warning('Address autocomplete failed', [
                    'provider' => $this->provider,
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                return [];
            }
        });
    }


    /**
     * Get detailed address information by place ID
     */
    public function getAddressDetails(string $placeId): ?array
    {
        $cacheKey = 'address_details_' . $this->provider . '_' . $placeId;
        return Cache::remember($cacheKey, 3600, function () use ($placeId) {
            try {
                if (!empty($this->googleApiKey)) {
                    $googleDetails = $this->googleDetails($placeId);
                    if ($googleDetails) {
                        return $this->mergeWithZillowPropertyDetails($googleDetails);
                    }
                }

                switch ($this->provider) {
                    case 'google':
                        if (empty($this->googleApiKey)) {
                            throw new \Exception('Google Places API key not configured');
                        }
                        return $this->mergeWithZillowPropertyDetails($this->googleDetails($placeId));

                    case 'zillow':
                    default:
                        if (empty($this->zillowServerToken)) {
                            throw new \Exception('Zillow API token not configured');
                        }
                        return $this->zillowDetails($placeId);
                }
            } catch (\Exception $e) {
                Log::error('Address details lookup failed', [
                    'provider' => $this->provider,
                    'place_id' => $placeId,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    private function searchWithConfiguredProvider(string $query, array $options = []): array
    {
        switch ($this->provider) {
            case 'google':
                if (empty($this->googleApiKey)) {
                    throw new \Exception('Google Places API key not configured');
                }
                return $this->googleAutocomplete($query, $options);

            case 'zillow':
            default:
                return $this->zillowAutocomplete($query, $options);
        }
    }

    private function mergeWithZillowPropertyDetails(?array $details): ?array
    {
        if (!$details) {
            return null;
        }

        $zillowDetails = $this->lookupZillowDetailsByAddress($details);
        if (!$zillowDetails) {
            $details['source'] = 'google_places_only';
            $details['confidence'] = 0.35;
            $details['field_sources'] = [
                'formatted_address' => 'google_places',
                'address' => 'google_places',
                'city' => 'google_places',
                'state' => 'google_places',
                'zip' => 'google_places',
                'country' => 'google_places',
                'latitude' => 'google_places',
                'longitude' => 'google_places',
            ];
            $details['property_source_chain'] = ['google_places'];
            return $details;
        }

        $merged = $details;
        foreach (['formatted_address', 'address', 'city', 'state', 'zip', 'country', 'latitude', 'longitude'] as $field) {
            if (empty($merged[$field]) && !empty($zillowDetails[$field])) {
                $merged[$field] = $zillowDetails[$field];
            }
        }

        foreach ([
            'bedrooms',
            'bathrooms',
            'sqft',
            'mls_id',
            'price',
            'lot_size',
            'year_built',
            'property_type',
            'garage_cars',
            'garage_sqft',
            'property_details',
            'raw_parcel_data',
            'raw_assessment_data',
            'raw_legacy_data',
            'manual_override',
            'override_applied',
            'override_fields',
            'zpid',
        ] as $field) {
            if (array_key_exists($field, $zillowDetails) && $zillowDetails[$field] !== null) {
                $merged[$field] = $zillowDetails[$field];
            }
        }

        $merged['source'] = $zillowDetails['source'] ?? 'google_plus_zillow';
        $merged['confidence'] = $zillowDetails['confidence'] ?? 0.8;
        $merged['field_sources'] = array_merge(
            [
                'formatted_address' => 'google_places',
                'address' => 'google_places',
                'city' => 'google_places',
                'state' => 'google_places',
                'zip' => 'google_places',
                'country' => 'google_places',
                'latitude' => 'google_places',
                'longitude' => 'google_places',
            ],
            $zillowDetails['field_sources'] ?? []
        );
        $merged['property_source_chain'] = array_values(array_unique(array_merge(
            ['google_places'],
            $zillowDetails['property_source_chain'] ?? [$merged['source']]
        )));

        return $merged;
    }

    private function lookupZillowDetailsByAddress(array $details): ?array
    {
        if (empty($this->zillowServerToken)) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $details['formatted_address'] ?? null,
            $this->formatAddressForApi($details),
            trim(($details['address'] ?? '') . ', ' . ($details['city'] ?? '') . ', ' . ($details['state'] ?? '') . ' ' . ($details['zip'] ?? '')),
        ])));

        foreach ($candidates as $candidate) {
            $propertyId = $this->findBridgePropertyIdByAddress($candidate);
            if ($propertyId) {
                $zillowDetails = $this->zillowDetails($propertyId);
                if ($zillowDetails) {
                    return $zillowDetails;
                }
            }
        }

        try {
            $addressQuery = $this->formatAddressForApi($details);
            if ($addressQuery) {
                $property = $this->zillowPropertyService->fetchPropertyDetails($addressQuery);
                if ($property) {
                    return [
                        'bedrooms' => isset($property['beds']) ? (float) $property['beds'] : null,
                        'bathrooms' => isset($property['baths']) ? (float) $property['baths'] : null,
                        'sqft' => isset($property['sqft']) ? (int) $property['sqft'] : null,
                        'mls_id' => $property['mls_id'] ?? null,
                        'price' => isset($property['price']) ? (float) $property['price'] : null,
                        'lot_size' => isset($property['lot_size']) ? (int) $property['lot_size'] : null,
                        'year_built' => isset($property['year_built']) ? (int) $property['year_built'] : null,
                        'property_type' => $property['property_type'] ?? null,
                        'garage_cars' => isset($property['garage_cars']) ? (int) $property['garage_cars'] : null,
                        'garage_sqft' => isset($property['garage_sqft']) ? (int) $property['garage_sqft'] : null,
                        'property_details' => array_merge(
                            $property,
                            [
                                'raw_data' => $property['raw_data'] ?? null,
                            ]
                        ),
                        'raw_parcel_data' => $property['raw_parcel'] ?? data_get($property, 'raw_data.parcel'),
                        'raw_assessment_data' => $property['raw_assessment'] ?? data_get($property, 'raw_data.assessment'),
                        'raw_legacy_data' => $property['raw_legacy_lookup'] ?? data_get($property, 'raw_data.legacy_lookup'),
                        'manual_override' => $property['manual_override'] ?? data_get($property, 'raw_data.manual_override'),
                        'override_applied' => (bool) ($property['override_applied'] ?? false),
                        'override_fields' => $property['override_fields'] ?? [],
                        'zpid' => isset($property['zpid']) ? (string) $property['zpid'] : (isset($property['raw_data']['zpid']) ? (string) $property['raw_data']['zpid'] : null),
                        'source' => $property['source'] ?? null,
                        'confidence' => $property['confidence'] ?? null,
                        'field_sources' => $this->mapPropertyFieldSourcesToAddressFields($property['field_sources'] ?? []),
                        'property_source_chain' => $property['property_source_chain'] ?? [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Zillow property fallback failed', [
                'address' => $details,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function mapPropertyFieldSourcesToAddressFields(array $fieldSources): array
    {
        $mapping = [
            'beds' => 'bedrooms',
            'baths' => 'bathrooms',
            'sqft' => 'sqft',
            'mls_id' => 'mls_id',
            'price' => 'price',
            'lot_size' => 'lot_size',
            'year_built' => 'year_built',
            'property_type' => 'property_type',
            'garage_cars' => 'garage_cars',
            'garage_sqft' => 'garage_sqft',
            'zpid' => 'zpid',
        ];

        $mapped = [];
        foreach ($mapping as $propertyField => $addressField) {
            if (!empty($fieldSources[$propertyField])) {
                $mapped[$addressField] = $fieldSources[$propertyField];
            }
        }

        return $mapped;
    }

    private function findBridgePropertyIdByAddress(string $searchAddress): ?string
    {
        $normalizedAddress = trim($searchAddress);
        if ($normalizedAddress === '') {
            return null;
        }

        try {
            $filter = "contains(tolower(UnparsedAddress), '" . str_replace("'", "''", strtolower($normalizedAddress)) . "')";
            $response = Http::withoutVerifying()->get($this->zillowBaseUrl . '/OData/pub/Property', [
                'access_token' => $this->zillowServerToken,
                '$filter' => $filter,
                '$top' => 1,
                '$select' => 'ListingKey,UnparsedAddress',
            ]);

            if ($response->successful()) {
                $results = $response->json('value', []);
                $firstResult = $results[0] ?? null;
                if ($firstResult) {
                    $listingKey = $firstResult['ListingKey'] ?? null;
                    if ($listingKey) {
                        return (string) $listingKey;
                    }

                    $odataId = data_get($firstResult, '@odata.id');
                    if (is_string($odataId) && str_contains($odataId, '/')) {
                        return (string) collect(explode('/', trim($odataId, '/')))->last();
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Bridge RESO address search failed', [
                'address' => $normalizedAddress,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $response = Http::withoutVerifying()->get($this->zillowBaseUrl . '/pub/parcels', [
                'access_token' => $this->zillowServerToken,
                'address.full' => $normalizedAddress,
                'limit' => 1,
            ]);

            if ($response->successful()) {
                $bundle = $response->json('bundle', []);
                $firstParcel = $bundle[0] ?? null;
                if ($firstParcel && !empty($firstParcel['id'])) {
                    return (string) $firstParcel['id'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Bridge parcel search failed', [
                'address' => $normalizedAddress,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Validate and standardize an address
     */
    public function validateAddress(array $addressData): array
    {
        $validation = [
            'is_valid' => true,
            'errors' => [],
            'suggestions' => [],
            'standardized' => $addressData
        ];

        // Required fields
        $required = ['address', 'city', 'state', 'zip'];
        foreach ($required as $field) {
            if (empty($addressData[$field])) {
                $validation['is_valid'] = false;
                $validation['errors'][] = "Missing required field: {$field}";
            }
        }

        // Validate ZIP code format
        if (!empty($addressData['zip'])) {
            if (!preg_match('/^\d{5}(-\d{4})?$/', $addressData['zip'])) {
                $validation['is_valid'] = false;
                $validation['errors'][] = 'Invalid ZIP code format';
            }
        }

        // Validate state (2-letter code)
        if (!empty($addressData['state'])) {
            if (strlen($addressData['state']) !== 2) {
                $validation['is_valid'] = false;
                $validation['errors'][] = 'State must be 2-letter code (e.g., CA, NY)';
            }
        }

        // If we have a place_id, get standardized address
        if (!empty($addressData['place_id'])) {
            $details = $this->getAddressDetails($addressData['place_id']);
            if ($details) {
                $validation['standardized'] = array_merge($addressData, $details);
            }
        }

        return $validation;
    }

    /**
     * Resolve an exact property address to coordinates using the shared,
     * server-side geocode cache.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function geocodeAddress(array $address): ?array
    {
        $coordinates = $this->geocodeWithCache($address);
        if ($coordinates) {
            return $coordinates;
        }

        // Test/imported street names are not always known to the provider.
        // Fall back to the locality so the listing can still participate in
        // area-based map browsing instead of remaining permanently unmapped.
        $locality = $address;
        unset($locality['address']);

        return $this->formatAddressForApi($locality)
            ? $this->geocodeWithCache($locality)
            : null;
    }

    /**
     * Format address suggestions for frontend
     */
    private function formatAddressSuggestions(array $predictions): array
    {
        return array_map(function ($prediction) {
            return [
                'place_id' => $prediction['place_id'],
                'description' => $prediction['description'],
                'main_text' => $prediction['structured_formatting']['main_text'] ?? '',
                'secondary_text' => $prediction['structured_formatting']['secondary_text'] ?? '',
                'types' => $prediction['types'] ?? []
            ];
        }, $predictions);
    }

    /**
     * Parse Google Places address components into our format
     */
    private function parseAddressComponents(array $placeData): array
    {
        $components = $placeData['address_components'] ?? [];
        $parsed = [
            'formatted_address' => $placeData['formatted_address'] ?? '',
            'address' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
            'country' => '',
            'latitude' => null,
            'longitude' => null
        ];

        // Extract coordinates
        if (isset($placeData['geometry']['location'])) {
            $parsed['latitude'] = $placeData['geometry']['location']['lat'];
            $parsed['longitude'] = $placeData['geometry']['location']['lng'];
        }

        // Parse address components
        foreach ($components as $component) {
            $types = $component['types'];
            $longName = $component['long_name'];
            $shortName = $component['short_name'];

            if (in_array('street_number', $types)) {
                $parsed['street_number'] = $longName;
            } elseif (in_array('route', $types)) {
                $parsed['street_name'] = $longName;
            } elseif (in_array('locality', $types)) {
                $parsed['city'] = $longName;
            } elseif (in_array('administrative_area_level_1', $types)) {
                $parsed['state'] = $shortName;
            } elseif (in_array('postal_code', $types)) {
                $parsed['zip'] = $longName;
            } elseif (in_array('country', $types)) {
                $parsed['country'] = $shortName;
            }
        }

        // Combine street number and name
        if (!empty($parsed['street_number']) && !empty($parsed['street_name'])) {
            $parsed['address'] = $parsed['street_number'] . ' ' . $parsed['street_name'];
        } elseif (!empty($parsed['street_name'])) {
            $parsed['address'] = $parsed['street_name'];
        }

        return $parsed;
    }

    /**
     * Get distance between two addresses
     */
    public function getDistance(array $origin, array $destination): ?array
    {
        // Skip external API calls in local dev for performance
        if (env('GEOCODING_ENABLED', true) === false) {
            return null;
        }

        // Cache distance calculations to avoid repeated API calls
        $cacheKey = 'distance_' . md5(json_encode($origin) . json_encode($destination));
        
        return Cache::remember($cacheKey, 3600, function () use ($origin, $destination) {
            return $this->calculateDistance($origin, $destination);
        });
    }

    /**
     * Actually calculate distance (called by getDistance with caching)
     */
    private function calculateDistance(array $origin, array $destination): ?array
    {
        if (empty($this->googleApiKey)) {
            return $this->approxDistanceFromAddresses($origin, $destination);
        }

        try {
            $originStr = $this->formatAddressForApi($origin);
            $destinationStr = $this->formatAddressForApi($destination);

            $params = [
                'origins' => $originStr,
                'destinations' => $destinationStr,
                'key' => $this->googleApiKey,
                'units' => 'imperial'
            ];

            $response = Http::get($this->googleBaseUrl . '/distancematrix/json', $params);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            if ($data['status'] !== 'OK' || empty($data['rows'][0]['elements'][0])) {
                return null;
            }

            $element = $data['rows'][0]['elements'][0];

            if ($element['status'] !== 'OK') {
                return null;
            }

            return [
                'distance' => $element['distance']['text'],
                'distance_value' => $element['distance']['value'], // in meters
                'duration' => $element['duration']['text'],
                'duration_value' => $element['duration']['value'] // in seconds
            ];

        } catch (\Exception $e) {
            Log::error('Distance calculation failed', [
                'origin' => $origin,
                'destination' => $destination,
                'error' => $e->getMessage()
            ]);
            // Fallback: approximate great-circle distance (no routing)
            return $this->approxDistanceFromAddresses($origin, $destination)
                ?? $this->approxDistanceByCoordinates($origin, $destination);
        }
    }

    /**
     * Geocode with caching to avoid repeated external calls
     */
    private function geocodeWithCache(array $address): ?array
    {
        $cacheKey = 'geocode_' . md5(json_encode($address));
        
        return Cache::remember($cacheKey, 86400, function () use ($address) {
            return $this->geocodeNominatim($address);
        });
    }

    private function approxDistanceFromAddresses(array $origin, array $destination): ?array
    {
        $originCoords = $this->geocodeWithCache($origin);
        $destinationCoords = $this->geocodeWithCache($destination);
        if (!$originCoords || !$destinationCoords) {
            return null;
        }

        return $this->approxDistanceByCoordinates(
            ['latitude' => $originCoords['latitude'], 'longitude' => $originCoords['longitude']],
            ['latitude' => $destinationCoords['latitude'], 'longitude' => $destinationCoords['longitude']]
        );
    }

    private function geocodeNominatim(array $address): ?array
    {
        $query = $this->formatAddressForApi($address);
        if (!$query) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'REPRO Dashboard App/1.0 (contact@repro.com)'
            ])->timeout(5)->get('https://nominatim.openstreetmap.org/search', [
                'format' => 'json',
                'q' => $query,
                'limit' => 1,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            if (!is_array($data) || empty($data)) {
                return null;
            }

            $result = $data[0] ?? null;
            if (!$result || !isset($result['lat'], $result['lon'])) {
                return null;
            }

            return [
                'latitude' => (float) $result['lat'],
                'longitude' => (float) $result['lon'],
            ];
        } catch (\Throwable $e) {
            Log::warning('Nominatim geocode failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function googleAutocomplete(string $query, array $options): array
    {
        $params = [
            'input' => $query,
            'key' => $this->googleApiKey,
            'types' => 'address',
            'components' => 'country:us',
        ];
        if (isset($options['location'])) $params['location'] = $options['location'];
        if (isset($options['radius'])) $params['radius'] = $options['radius'];

        $response = Http::get($this->googleBaseUrl . '/place/autocomplete/json', $params);
        if (!$response->successful()) {
            Log::error('Google Places API error', ['status' => $response->status(), 'body' => $response->body()]);
            return [];
        }
        $data = $response->json();
        if (($data['status'] ?? '') !== 'OK') {
            Log::warning('Google Places API warning', ['status' => $data['status'] ?? 'unknown', 'error_message' => $data['error_message'] ?? null]);
            return [];
        }
        return $this->formatAddressSuggestions($data['predictions'] ?? []);
    }

    private function googleDetails(string $placeId): ?array
    {
        $params = [
            'place_id' => $placeId,
            'key' => $this->googleApiKey,
            'fields' => 'address_components,formatted_address,geometry,name,types'
        ];
        $response = Http::get($this->googleBaseUrl . '/place/details/json', $params);
        if (!$response->successful()) {
            Log::error('Google Places Details API error', ['place_id' => $placeId, 'status' => $response->status(), 'body' => $response->body()]);
            return null;
        }
        $data = $response->json();
        if (($data['status'] ?? '') !== 'OK') {
            Log::warning('Google Places Details API warning', ['place_id' => $placeId, 'status' => $data['status'] ?? 'unknown', 'error_message' => $data['error_message'] ?? null]);
            return null;
        }
        return $this->parseAddressComponents($data['result'] ?? []);
    }

    private function locationIqAutocomplete(string $query, array $options): array
    {
        if (empty($this->locationIqKey)) {
            Log::error('LocationIQ API key not configured');
            throw new \Exception('LocationIQ API key not configured. Please set LOCATIONIQ_API_KEY in your .env file.');
        }

        $params = [
            'key' => $this->locationIqKey,
            'q' => $query,
            'limit' => 8,
            'dedupe' => 1,
            'countrycodes' => 'us',
            'format' => 'json',
        ];

        // Try different LocationIQ endpoints
        $endpoints = [
            $this->locationIqBaseUrl . '/autocomplete',
            $this->locationIqBaseUrl . '/autocomplete.php',
            'https://us1.locationiq.com/v1/autocomplete',
            'https://api.locationiq.com/v1/autocomplete',
        ];

        $lastError = null;
        foreach ($endpoints as $url) {
            try {
                $response = Http::withOptions(['verify' => false])->get($url, $params);

                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data) && !empty($data)) {
                        // Success - process and return
                        break;
                    }
                } else {
                    $lastError = [
                        'url' => $url,
                        'status' => $response->status(),
                        'body' => substr($response->body(), 0, 200),
                    ];
                    Log::warning('LocationIQ endpoint failed', $lastError);
                    continue; // Try next endpoint
                }
            } catch (\Exception $e) {
                $lastError = [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ];
                Log::warning('LocationIQ request exception', $lastError);
                continue; // Try next endpoint
            }
        }

        if (!isset($response) || !$response->successful()) {
            Log::error('LocationIQ autocomplete error - all endpoints failed', [
                'query' => $query,
                'last_error' => $lastError,
            ]);
            throw new \Exception('LocationIQ autocomplete failed. Please check your API key and network connection.');
        }

        $items = $response->json();

        if (!is_array($items) || empty($items)) {
            Log::warning('LocationIQ returned empty result', [
                'query' => $query,
                'response' => substr($response->body(), 0, 500),
            ]);
            return [];
        }

        // Include both parsed and raw data in each item
        return array_map(function ($it) {
            $addr = $it['address'] ?? [];
            $display = $it['display_name'] ?? '';
            $main = trim(($addr['house_number'] ?? '') . ' ' . ($addr['road'] ?? ''));
            $city = $addr['city'] ?? ($addr['town'] ?? ($addr['village'] ?? ''));
            $state = $addr['state'] ?? '';
            $zip = $addr['postcode'] ?? '';
            $country = $addr['country_code'] ?? 'US';
            $secondary = trim(join(', ', array_filter([$city, $state, $zip])));

            return [
                'place_id' => (string)($it['place_id'] ?? ''),
                'description' => $display,
                'main_text' => $main ?: ($addr['neighbourhood'] ?? $display),
                'secondary_text' => $secondary,
                'types' => [$it['class'] ?? 'address'],
                'latitude' => isset($it['lat']) ? (float)$it['lat'] : null,
                'longitude' => isset($it['lon']) ? (float)$it['lon'] : null,
                'importance' => $it['importance'] ?? null,
                'osm_id' => $it['osm_id'] ?? null,
                'osm_type' => $it['osm_type'] ?? null,
                'address' => $main,
                'city' => $city,
                'state' => $state,
                'zip' => $zip,
                'country' => $country,
                'formatted_address' => $display,
                'raw' => $it,
                'source' => 'locationiq', // Mark source for deduplication
            ];
        }, $items);
    }



    /**
     * Geoapify Autocomplete API
     */
    private function geoapifyAutocomplete(string $query, array $options): array
    {
        if (empty($this->geoapifyKey)) {
            Log::error('Geoapify API key not configured');
            throw new \Exception('Geoapify API key not configured. Please set GEOAPIFY_API_KEY in your .env file.');
        }

        $params = [
            'apiKey' => $this->geoapifyKey,
            'text' => $query,
            'limit' => 8,
            'filter' => 'countrycode:us',
            'format' => 'json',
        ];

        // Geoapify autocomplete endpoint - try multiple variations
        $endpoints = [
            $this->geoapifyBaseUrl . '/geocode/autocomplete',
            'https://api.geoapify.com/v1/geocode/autocomplete',
            'https://api.geoapify.com/v1/geocode/search',
        ];

        $response = null;
        $lastError = null;
        
        foreach ($endpoints as $url) {
            try {
                $response = Http::withOptions(['verify' => false])->get($url, $params);
                
                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['features']) || isset($data['results'])) {
                        break; // Success
                    }
                } else {
                    $lastError = [
                        'url' => $url,
                        'status' => $response->status(),
                        'body' => substr($response->body(), 0, 200),
                    ];
                    Log::warning('Geoapify endpoint failed', $lastError);
                    continue;
                }
            } catch (\Exception $e) {
                $lastError = [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ];
                Log::warning('Geoapify request exception', $lastError);
                continue;
            }
        }

        try {
            if (!$response || !$response->successful()) {
                Log::error('Geoapify autocomplete error - all endpoints failed', [
                    'params' => $params,
                    'last_error' => $lastError,
                ]);
                throw new \Exception('Geoapify autocomplete failed. Please check your API key and network connection.');
            }

            $data = $response->json();
            $features = $data['features'] ?? $data['results'] ?? [];

            if (empty($features) || !is_array($features)) {
                Log::warning('Geoapify returned empty result', [
                    'query' => $query,
                    'response' => substr($response->body(), 0, 500),
                ]);
                return [];
            }

            // Map Geoapify results to our standard format
            return array_map(function ($feature) {
                $props = $feature['properties'] ?? [];
                $geometry = $feature['geometry'] ?? [];
                $coords = $geometry['coordinates'] ?? [];
                
                // Extract address components
                $streetNumber = $props['housenumber'] ?? '';
                $streetName = $props['street'] ?? '';
                $main = trim($streetNumber . ' ' . $streetName) ?: $props['name'] ?? '';
                $city = $props['city'] ?? $props['town'] ?? $props['village'] ?? '';
                $state = $props['state'] ?? $props['state_code'] ?? '';
                $zip = $props['postcode'] ?? '';
                $country = $props['country'] ?? 'US';
                
                $secondary = trim(implode(', ', array_filter([$city, $state, $zip])));
                $description = trim(implode(', ', array_filter([$main, $city, $state, $zip]))) ?: $props['formatted'] ?? '';

                // Use Geoapify place_id or generate one
                $placeId = (string)($props['place_id'] ?? $props['osm_id'] ?? uniqid('geoapify_'));

                return [
                    'place_id' => $placeId,
                    'description' => $description,
                    'main_text' => $main ?: $props['name'] ?? '',
                    'secondary_text' => $secondary,
                    'types' => $props['result_type'] ? [$props['result_type']] : ['address'],
                    'latitude' => !empty($coords[1]) ? (float)$coords[1] : null,
                    'longitude' => !empty($coords[0]) ? (float)$coords[0] : null,
                    'address' => $main,
                    'city' => $city,
                    'state' => $state,
                    'zip' => $zip,
                    'country' => $country,
                    'formatted_address' => $props['formatted'] ?? $description,
                    'raw' => $feature,
                    'source' => 'geoapify', // Mark source for deduplication
                ];
            }, $features);
        } catch (\Exception $e) {
            Log::error('Geoapify autocomplete exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Merge and deduplicate results from multiple providers
     */
    private function mergeAndDeduplicateResults(array $zillowResults, array $locationIqResults, array $geoapifyResults, string $query): array
    {
        $merged = [];
        $seen = []; // Track seen addresses to avoid duplicates
        
        // Combine all results (sources are already marked in their respective methods)
        // Prioritize Zillow results first, then LocationIQ, then Geoapify
        $allResults = array_merge($zillowResults, $locationIqResults, $geoapifyResults);
        
        // Deduplicate based on address similarity
        foreach ($allResults as $result) {
            $key = $this->generateDeduplicationKey($result);
            
            // If we haven't seen this address, add it
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $merged[] = $result;
            } else {
                // If duplicate, prefer the one with more complete data
                $existingIndex = $this->findSimilarResult($merged, $result);
                if ($existingIndex !== false) {
                    $existing = $merged[$existingIndex];
                    // Keep the result with more complete information
                    if ($this->isMoreComplete($result, $existing)) {
                        $merged[$existingIndex] = $result;
                    }
                }
            }
        }
        
        // Sort by relevance (prefer exact matches, then by completeness)
        usort($merged, function ($a, $b) use ($query) {
            $aScore = $this->calculateRelevanceScore($a, $query);
            $bScore = $this->calculateRelevanceScore($b, $query);
            return $bScore <=> $aScore; // Descending order
        });
        
        // Limit to 8 results
        return array_slice($merged, 0, 8);
    }
    
    /**
     * Generate a key for deduplication
     */
    private function generateDeduplicationKey(array $result): string
    {
        $main = strtolower(trim($result['main_text'] ?? ''));
        $city = strtolower(trim($result['city'] ?? ''));
        $state = strtolower(trim($result['state'] ?? ''));
        $zip = strtolower(trim($result['zip'] ?? ''));
        
        // Create a normalized key
        return md5($main . '|' . $city . '|' . $state . '|' . $zip);
    }
    
    /**
     * Find similar result in array
     */
    private function findSimilarResult(array $results, array $target): int|false
    {
        $targetKey = $this->generateDeduplicationKey($target);
        
        foreach ($results as $index => $result) {
            $resultKey = $this->generateDeduplicationKey($result);
            if ($resultKey === $targetKey) {
                return $index;
            }
        }
        
        return false;
    }
    
    /**
     * Check if result A is more complete than result B
     */
    private function isMoreComplete(array $a, array $b): bool
    {
        $aScore = 0;
        $bScore = 0;
        
        // Count non-empty fields
        $fields = ['main_text', 'city', 'state', 'zip', 'latitude', 'longitude'];
        foreach ($fields as $field) {
            if (!empty($a[$field])) $aScore++;
            if (!empty($b[$field])) $bScore++;
        }
        
        return $aScore > $bScore;
    }
    
    /**
     * Calculate relevance score for sorting
     */
    private function calculateRelevanceScore(array $result, string $query): int
    {
        $score = 0;
        $queryLower = strtolower($query);
        
        $mainText = strtolower($result['main_text'] ?? '');
        $description = strtolower($result['description'] ?? '');
        $source = $result['source'] ?? '';
        
        // Prioritize Zillow results (they're often more accurate for US addresses)
        if ($source === 'zillow') {
            $score += 15;
        } elseif ($source === 'locationiq') {
            $score += 5;
        } elseif ($source === 'geoapify') {
            $score += 3;
        }
        
        // Exact match in main text
        if (strpos($mainText, $queryLower) !== false) {
            $score += 10;
        }
        
        // Exact match in description
        if (strpos($description, $queryLower) !== false) {
            $score += 5;
        }
        
        // Completeness bonus
        if (!empty($result['city'])) $score += 2;
        if (!empty($result['state'])) $score += 2;
        if (!empty($result['zip'])) $score += 2;
        if (!empty($result['latitude']) && !empty($result['longitude'])) $score += 1;
        
        // Bonus for having ZPID (Zillow Property ID) - indicates verified property
        if (!empty($result['zpid'])) {
            $score += 5;
        }
        
        return $score;
    }

    private function locationIqDetails(string $placeId): ?array
    {
        // Try OSM-style lookup first (N/W/R prefix + osm_id)
        $osmPrefixes = ['W', 'N', 'R'];
        foreach ($osmPrefixes as $prefix) {
            try {
                $params = [
                    'key' => $this->locationIqKey,
                    'osm_type' => $prefix,
                    'osm_id' => $placeId,
                    'format' => 'json',
                    'addressdetails' => 1,
                ];
                $response = Http::withOptions(['verify' => false])
                    ->get($this->locationIqBaseUrl . '/reverse', $params);
                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data) && !empty($data['address'] ?? null)) {
                        return $this->parseLocationIqAddress($data);
                    }
                }
            } catch (\Exception $e) {
                // Try next prefix
            }
        }

        // Try the place_id lookup endpoint as a fallback
        $params = [
            'key' => $this->locationIqKey,
            'place_id' => $placeId,
            'format' => 'json',
        ];
        $urls = [
            $this->locationIqBaseUrl . '/lookup',
            $this->locationIqBaseUrl . '/lookup.php',
        ];
        foreach ($urls as $url) {
            try {
                $response = Http::withOptions(['verify' => false])->get($url, $params);
                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data[0])) $data = $data[0];
                    if (is_array($data) && !empty($data['address'] ?? null)) {
                        return $this->parseLocationIqAddress($data);
                    }
                }
            } catch (\Exception $e) {
                // Try next url
            }
        }

        Log::warning('LocationIQ details lookup exhausted all methods', [
            'place_id' => $placeId,
        ]);

        return null;
    }


    private function parseLocationIqAddress(array $data): array
    {
        $addr = $data['address'] ?? [];
        $streetNumber = $addr['house_number'] ?? '';
        $streetName = $addr['road'] ?? ($addr['pedestrian'] ?? ($addr['path'] ?? ''));
        $address = trim(trim($streetNumber . ' ' . $streetName));

        return [
            'formatted_address' => $data['display_name'] ?? $address,
            'address' => $address,
            'city' => $addr['city'] ?? ($addr['town'] ?? ($addr['village'] ?? ($addr['hamlet'] ?? ''))),
            'state' => $addr['state'] ?? '',
            'zip' => $addr['postcode'] ?? '',
            'country' => $addr['country_code'] ?? '',
            'latitude' => isset($data['lat']) ? (float)$data['lat'] : null,
            'longitude' => isset($data['lon']) ? (float)$data['lon'] : null,
        ];
    }

    private function approxDistanceByCoordinates(array $origin, array $destination): ?array
    {
        // If coordinates exist in arrays, use haversine; otherwise, return null
        $olat = $origin['latitude'] ?? null;
        $olon = $origin['longitude'] ?? null;
        $dlat = $destination['latitude'] ?? null;
        $dlon = $destination['longitude'] ?? null;
        if ($olat === null || $olon === null || $dlat === null || $dlon === null) {
            return null;
        }
        $earth = 6371000; // meters
        $toRad = function ($deg) { return $deg * M_PI / 180; };
        $dPhi = $toRad($dlat - $olat);
        $dLam = $toRad($dlon - $olon);
        $phi1 = $toRad($olat);
        $phi2 = $toRad($dlat);
        $a = sin($dPhi/2)**2 + cos($phi1)*cos($phi2)*sin($dLam/2)**2;
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $meters = $earth * $c;
        // Rough driving time assuming 35 mph average (~15.6 m/s)
        $seconds = $meters / 15.6;
        return [
            'distance' => round($meters/1609.34, 2) . ' mi',
            'distance_value' => (int)$meters,
            'duration' => gmdate('H\h i\m', (int)$seconds),
            'duration_value' => (int)$seconds,
        ];
    }

    /**
     * Format address array for API calls
     */
    private function formatAddressForApi(array $address): string
    {
        $parts = [];
        
        if (!empty($address['address'])) {
            $parts[] = $address['address'];
        }
        if (!empty($address['city'])) {
            $parts[] = $address['city'];
        }
        if (!empty($address['state'])) {
            $parts[] = $address['state'];
        }
        if (!empty($address['zip'])) {
            $parts[] = $address['zip'];
        }

        return implode(', ', $parts);
    }

    /**
     * Nominatim (OpenStreetMap) - Free, open-source, no API key required
     */
    private function nominatimAutocomplete(string $query, array $options): array
    {
        $params = [
            'q' => $query,
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => 8,
            'countrycodes' => 'us',
            'dedupe' => 1,
        ];

        // Use official Nominatim instance (please use responsibly - see usage policy)
        $url = 'https://nominatim.openstreetmap.org/search';
        $response = Http::withHeaders([
            'User-Agent' => config('app.name', 'REPRO') . ' Address Lookup'
        ])->get($url, $params);

        if (!$response->successful()) {
            Log::error('Nominatim autocomplete error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        $items = $response->json();
        if (!is_array($items)) {
            return [];
        }

        return array_map(function ($item) {
            $addr = $item['address'] ?? [];
            $streetNumber = $addr['house_number'] ?? '';
            $streetName = $addr['road'] ?? '';
            $main = trim($streetNumber . ' ' . $streetName) ?: ($addr['neighbourhood'] ?? $item['display_name']);
            $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? '';
            $state = $addr['state'] ?? '';
            $zip = $addr['postcode'] ?? '';
            $secondary = trim(implode(', ', array_filter([$city, $state, $zip])));

            return [
                'place_id' => (string)$item['place_id'],
                'description' => $item['display_name'],
                'main_text' => $main,
                'secondary_text' => $secondary,
                'types' => [$item['type'] ?? 'address'],
                'raw' => $item,
            ];
        }, $items);
    }

    private function nominatimDetails(string $placeId): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/lookup';
        $response = Http::withHeaders([
            'User-Agent' => config('app.name', 'REPRO') . ' Address Lookup'
        ])->get($url, [
            'osm_ids' => $placeId,
            'format' => 'json',
            'addressdetails' => 1,
        ]);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        if (empty($data) || !is_array($data)) {
            return null;
        }

        $item = $data[0] ?? null;
        if (!$item) {
            return null;
        }

        return $this->parseLocationIqAddress($item); // Same format as LocationIQ
    }

    /**
     * Photon (Komoot) - Free, open-source, no API key required
     */
    private function photonAutocomplete(string $query, array $options): array
    {
        $params = [
            'q' => $query,
            'limit' => 8,
            'lang' => 'en',
            'osm_tag' => 'place:city,place:town,place:village,place:house',
        ];

        $url = 'https://photon.komoot.io/api/';
        $response = Http::get($url, $params);

        if (!$response->successful()) {
            Log::error('Photon autocomplete error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        $data = $response->json();
        $features = $data['features'] ?? [];

        return array_map(function ($feature) {
            $props = $feature['properties'] ?? [];
            $geometry = $feature['geometry'] ?? [];
            $coords = $geometry['coordinates'] ?? [];

            $streetNumber = $props['housenumber'] ?? '';
            $streetName = $props['street'] ?? '';
            $main = trim($streetNumber . ' ' . $streetName) ?: ($props['name'] ?? '');
            $city = $props['city'] ?? $props['town'] ?? '';
            $state = $props['state'] ?? '';
            $zip = $props['postcode'] ?? '';
            $secondary = trim(implode(', ', array_filter([$city, $state, $zip])));
            $description = trim(implode(', ', array_filter([$main, $city, $state, $zip])));

            return [
                'place_id' => (string)($props['osm_id'] ?? $props['place_id'] ?? uniqid('photon_')),
                'description' => $description,
                'main_text' => $main,
                'secondary_text' => $secondary,
                'types' => [$props['osm_type'] ?? 'address'],
                'latitude' => !empty($coords[1]) ? (float)$coords[1] : null,
                'longitude' => !empty($coords[0]) ? (float)$coords[0] : null,
                'raw' => $feature,
            ];
        }, $features);
    }

    private function photonDetails(string $placeId): ?array
    {
        // Photon uses OSM ID format, try reverse geocoding or lookup
        // For simplicity, we'll use the place_id to search again
        $url = 'https://photon.komoot.io/api/';
        $response = Http::get($url, [
            'osm_id' => $placeId,
            'limit' => 1,
        ]);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        $features = $data['features'] ?? [];
        if (empty($features)) {
            return null;
        }

        $feature = $features[0];
        $props = $feature['properties'] ?? [];
        $geometry = $feature['geometry'] ?? [];
        $coords = $geometry['coordinates'] ?? [];

        $streetNumber = $props['housenumber'] ?? '';
        $streetName = $props['street'] ?? '';
        $address = trim($streetNumber . ' ' . $streetName);

        return [
            'formatted_address' => $props['name'] ?? $address,
            'address' => $address,
            'city' => $props['city'] ?? $props['town'] ?? '',
            'state' => $props['state'] ?? '',
            'zip' => $props['postcode'] ?? '',
            'country' => $props['countrycode'] ?? 'US',
            'latitude' => !empty($coords[1]) ? (float)$coords[1] : null,
            'longitude' => !empty($coords[0]) ? (float)$coords[0] : null,
        ];
    }

    /**
     * Zillow Public Autocomplete API
     * Uses Zillow's public autocomplete endpoint (no auth required)
     */
    private function zillowAutocomplete(string $query, array $options): array
    {
        // Minimum query length
        if (strlen(trim($query)) < 3) {
            return [];
        }

        $params = [
            'q' => $query,
            'resultCount' => 10,
            'resultTypes' => 'allAddress',
        ];

        // Zillow public autocomplete endpoint (no authentication needed)
        $url = 'https://www.zillowstatic.com/autocomplete/v3/suggestions';

        try {
            $response = Http::withOptions(['verify' => false])->get($url, $params);

            if (!$response->successful()) {
                Log::warning('Zillow autocomplete error', [
                    'url' => $url,
                    'params' => $params,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return [];
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            if (empty($results) || !is_array($results)) {
                return [];
            }

            // Map Zillow results to our standard format
            return array_map(function ($item) {
                $display = $item['display'] ?? '';
                $metaData = $item['metaData'] ?? [];
                
                // Parse address components from display string
                // Format is typically: "123 Main St, City, State ZIP"
                $parts = explode(',', $display);
                $main = trim($parts[0] ?? '');
                $city = $metaData['city'] ?? trim($parts[1] ?? '');

                // State/ZIP may be in either the metadata or the final comma section
                $stateZipSource = $metaData['state'] ?? trim($parts[2] ?? ($parts[1] ?? ''));
                $zipFromMeta = $metaData['zip'] ?? $metaData['zipcode'] ?? $metaData['zipCode'] ?? null;
                
                // Extract state and zip from "State ZIP" format
                $state = $metaData['state'] ?? '';
                $zip = $zipFromMeta ?? '';
                if (!$state || !$zip) {
                    $stateZipParts = preg_split('/\s+/', trim((string)$stateZipSource), 2);
                    $state = $state ?: ($stateZipParts[0] ?? '');
                    $zip = $zip ?: ($stateZipParts[1] ?? '');
                }
                
                $secondary = trim(implode(', ', array_filter([$city, $state, $zip])));
                
                // Use Zillow Property ID (zpid) if available, otherwise generate one
                $zpid = $metaData['zpid'] ?? null;
                $placeId = $zpid ? (string)$zpid : uniqid('zillow_');

                return [
                    'place_id' => $placeId,
                    'description' => $display,
                    'main_text' => $main,
                    'secondary_text' => $secondary,
                    'types' => ['address'],
                    'latitude' => isset($metaData['lat']) ? (float)$metaData['lat'] : null,
                    'longitude' => isset($metaData['lng']) ? (float)$metaData['lng'] : null,
                    'address' => $main,
                    'city' => $city,
                    'state' => $state,
                    'zip' => $zip,
                    'country' => 'US',
                    'formatted_address' => $display,
                    'raw' => $item,
                    'source' => 'zillow',
                    'zpid' => $zpid, // Store Zillow Property ID for later use
                ];
            }, $results);
        } catch (\Exception $e) {
            Log::error('Zillow autocomplete exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function getAreaSquareFeet(array $area): ?int
    {
        if (!isset($area['areaSquareFeet']) || $area['areaSquareFeet'] === null) {
            return null;
        }

        $value = (int) round((float) $area['areaSquareFeet']);

        return $value > 0 ? $value : null;
    }

    private function getPreferredFinishedSqft(array $areas): ?int
    {
        if (empty($areas)) {
            return null;
        }

        $areaTypePriority = [
            'Living Building Area',
            'Finished Building Area',
            'Zillow Calculated Finished Area',
            'Base Building Area',
            'Gross Building Area',
        ];

        $supplementalFinishedAreaTypes = [
            'Basement Finished',
            'Game Room/Recreation',
            'Lower Level Finished',
            'Finished Basement',
            'Basement Partially Finished',
            'Finished Rec Room',
        ];

        $primarySqft = null;
        $primaryType = null;

        foreach ($areaTypePriority as $type) {
            $typeValues = [];

            foreach ($areas as $area) {
                if (($area['type'] ?? null) !== $type) {
                    continue;
                }

                $sqft = $this->getAreaSquareFeet($area);
                if ($sqft !== null) {
                    $typeValues[] = $sqft;
                }
            }

            if (!empty($typeValues)) {
                $primarySqft = max($typeValues);
                $primaryType = $type;
                break;
            }
        }

        if ($primarySqft === null) {
            return null;
        }

        if ($primaryType !== 'Living Building Area') {
            return $primarySqft;
        }

        $supplementalSqft = 0;
        foreach ($areas as $area) {
            if (!in_array($area['type'] ?? null, $supplementalFinishedAreaTypes, true)) {
                continue;
            }

            $sqft = $this->getAreaSquareFeet($area);
            if ($sqft !== null) {
                $supplementalSqft += $sqft;
            }
        }

        if ($supplementalSqft > 0) {
            return $primarySqft + $supplementalSqft;
        }

        $hasFinishedAreaMarker = false;
        foreach ($areas as $area) {
            $type = (string) ($area['type'] ?? '');
            if (str_ends_with($type, ' Finished') || str_starts_with($type, 'Finished ')) {
                $hasFinishedAreaMarker = true;
                break;
            }
        }

        if (!$hasFinishedAreaMarker) {
            return $primarySqft;
        }

        $basementCandidates = [];
        foreach ($areas as $area) {
            if (($area['type'] ?? null) !== 'Basement') {
                continue;
            }

            $sqft = $this->getAreaSquareFeet($area);
            if ($sqft !== null) {
                $basementCandidates[] = $sqft;
            }
        }

        if (empty($basementCandidates)) {
            return $primarySqft;
        }

        return $primarySqft + max($basementCandidates);
    }

    private function zillowDetails(string $placeId): ?array
    {
        // Bridge Data API endpoint for parcel details
        $url = $this->zillowBaseUrl . '/pub/parcels/' . $placeId;
        $params = [
            'access_token' => $this->zillowServerToken,
            'fields' => 'areas,building,garages',
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->zillowServerToken,
                'Accept' => 'application/json',
            ])->withoutVerifying()->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();
                $property = $data['bundle'] ?? $data['data'] ?? $data['property'] ?? $data;
                
                if (!is_array($property)) {
                    Log::error('Bridge Data parcel lookup failed - invalid response format', [
                        'place_id' => $placeId,
                        'response' => $data,
                    ]);
                    return null;
                }

                $address = $property['address'] ?? $property;
                $streetNumber = $address['streetNumber'] ?? $address['houseNumber'] ?? $address['street_number'] ?? '';
                $streetName = $address['streetName'] ?? $address['street'] ?? $address['street_name'] ?? '';
                $addressLine = trim($streetNumber . ' ' . $streetName);
                
                // Extract property metrics from areas and building data
                $areas = $property['areas'] ?? [];
                $building = $property['building'] ?? [];
                
                // Get bedrooms from building data
                $bedrooms = null;
                if (!empty($building) && isset($building[0]['bedrooms'])) {
                    $bedrooms = $building[0]['bedrooms'];
                }
                
                // Calculate bathrooms from full and half baths, with fallback to simple baths field
                $bathrooms = null;
                if (!empty($building) && isset($building[0])) {
                    // Try detailed breakdown first (common for single-family homes)
                    $fullBaths = $building[0]['fullBaths'] ?? 0;
                    $halfBaths = ($building[0]['halfBaths'] ?? 0) * 0.5;
                    $threeQuarterBaths = ($building[0]['threeQuarterBaths'] ?? 0) * 0.75;
                    $quarterBaths = ($building[0]['quarterBaths'] ?? 0) * 0.25;
                    $bathrooms = $fullBaths + $halfBaths + $threeQuarterBaths + $quarterBaths;
                    
                    // If detailed breakdown is zero, try simple baths field (common for condos/apartments)
                    if ($bathrooms == 0) {
                        $bathrooms = $building[0]['baths'] ?? null;
                    }
                    
                    if ($bathrooms == 0) $bathrooms = null;
                }

                $yearBuilt = null;
                if (!empty($building) && isset($building[0])) {
                    $yearBuilt = isset($building[0]['yearBuilt']) ? (int) $building[0]['yearBuilt'] : null;
                }

                // Prefer finished building area types, and add finished lower-level space
                // when the parcel only exposes the main-floor living area as the base.
                $sqft = $this->getPreferredFinishedSqft($areas);
                $lotSize = isset($property['lotSizeSquareFeet']) ? (int) $property['lotSizeSquareFeet'] : null;
                $propertyType = $property['landUseDescription'] ?? $property['propertyType'] ?? null;
                $propertyDetails = array_merge($property, [
                    'beds' => $bedrooms !== null ? (float) $bedrooms : null,
                    'bedrooms' => $bedrooms !== null ? (float) $bedrooms : null,
                    'baths' => $bathrooms !== null ? (float) $bathrooms : null,
                    'bathrooms' => $bathrooms !== null ? (float) $bathrooms : null,
                    'sqft' => $sqft !== null ? (int) $sqft : null,
                    'squareFeet' => $sqft !== null ? (int) $sqft : null,
                    'lot_size' => $lotSize,
                    'lotSize' => $lotSize,
                    'year_built' => $yearBuilt,
                    'yearBuilt' => $yearBuilt,
                    'property_type' => $propertyType,
                    'propertyType' => $propertyType,
                    'zpid' => $property['zpid'] ?? null,
                ]);

                // Get garage information
                $garageCars = null;
                $garageSqft = null;
                $garages = $property['garages'] ?? [];
                
                if (!empty($garages)) {
                    $totalCars = 0;
                    $totalSqft = 0;
                    $hasGarageData = false;
                    
                    foreach ($garages as $garage) {
                        // Sum car count if available
                        if (isset($garage['carCount']) && $garage['carCount'] !== null) {
                            $totalCars += (int)$garage['carCount'];
                            $hasGarageData = true;
                        }
                        
                        // Sum garage square footage
                        if (isset($garage['areaSquareFeet']) && $garage['areaSquareFeet'] !== null) {
                            $totalSqft += (int)$garage['areaSquareFeet'];
                            $hasGarageData = true;
                        }
                    }
                    
                    // If no carCount data, use count of garage objects
                    if ($totalCars === 0 && $hasGarageData) {
                        $totalCars = count($garages);
                    }
                    
                    $garageCars = $totalCars > 0 ? $totalCars : null;
                    $garageSqft = $totalSqft > 0 ? $totalSqft : null;
                }

                return [
                    'formatted_address' => $address['formattedStreetAddress'] ?? $address['formatted_address'] ?? $addressLine,
                    'address' => $addressLine,
                    'city' => $address['city'] ?? '',
                    'state' => $address['state'] ?? $address['stateCode'] ?? $address['state_code'] ?? '',
                    'zip' => $address['zipcode'] ?? $address['zip'] ?? $address['postalCode'] ?? $address['postal_code'] ?? '',
                    'country' => 'US',
                    'latitude' => isset($address['latitude']) ? (float)$address['latitude'] : (isset($property['latitude']) ? (float)$property['latitude'] : null),
                    'longitude' => isset($address['longitude']) ? (float)$address['longitude'] : (isset($property['longitude']) ? (float)$property['longitude'] : null),
                    'bedrooms' => $bedrooms !== null ? (float)$bedrooms : null,
                    'bathrooms' => $bathrooms !== null ? (float)$bathrooms : null,
                    'sqft' => $sqft !== null ? (int)$sqft : null,
                    'lot_size' => $lotSize,
                    'year_built' => $yearBuilt,
                    'property_type' => $propertyType,
                    'garage_cars' => $garageCars,
                    'garage_sqft' => $garageSqft,
                    'property_details' => $propertyDetails,
                    'zpid' => $placeId,
                    'source' => 'bridge_parcel_id',
                    'confidence' => 0.9,
                    'field_sources' => array_filter([
                        'bedrooms' => $bedrooms !== null ? 'bridge_parcel_id' : null,
                        'bathrooms' => $bathrooms !== null ? 'bridge_parcel_id' : null,
                        'sqft' => $sqft !== null ? 'bridge_parcel_id' : null,
                        'lot_size' => $lotSize !== null ? 'bridge_parcel_id' : null,
                        'year_built' => $yearBuilt !== null ? 'bridge_parcel_id' : null,
                        'property_type' => $propertyType !== null ? 'bridge_parcel_id' : null,
                        'garage_cars' => $garageCars !== null ? 'bridge_parcel_id' : null,
                        'garage_sqft' => $garageSqft !== null ? 'bridge_parcel_id' : null,
                        'zpid' => 'bridge_parcel_id',
                    ]),
                    'property_source_chain' => ['bridge_parcel_id'],
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Bridge Data parcel lookup exception', [
                'url' => $url,
                'place_id' => $placeId,
                'error' => $e->getMessage(),
            ]);
        }

        Log::error('Bridge Data parcel lookup failed', [
            'place_id' => $placeId,
        ]);
        return null;
    }
}
