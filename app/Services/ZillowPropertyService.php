<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ZillowPropertyService
{
    private const ADDRESS_OVERRIDE_SETTING_KEY = 'integrations.zillow.address_overrides';

    private $clientId;
    private $clientSecret;
    private $serverToken;
    private $browserToken;
    private $baseUrl;
    private $legacyLookupUrl;
    private array $addressOverrides;

    public function __construct()
    {
        // Try to load from database settings first, fallback to config
        $settings = $this->loadSettings('integrations.zillow');
        
        $this->clientId = $settings['clientId'] ?? config('services.zillow.client_id');
        $this->clientSecret = $settings['clientSecret'] ?? config('services.zillow.client_secret');
        $this->serverToken = $settings['serverToken'] ?? config('services.zillow.server_token');
        $this->browserToken = $settings['browserToken'] ?? config('services.zillow.browser_token');
        $this->baseUrl = rtrim(config('services.zillow.base_url', 'https://api.bridgedataoutput.com/api/v2'), '/');
        $this->legacyLookupUrl = trim((string) config('services.zillow.legacy_lookup_url', 'https://pro.reprophotos.com/get_zillow_info.php'));
        $this->addressOverrides = $this->loadAddressOverrides();
    }

    private function loadSettings(string $key): array
    {
        try {
            $setting = DB::table('settings')->where('key', $key)->first();
            if ($setting && $setting->type === 'json') {
                return json_decode($setting->value, true) ?? [];
            }
        } catch (\Exception $e) {
            Log::warning('Could not load settings from database', ['key' => $key]);
        }
        return [];
    }

    /**
     * Fetch property details by address or MLS ID
     */
    public function fetchPropertyDetails(string $address, ?string $mlsId = null): ?array
    {
        $normalizedAddress = trim($address);
        if ($normalizedAddress === '') {
            return null;
        }

        $cacheKey = 'zillow_property_lookup_' . md5(strtolower($normalizedAddress) . '|' . ($mlsId ?? ''));
        $cached = Cache::remember($cacheKey, 21600, function () use ($normalizedAddress, $mlsId) {
            $property = $this->performPropertyLookup($normalizedAddress, $mlsId);

            return [
                'hit' => $property !== null,
                'data' => $property,
            ];
        });

        return ($cached['hit'] ?? false) ? ($cached['data'] ?? null) : null;
    }

    private function performPropertyLookup(string $address, ?string $mlsId = null): ?array
    {
        try {
            $property = $this->fetchPropertyDetailsFromApi($address, $mlsId);
            if ($property) {
                return $this->applyManualOverrides(
                    $this->supplementPropertyWithLegacyData(
                    $this->withLookupMetadata($property, 'bridge_properties', 0.96, ['bridge_properties']),
                    $address
                    ),
                    $address
                );
            }

            $zpid = $this->resolveZpidByAddress($address);
            if ($zpid) {
                $property = $this->getBridgePropertyByZpidCached($zpid);
                if ($property) {
                    return $this->applyManualOverrides(
                        $this->supplementPropertyWithLegacyData($property, $address),
                        $address
                    );
                }
            }

            $legacyProperty = $this->applyManualOverrides(
                $this->supplementPropertyWithLegacyData(null, $address),
                $address
            );
            if ($legacyProperty) {
                return $legacyProperty;
            }

            Log::warning('Zillow property fallback returned no data', [
                'address' => $address,
                'mls_id' => $mlsId,
                'zpid' => $zpid ?? null,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Zillow property lookup exception', [
                'address' => $address,
                'mls_id' => $mlsId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fetchPropertyDetailsFromApi(string $address, ?string $mlsId = null): ?array
    {
        $params = [
            'access_token' => $this->serverToken,
            'address' => $address,
        ];

        if ($mlsId) {
            $params['mls'] = $mlsId;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->serverToken,
            'Accept' => 'application/json',
        ])->withoutVerifying()->get($this->baseUrl . '/properties', $params);

        if (!$response->successful()) {
            Log::warning('Zillow property API lookup failed, trying zpid fallback', [
                'address' => $address,
                'mls_id' => $mlsId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $data = $response->json();
        $property = $data['bundle'] ?? $data['data'] ?? $data['property'] ?? ($data[0] ?? $data);

        if (!is_array($property) || empty($property)) {
            Log::warning('Zillow property API returned no usable data', [
                'address' => $address,
                'response' => $data,
            ]);
            return null;
        }

        return $this->parsePropertyData($property);
    }

    public function resolveZpidByAddress(string $address): ?string
    {
        $normalizedTarget = $this->normalizeAddressText($address);
        if ($normalizedTarget === '') {
            return null;
        }

        $cacheKey = 'zillow_resolved_zpid_' . md5($normalizedTarget);
        $cached = Cache::remember($cacheKey, 21600, function () use ($address, $normalizedTarget) {
            try {
                $response = Http::withOptions(['verify' => false])->get(
                    'https://www.zillowstatic.com/autocomplete/v3/suggestions',
                    [
                        'q' => $address,
                        'resultCount' => 10,
                        'resultTypes' => 'allAddress',
                    ]
                );

                if (!$response->successful()) {
                    Log::warning('Zillow autocomplete zpid lookup failed', [
                        'address' => $address,
                        'status' => $response->status(),
                        'body' => substr($response->body(), 0, 500),
                    ]);
                    return ['hit' => false, 'zpid' => null];
                }

                $results = $response->json('results', []);
                if (!is_array($results) || empty($results)) {
                    return ['hit' => false, 'zpid' => null];
                }

                $bestResult = null;
                $bestScore = -1;
                foreach ($results as $result) {
                    $score = $this->scoreAutocompleteResultForAddress($result, $normalizedTarget);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestResult = $result;
                    }
                }

                $zpid = data_get($bestResult, 'metaData.zpid');
                if ($bestScore < 40 || empty($zpid)) {
                    return ['hit' => false, 'zpid' => null];
                }

                return ['hit' => true, 'zpid' => (string) $zpid];
            } catch (\Throwable $e) {
                Log::warning('Zillow autocomplete zpid lookup exception', [
                    'address' => $address,
                    'error' => $e->getMessage(),
                ]);
                return ['hit' => false, 'zpid' => null];
            }
        });

        return ($cached['hit'] ?? false) ? ($cached['zpid'] ?? null) : null;
    }

    private function scoreAutocompleteResultForAddress(array $result, string $normalizedTarget): int
    {
        $display = $this->normalizeAddressText((string) ($result['display'] ?? ''));
        $streetNumber = (string) data_get($result, 'metaData.streetNumber', '');
        $streetName = $this->normalizeAddressText((string) data_get($result, 'metaData.streetName', ''));
        $city = $this->normalizeAddressText((string) data_get($result, 'metaData.city', ''));
        $state = strtolower((string) data_get($result, 'metaData.state', ''));
        $zip = (string) data_get($result, 'metaData.zipCode', '');

        $score = 0;

        if ($display === $normalizedTarget) {
            $score += 100;
        }

        if ($streetNumber !== '' && preg_match('/^' . preg_quote($streetNumber, '/') . '\b/', $normalizedTarget)) {
            $score += 20;
        }

        if ($streetName !== '' && str_contains($normalizedTarget, $streetName)) {
            $score += 20;
        }

        if ($city !== '' && str_contains($normalizedTarget, $city)) {
            $score += 10;
        }

        if ($state !== '' && preg_match('/\b' . preg_quote($state, '/') . '\b/', $normalizedTarget)) {
            $score += 5;
        }

        if ($zip !== '' && str_contains($normalizedTarget, $zip)) {
            $score += 10;
        }

        return $score;
    }

    private function normalizeAddressText(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^\w\s]/', ' ', $value) ?? '';

        $replacements = [
            'street' => 'st',
            'st' => 'st',
            'avenue' => 'ave',
            'ave' => 'ave',
            'road' => 'rd',
            'rd' => 'rd',
            'drive' => 'dr',
            'dr' => 'dr',
            'court' => 'ct',
            'ct' => 'ct',
            'boulevard' => 'blvd',
            'blvd' => 'blvd',
            'lane' => 'ln',
            'ln' => 'ln',
            'place' => 'pl',
            'pl' => 'pl',
            'circle' => 'cir',
            'cir' => 'cir',
            'terrace' => 'ter',
            'ter' => 'ter',
            'parkway' => 'pkwy',
            'pkwy' => 'pkwy',
            'highway' => 'hwy',
            'hwy' => 'hwy',
            'north' => 'n',
            'south' => 's',
            'east' => 'e',
            'west' => 'w',
        ];

        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $parts = array_map(fn ($part) => $replacements[$part] ?? $part, $parts);

        return trim(implode(' ', array_filter($parts, fn ($part) => $part !== '')));
    }

    private function fetchBridgePropertyByZpid(string $zpid): ?array
    {
        $parcel = $this->fetchBridgeRecordByZpid('/pub/parcels', $zpid);
        $assessment = $this->fetchLatestAssessmentByZpid($zpid);

        if ($parcel || $assessment) {
            $parsed = $this->buildBridgePropertyFromRecords($parcel, $assessment, $zpid);

            return $this->withLookupMetadata(
                $parsed,
                'bridge_zpid',
                $assessment ? 0.88 : 0.84,
                array_values(array_filter([
                    'zillow_autocomplete_zpid',
                    $parcel ? 'bridge_pub_parcels' : null,
                    $assessment ? 'bridge_pub_assessments' : null,
                ]))
            );
        }

        return null;
    }

    private function getBridgePropertyByZpidCached(string $zpid): ?array
    {
        $cacheKey = 'zillow_property_zpid_' . md5($zpid);
        $cached = Cache::remember($cacheKey, 21600, function () use ($zpid) {
            $property = $this->fetchBridgePropertyByZpid($zpid);

            return [
                'hit' => $property !== null,
                'data' => $property,
            ];
        });

        return ($cached['hit'] ?? false) ? ($cached['data'] ?? null) : null;
    }

    private function fetchBridgeRecordByZpid(string $endpoint, string $zpid): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serverToken,
                'Accept' => 'application/json',
            ])->withoutVerifying()->get($this->baseUrl . $endpoint, [
                'access_token' => $this->serverToken,
                'zpid' => $zpid,
                'limit' => 1,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $bundle = $response->json('bundle', []);
            $record = $bundle[0] ?? null;

            return is_array($record) ? $record : null;
        } catch (\Throwable $e) {
            Log::warning('Bridge zpid lookup failed', [
                'endpoint' => $endpoint,
                'zpid' => $zpid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fetchLatestAssessmentByZpid(string $zpid): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serverToken,
                'Accept' => 'application/json',
            ])->withoutVerifying()->get($this->baseUrl . '/pub/assessments', [
                'access_token' => $this->serverToken,
                'zpid' => $zpid,
                'sortBy' => 'year',
                'order' => 'desc',
                'limit' => 1,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $bundle = $response->json('bundle', []);
            if (!is_array($bundle) || empty($bundle)) {
                return null;
            }

            $record = $bundle[0] ?? null;

            return is_array($record) ? $record : null;
        } catch (\Throwable $e) {
            Log::warning('Bridge assessment zpid lookup failed', [
                'zpid' => $zpid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildBridgePropertyFromRecords(?array $parcel, ?array $assessment, string $resolvedZpid): array
    {
        $parcelProperty = $parcel ? $this->parseBridgeRecord($parcel, 'bridge_pub_parcels') : null;
        $assessmentProperty = $assessment ? $this->parseBridgeRecord($assessment, 'bridge_pub_assessments') : null;

        $address = $parcelProperty['address'] ?? $assessmentProperty['address'] ?? [
            'street' => '',
            'street_name' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
            'formatted' => '',
        ];

        $address = array_merge(
            $assessmentProperty['address'] ?? [],
            $address,
            $parcelProperty['address'] ?? []
        );

        $property = [
            'mls_id' => null,
            'status' => null,
            'price' => null,
            'price_low' => null,
            'price_high' => null,
            'days_on_market' => null,
            'beds' => $assessmentProperty['beds'] ?? $parcelProperty['beds'] ?? null,
            'baths' => $assessmentProperty['baths'] ?? $parcelProperty['baths'] ?? null,
            'sqft' => $parcelProperty['sqft'] ?? $assessmentProperty['sqft'] ?? null,
            'lot_size' => $parcelProperty['lot_size'] ?? $assessmentProperty['lot_size'] ?? null,
            'year_built' => $assessmentProperty['year_built'] ?? $parcelProperty['year_built'] ?? null,
            'property_type' => $parcelProperty['property_type'] ?? $assessmentProperty['property_type'] ?? null,
            'tax_assessed_value' => $assessmentProperty['tax_assessed_value'] ?? $parcelProperty['tax_assessed_value'] ?? null,
            'tax_year' => $assessmentProperty['tax_year'] ?? $parcelProperty['tax_year'] ?? null,
            'garage_cars' => $parcelProperty['garage_cars'] ?? $assessmentProperty['garage_cars'] ?? null,
            'garage_sqft' => $parcelProperty['garage_sqft'] ?? $assessmentProperty['garage_sqft'] ?? null,
            'zpid' => (string) (
                $parcelProperty['zpid']
                ?? $assessmentProperty['zpid']
                ?? $resolvedZpid
            ),
            'address' => $address,
            'raw_data' => [
                'parcel' => $parcel,
                'assessment' => $assessment,
            ],
            'raw_parcel' => $parcel,
            'raw_assessment' => $assessment,
            'field_sources' => [],
        ];

        $fieldPriority = [
            'beds' => [$assessmentProperty, $parcelProperty],
            'baths' => [$assessmentProperty, $parcelProperty],
            'sqft' => [$parcelProperty, $assessmentProperty],
            'lot_size' => [$parcelProperty, $assessmentProperty],
            'year_built' => [$assessmentProperty, $parcelProperty],
            'property_type' => [$parcelProperty, $assessmentProperty],
            'tax_assessed_value' => [$assessmentProperty, $parcelProperty],
            'tax_year' => [$assessmentProperty, $parcelProperty],
            'garage_cars' => [$parcelProperty, $assessmentProperty],
            'garage_sqft' => [$parcelProperty, $assessmentProperty],
            'zpid' => [$parcelProperty, $assessmentProperty],
        ];

        foreach ($fieldPriority as $field => $sources) {
            foreach ($sources as $sourceProperty) {
                if (($sourceProperty[$field] ?? null) === null || ($sourceProperty[$field] ?? null) === '') {
                    continue;
                }

                $property['field_sources'][$field] = $sourceProperty['field_sources'][$field] ?? null;
                break;
            }
        }

        return $property;
    }

    private function parseBridgeRecord(array $property, string $source = 'bridge_pub_parcels'): array
    {
        if (!isset($property['zpid']) || $property['zpid'] === null || $property['zpid'] === '') {
            $property['zpid'] = $property['id'] ?? null;
        }

        $areas = is_array($property['areas'] ?? null) ? $property['areas'] : [];
        $buildingData = $property['building'] ?? [];
        $building = is_array($buildingData) ? ($buildingData[0] ?? []) : [];
        $garages = is_array($property['garages'] ?? null) ? $property['garages'] : [];
        $address = $property['address'] ?? [];

        $garageCars = null;
        $garageSqft = null;
        if (!empty($garages)) {
            $carTotal = 0;
            $sqftTotal = 0;
            $hasCars = false;
            $hasSqft = false;

            foreach ($garages as $garage) {
                if (isset($garage['carCount']) && $garage['carCount'] !== null) {
                    $carTotal += (int) $garage['carCount'];
                    $hasCars = true;
                }

                if (isset($garage['areaSquareFeet']) && $garage['areaSquareFeet'] !== null) {
                    $sqftTotal += (int) round((float) $garage['areaSquareFeet']);
                    $hasSqft = true;
                }
            }

            if (!$hasCars && count($garages) > 0) {
                $carTotal = count($garages);
                $hasCars = true;
            }

            $garageCars = $hasCars && $carTotal > 0 ? $carTotal : null;
            $garageSqft = $hasSqft && $sqftTotal > 0 ? $sqftTotal : null;
        }

        $parsed = [
            'mls_id' => null,
            'status' => null,
            'price' => null,
            'price_low' => null,
            'price_high' => null,
            'days_on_market' => null,
            'beds' => $this->normalizeNumber($building['bedrooms'] ?? null),
            'baths' => $this->normalizeBathrooms($building),
            'sqft' => $this->getPreferredFinishedSqft($areas),
            'lot_size' => $this->normalizeNumber($property['lotSizeSquareFeet'] ?? null),
            'year_built' => $this->normalizeNumber($building['yearBuilt'] ?? null),
            'property_type' => $property['landUseDescription'] ?? null,
            'tax_assessed_value' => $this->normalizeNumber($property['totalValue'] ?? null),
            'tax_year' => $this->normalizeNumber($property['taxYear'] ?? $property['year'] ?? null),
            'garage_cars' => $garageCars,
            'garage_sqft' => $garageSqft,
            'zpid' => isset($property['zpid']) ? (string) $property['zpid'] : null,
            'address' => [
                'street' => $address['house'] ?? '',
                'street_name' => trim(implode(' ', array_filter([
                    $address['streetPre'] ?? null,
                    $address['street'] ?? null,
                    $address['streetSuffix'] ?? null,
                    $address['streetPost'] ?? null,
                ]))),
                'city' => $address['city'] ?? '',
                'state' => $address['state'] ?? '',
                'zip' => $address['zip'] ?? '',
                'formatted' => $address['full'] ?? '',
            ],
            'raw_data' => $property,
        ];

        $parsed['field_sources'] = $this->buildFieldSources($parsed, $source);

        return $parsed;
    }

    private function supplementPropertyWithLegacyData(?array $property, string $address): ?array
    {
        $needsLegacyMetrics = !$property
            || $property['beds'] === null
            || $property['baths'] === null
            || $property['sqft'] === null;

        if (!$needsLegacyMetrics) {
            return $property;
        }

        $legacyMetrics = $this->fetchLegacyMetricsByAddress($address);
        if (!$legacyMetrics) {
            return $property;
        }

        if (!$property) {
            $legacyProperty = [
                'mls_id' => null,
                'status' => null,
                'price' => null,
                'price_low' => null,
                'price_high' => null,
                'days_on_market' => null,
                'beds' => $legacyMetrics['beds'],
                'baths' => $legacyMetrics['baths'],
                'sqft' => $legacyMetrics['sqft'],
                'lot_size' => null,
                'year_built' => null,
                'property_type' => null,
                'tax_assessed_value' => null,
                'tax_year' => null,
                'garage_cars' => null,
                'garage_sqft' => null,
                'zpid' => null,
                'address' => [
                    'street' => '',
                    'street_name' => '',
                    'city' => '',
                    'state' => '',
                    'zip' => '',
                    'formatted' => $address,
                ],
                'raw_data' => [
                    'legacy_lookup' => $legacyMetrics['raw_data'],
                ],
            ];

            return $this->withLookupMetadata(
                $legacyProperty,
                'legacy_get_zillow_info',
                0.62,
                ['legacy_get_zillow_info']
            );
        }

        $fieldMap = [
            'beds' => 'beds',
            'baths' => 'baths',
            'sqft' => 'sqft',
        ];

        foreach ($fieldMap as $propertyField => $legacyField) {
            if (($property[$propertyField] ?? null) === null && $legacyMetrics[$legacyField] !== null) {
                $property[$propertyField] = $legacyMetrics[$legacyField];
                $property['field_sources'][$propertyField] = 'legacy_get_zillow_info';
            }
        }

        $rawData = $property['raw_data'] ?? [];
        if (!is_array($rawData)) {
            $rawData = ['bridge_raw' => $rawData];
        }
        $rawData['legacy_lookup'] = $legacyMetrics['raw_data'];
        $property['raw_data'] = $rawData;
        $property['raw_legacy_lookup'] = $legacyMetrics['raw_data'];
        $property['property_source_chain'] = array_values(array_unique(array_merge(
            $property['property_source_chain'] ?? [],
            ['legacy_get_zillow_info']
        )));
        $property['confidence'] = min((float) ($property['confidence'] ?? 0.88), 0.82);

        return $property;
    }

    private function fetchLegacyMetricsByAddress(string $address): ?array
    {
        $normalizedAddress = trim($address);
        if ($normalizedAddress === '' || $this->legacyLookupUrl === '') {
            return null;
        }

        $cacheKey = 'zillow_legacy_metrics_' . md5(strtolower($normalizedAddress));
        $cached = Cache::remember($cacheKey, 21600, function () use ($normalizedAddress) {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'User-Agent' => 'REPRO Dashboard/1.0',
                ])->withoutVerifying()->timeout(8)->get($this->legacyLookupUrl, [
                    'address' => $normalizedAddress,
                ]);

                if (!$response->successful()) {
                    Log::warning('Legacy Zillow lookup failed', [
                        'address' => $normalizedAddress,
                        'status' => $response->status(),
                        'body' => substr($response->body(), 0, 300),
                    ]);

                    return ['hit' => false, 'data' => null];
                }

                $payload = $response->json();
                if (!is_array($payload)) {
                    $payload = json_decode($response->body(), true);
                }

                if (!is_array($payload)) {
                    return ['hit' => false, 'data' => null];
                }

                $beds = $this->normalizeNumber($payload['bedrooms'] ?? $payload['beds'] ?? null);
                $baths = $this->normalizeNumber($payload['bathrooms'] ?? $payload['baths'] ?? null);
                $sqft = $this->normalizeNumber($payload['squareFootage'] ?? $payload['sqft'] ?? null);

                if ($beds === null && $baths === null && $sqft === null) {
                    return ['hit' => false, 'data' => null];
                }

                return [
                    'hit' => true,
                    'data' => [
                        'beds' => $beds,
                        'baths' => $baths,
                        'sqft' => $sqft,
                        'raw_data' => $payload,
                    ],
                ];
            } catch (\Throwable $e) {
                Log::warning('Legacy Zillow lookup exception', [
                    'address' => $normalizedAddress,
                    'error' => $e->getMessage(),
                ]);

                return ['hit' => false, 'data' => null];
            }
        });

        return ($cached['hit'] ?? false) ? ($cached['data'] ?? null) : null;
    }

    private function loadAddressOverrides(): array
    {
        $rawOverrides = $this->loadSettings(self::ADDRESS_OVERRIDE_SETTING_KEY);
        if (isset($rawOverrides['overrides']) && is_array($rawOverrides['overrides'])) {
            $rawOverrides = $rawOverrides['overrides'];
        }

        if (!is_array($rawOverrides)) {
            return [];
        }

        $normalizedOverrides = [];
        foreach ($rawOverrides as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $normalizedKey = $this->normalizeAddressText((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            $normalizedOverrides[$normalizedKey] = $value;
        }

        return $normalizedOverrides;
    }

    private function applyManualOverrides(?array $property, string $address): ?array
    {
        $override = $this->findAddressOverride($address, $property);
        if (!$override) {
            return $property;
        }

        if (!$property) {
            $property = [
                'mls_id' => null,
                'status' => null,
                'price' => null,
                'price_low' => null,
                'price_high' => null,
                'days_on_market' => null,
                'beds' => null,
                'baths' => null,
                'sqft' => null,
                'lot_size' => null,
                'year_built' => null,
                'property_type' => null,
                'tax_assessed_value' => null,
                'tax_year' => null,
                'garage_cars' => null,
                'garage_sqft' => null,
                'zpid' => null,
                'address' => [
                    'street' => '',
                    'street_name' => '',
                    'city' => '',
                    'state' => '',
                    'zip' => '',
                    'formatted' => $address,
                ],
                'raw_data' => [],
                'field_sources' => [],
                'property_source_chain' => [],
            ];
        }

        $appliedFields = [];
        foreach ([
            'beds',
            'baths',
            'sqft',
            'lot_size',
            'year_built',
            'garage_cars',
            'garage_sqft',
            'property_type',
            'zpid',
        ] as $field) {
            $overrideValue = $this->normalizeOverrideValue($override[$field] ?? null);
            if ($overrideValue === null || $overrideValue === '') {
                continue;
            }

            $property[$field] = $overrideValue;
            $property['field_sources'][$field] = 'manual_override';
            $appliedFields[] = $field;
        }

        $rawData = $property['raw_data'] ?? [];
        if (!is_array($rawData)) {
            $rawData = ['lookup_payload' => $rawData];
        }
        $rawData['manual_override'] = $override;
        $property['raw_data'] = $rawData;
        $property['raw_manual_override'] = $override;
        $property['manual_override'] = $override;
        $property['override_applied'] = !empty($appliedFields);
        $property['override_fields'] = $appliedFields;
        $property['property_source_chain'] = array_values(array_unique(array_merge(
            $property['property_source_chain'] ?? [],
            ['manual_override']
        )));

        if (($property['source'] ?? null) === null || ($property['source'] ?? null) === 'legacy_get_zillow_info') {
            $property['source'] = 'manual_override';
        }

        $property['confidence'] = max((float) ($property['confidence'] ?? 0.0), 0.99);

        return $property;
    }

    private function findAddressOverride(string $address, ?array $property = null): ?array
    {
        if (empty($this->addressOverrides)) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $this->normalizeAddressText($address),
            $this->normalizeAddressText((string) data_get($property, 'address.formatted', '')),
            $this->normalizeAddressText((string) data_get($property, 'raw_data.parcel.address.full', '')),
            $this->normalizeAddressText((string) data_get($property, 'raw_data.assessment.address.full', '')),
        ])));

        foreach ($candidates as $candidate) {
            if (isset($this->addressOverrides[$candidate]) && is_array($this->addressOverrides[$candidate])) {
                return $this->addressOverrides[$candidate];
            }
        }

        $zpid = (string) ($property['zpid'] ?? '');
        if ($zpid !== '') {
            foreach ($this->addressOverrides as $override) {
                if (!is_array($override)) {
                    continue;
                }

                if ((string) ($override['zpid'] ?? '') === $zpid) {
                    return $override;
                }
            }
        }

        return null;
    }

    private function normalizeOverrideValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return $this->normalizeNumber($value);
        }

        return $value;
    }

    private function withLookupMetadata(array $property, string $source, float $confidence, array $sourceChain = []): array
    {
        $property['source'] = $source;
        $property['confidence'] = $confidence;
        $property['field_sources'] = array_merge(
            $this->buildFieldSources($property, $source),
            $property['field_sources'] ?? []
        );
        $property['property_source_chain'] = array_values(array_unique(array_filter(array_merge(
            $property['property_source_chain'] ?? [],
            $sourceChain ?: [$source]
        ))));

        return $property;
    }

    private function buildFieldSources(array $property, string $source): array
    {
        $fieldSources = [];

        foreach ([
            'beds',
            'baths',
            'sqft',
            'lot_size',
            'year_built',
            'property_type',
            'tax_assessed_value',
            'tax_year',
            'garage_cars',
            'garage_sqft',
            'zpid',
        ] as $field) {
            if (array_key_exists($field, $property) && $property[$field] !== null && $property[$field] !== '') {
                $fieldSources[$field] = $source;
            }
        }

        return $fieldSources;
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

    private function getAreaSquareFeet(array $area): ?int
    {
        if (!isset($area['areaSquareFeet']) || $area['areaSquareFeet'] === null) {
            return null;
        }

        $value = (int) round((float) $area['areaSquareFeet']);

        return $value > 0 ? $value : null;
    }

    private function normalizeBathrooms(array $building): ?float
    {
        $fullBaths = (float) ($building['fullBaths'] ?? 0);
        $halfBaths = (float) ($building['halfBaths'] ?? 0) * 0.5;
        $threeQuarterBaths = (float) ($building['threeQuarterBaths'] ?? 0) * 0.75;
        $quarterBaths = (float) ($building['quarterBaths'] ?? 0) * 0.25;

        $total = $fullBaths + $halfBaths + $threeQuarterBaths + $quarterBaths;
        if ($total > 0) {
            return $total;
        }

        return $this->normalizeNumber($building['baths'] ?? null);
    }

    private function normalizeNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $numeric = (float) $value;
        if ((float) (int) $numeric === $numeric) {
            return (int) $numeric;
        }

        return $numeric;
    }

    /**
     * Parse property data into standardized format
     */
    private function parsePropertyData(array $property): array
    {
        $address = $property['address'] ?? $property;
        $propertyDetails = $property['propertyDetails'] ?? $property;
        $zestimate = $property['zestimate'] ?? $property['zEstimate'] ?? [];
        $garages = is_array($property['garages'] ?? null) ? $property['garages'] : [];

        $garageCars = null;
        $garageSqft = null;
        if (!empty($garages)) {
            $garageCars = $this->normalizeNumber(collect($garages)->sum(fn ($garage) => (int) ($garage['carCount'] ?? 0)));
            $garageSqft = $this->normalizeNumber(collect($garages)->sum(fn ($garage) => (float) ($garage['areaSquareFeet'] ?? 0)));
        }

        return [
            'mls_id' => $property['mlsId'] ?? $property['mls_id'] ?? $property['mlsNumber'] ?? null,
            'status' => $property['status'] ?? $property['listingStatus'] ?? null,
            'price' => $property['price'] ?? $zestimate['amount'] ?? null,
            'price_low' => $property['priceLow'] ?? $zestimate['low'] ?? null,
            'price_high' => $property['priceHigh'] ?? $zestimate['high'] ?? null,
            'days_on_market' => $property['daysOnMarket'] ?? $property['days_on_market'] ?? $property['dom'] ?? null,
            'beds' => $propertyDetails['bedrooms'] ?? $property['bedrooms'] ?? $property['beds'] ?? null,
            'baths' => $propertyDetails['bathrooms'] ?? $property['bathrooms'] ?? $property['baths'] ?? null,
            'sqft' => $propertyDetails['livingArea'] ?? $property['livingArea'] ?? $property['sqft'] ?? $property['squareFeet'] ?? null,
            'lot_size' => $propertyDetails['lotSize'] ?? $property['lotSize'] ?? $property['lotSizeSqft'] ?? null,
            'year_built' => $propertyDetails['yearBuilt'] ?? $property['yearBuilt'] ?? $property['year_built'] ?? null,
            'property_type' => $propertyDetails['propertyType'] ?? $property['propertyType'] ?? $property['property_type'] ?? null,
            'tax_assessed_value' => $property['taxAssessedValue'] ?? $property['tax_assessed_value'] ?? null,
            'tax_year' => $property['taxYear'] ?? $property['tax_year'] ?? null,
            'garage_cars' => $garageCars,
            'garage_sqft' => $garageSqft,
            'zpid' => isset($property['zpid']) ? (string) $property['zpid'] : (isset($property['id']) ? (string) $property['id'] : null),
            'address' => [
                'street' => $address['streetNumber'] ?? $address['street_number'] ?? '',
                'street_name' => $address['streetName'] ?? $address['street_name'] ?? '',
                'city' => $address['city'] ?? '',
                'state' => $address['state'] ?? $address['stateCode'] ?? '',
                'zip' => $address['zipcode'] ?? $address['zip'] ?? '',
                'formatted' => $address['formattedStreetAddress'] ?? $address['formatted_address'] ?? '',
            ],
            'raw_data' => $property,
        ];
    }

    /**
     * Test connection to Zillow API
     */
    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serverToken,
                'Accept' => 'application/json',
            ])->timeout(5)->get($this->baseUrl . '/test', [
                'access_token' => $this->serverToken,
            ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'message' => $response->successful() ? 'Connection successful' : 'Connection failed',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }
}


