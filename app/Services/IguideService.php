<?php

namespace App\Services;

use App\Models\Shoot;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class IguideService
{
    private ?string $apiUsername;
    private ?string $apiPassword;
    private ?string $apiKey;
    private ?string $appId;
    private ?string $appToken;
    private string $baseUrl;
    private string $legacyBaseUrl;
    private ?string $webhookUrl;

    public function __construct()
    {
        $settings = $this->loadSettings('integrations.iguide');

        $this->apiUsername = $settings['apiUsername'] ?? config('services.iguide.api_username');
        $this->apiPassword = $settings['apiPassword'] ?? config('services.iguide.api_password');
        $this->apiKey = $settings['apiKey'] ?? config('services.iguide.api_key');
        $this->appId = $settings['appId'] ?? config('services.iguide.app_id');
        $this->appToken = $settings['appToken']
            ?? config('services.iguide.app_token')
            ?? ($this->appId ? $this->apiKey : null);
        $this->baseUrl = rtrim(config('services.iguide.base_url', 'https://manage.youriguide.com/api/v1'), '/');
        $this->legacyBaseUrl = rtrim(config('services.iguide.legacy_base_url', 'https://api.iguide.com'), '/');
        $this->webhookUrl = config('services.iguide.webhook_url');
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

    public function syncShoot(Shoot $shoot): ?array
    {
        $iguideData = null;

        if ($shoot->iguide_property_id) {
            $iguideData = $this->syncProperty((string) $shoot->iguide_property_id);
        }

        if (!$iguideData) {
            $fullAddress = $this->buildFullAddress($shoot);
            if ($fullAddress !== null) {
                $iguideData = $this->searchByAddress($fullAddress);
            }
        }

        if (!$iguideData) {
            return null;
        }

        $this->applyShootData($shoot, $iguideData);

        return $iguideData;
    }

    /**
     * Sync iGUIDE data by its iGUIDE/property ID.
     */
    public function syncProperty(string $propertyId): ?array
    {
        try {
            if ($this->hasPortalCredentials()) {
                $response = $this->portalRequest('get', '/iguides/' . rawurlencode($propertyId));
                if ($response?->successful()) {
                    return $this->parsePropertyData($response->json());
                }
            }

            return $this->syncLegacyProperty($propertyId);
        } catch (\Exception $e) {
            Log::error('iGUIDE sync exception', [
                'property_id' => $propertyId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Search for iGUIDE properties by a shoot address.
     */
    public function searchByAddress(string $address): ?array
    {
        $normalizedAddress = $this->normalizeAddress($address);
        if ($normalizedAddress === '') {
            return null;
        }

        try {
            if ($this->hasPortalCredentials()) {
                $match = $this->searchPortalByAddress($address, $normalizedAddress);
                if ($match) {
                    return $match;
                }
            }

            return $this->searchLegacyByAddress($address);
        } catch (\Exception $e) {
            Log::error('iGUIDE search exception', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function applyShootData(Shoot $shoot, array $iguideData): Shoot
    {
        $shoot->iguide_tour_url = $iguideData['tour_url'] ?? $shoot->iguide_tour_url;
        $shoot->iguide_floorplans = $iguideData['floorplans'] ?? $shoot->iguide_floorplans ?? [];
        $shoot->iguide_property_id = $iguideData['property_id'] ?? $shoot->iguide_property_id;

        if (!empty($iguideData['work_order_id'])) {
            $shoot->iguide_work_order_id = (string) $iguideData['work_order_id'];
        }

        // Persist a slimmed-down structured payload (no large blobs) for the UI.
        if (Schema::hasColumn('shoots', 'iguide_data')) {
            $shoot->iguide_data = $this->buildIguideDataPayload($iguideData);
        }

        // Auto-fill the managed iGuide Branded / MLS link slots in tour_links so
        // they show up automatically in the Tour tab. We never overwrite a
        // value an admin has already pasted.
        $shoot->tour_links = $this->mergeIguideTourLinks(
            $shoot->tour_links,
            $iguideData,
        );

        $shoot->iguide_last_synced_at = now();
        $shoot->save();

        return $shoot;
    }

    /**
     * Merge auto-discovered iGuide URLs (publicUrl / unbrandedUrl) into the
     * shoot's tour_links payload without overwriting existing manual values.
     */
    private function mergeIguideTourLinks($existing, array $iguideData): array
    {
        $tourLinks = is_array($existing) ? $existing : (
            is_string($existing) ? (json_decode($existing, true) ?: []) : []
        );

        $brandedCandidate = $iguideData['tour_url']
            ?? $iguideData['unbranded_url']
            ?? null;
        $mlsCandidate = $iguideData['unbranded_url']
            ?? $iguideData['tour_url']
            ?? null;

        $isBlank = static fn ($value): bool => !is_string($value) || trim($value) === '';

        if ($isBlank($tourLinks['iguide_branded'] ?? null) && is_string($brandedCandidate) && $brandedCandidate !== '') {
            $tourLinks['iguide_branded'] = $brandedCandidate;
        }
        if ($isBlank($tourLinks['iguide_mls'] ?? null) && is_string($mlsCandidate) && $mlsCandidate !== '') {
            $tourLinks['iguide_mls'] = $mlsCandidate;
        }
        if ($isBlank($tourLinks['iGuide'] ?? null) && is_string($brandedCandidate) && $brandedCandidate !== '') {
            // Legacy fallback key kept in sync for older readers.
            $tourLinks['iGuide'] = $brandedCandidate;
        }

        return $tourLinks;
    }

    /**
     * Parse the documented iGUIDE Portal `ready` event (or a /iguides GET payload)
     * into a normalized shape used throughout the dashboard.
     */
    public function parsePropertyData(array $data): array
    {
        $authToken = $this->extractAccessToken($data);
        $mediaUrls = $this->extractMediaUrls($data, $authToken);
        $jpgMetric = $this->extractJpgFloors($data, 'metric', $authToken);
        $jpgImperial = $this->extractJpgFloors($data, 'imperial', $authToken);

        return [
            // Backward-compatible keys.
            'property_id' => $this->extractPropertyId($data),
            'tour_url' => $this->withAccessToken($this->extractTourUrl($data), $authToken),
            'floorplans' => $this->extractFloorplans($data, $mediaUrls, $jpgMetric, $jpgImperial),
            'room_measurements' => Arr::get($data, 'roomMeasurements')
                ?? Arr::get($data, 'room_measurements')
                ?? Arr::get($data, 'measurements'),
            'address' => $this->extractAddress($data),
            'raw_data' => $data,

            // Richer fields documented at https://docs.youriguide.com/docs/integrations/webhooks
            'work_order_id' => $this->extractWorkOrderId($data),
            'iguide_alias' => Arr::get($data, 'iguideAlias') ?? Arr::get($data, 'alias'),
            'default_view_id' => Arr::get($data, 'defaultViewId'),
            'authtoken' => $authToken,
            'unbranded_url' => $this->withAccessToken(
                $this->firstString([
                    Arr::get($data, 'urls.unbrandedUrl'),
                    Arr::get($data, 'unbrandedUrl'),
                ]),
                $authToken,
            ),
            'embedded_url' => $this->withAccessToken(
                $this->firstString([
                    Arr::get($data, 'urls.embeddedUrl'),
                    Arr::get($data, 'embeddedUrl'),
                ]),
                $authToken,
            ),
            'manage_url' => $this->firstString([
                Arr::get($data, 'urls.manageUrl'),
                Arr::get($data, 'manageUrl'),
            ]),
            'embed_image_url' => $mediaUrls['embedImage'] ?? null,
            'gallery_front_url' => $mediaUrls['galleryFrontImage'] ?? null,
            'pdf_metric_url' => $mediaUrls['pdfMetric'] ?? null,
            'pdf_imperial_url' => $mediaUrls['pdfImperial'] ?? null,
            'gallery_zip_url' => $mediaUrls['galleryZip'] ?? null,
            'gallery_low_res_zip_url' => $mediaUrls['galleryLowResZip'] ?? null,
            'sphere_zip_url' => $mediaUrls['sphereZip'] ?? null,
            'offline_zip_url' => $mediaUrls['offlineZip'] ?? null,
            'svg_zip_url' => $mediaUrls['svgZip'] ?? null,
            'dxf_zip_url' => $mediaUrls['dxfZip'] ?? null,
            'jpg_metric' => $jpgMetric,
            'jpg_imperial' => $jpgImperial,
            'media_urls' => $mediaUrls,
            'property' => $this->extractPropertyInfo($data),
            'billing' => $this->extractBillingInfo($data),
            'summary' => Arr::get($data, 'summary'),
            'banner' => Arr::get($data, 'banner'),
        ];
    }

    /**
     * Build the slim payload that we store on shoots.iguide_data.
     * We intentionally drop heavy blobs (raw event, full summary/banner) to keep the column small.
     */
    private function buildIguideDataPayload(array $iguideData): array
    {
        return array_filter([
            'property_id' => $iguideData['property_id'] ?? null,
            'work_order_id' => $iguideData['work_order_id'] ?? null,
            'iguide_alias' => $iguideData['iguide_alias'] ?? null,
            'default_view_id' => $iguideData['default_view_id'] ?? null,
            'authtoken' => $iguideData['authtoken'] ?? null,
            'tour_url' => $iguideData['tour_url'] ?? null,
            'unbranded_url' => $iguideData['unbranded_url'] ?? null,
            'embedded_url' => $iguideData['embedded_url'] ?? null,
            'manage_url' => $iguideData['manage_url'] ?? null,
            'embed_image_url' => $iguideData['embed_image_url'] ?? null,
            'gallery_front_url' => $iguideData['gallery_front_url'] ?? null,
            'pdf_metric_url' => $iguideData['pdf_metric_url'] ?? null,
            'pdf_imperial_url' => $iguideData['pdf_imperial_url'] ?? null,
            'gallery_zip_url' => $iguideData['gallery_zip_url'] ?? null,
            'gallery_low_res_zip_url' => $iguideData['gallery_low_res_zip_url'] ?? null,
            'sphere_zip_url' => $iguideData['sphere_zip_url'] ?? null,
            'offline_zip_url' => $iguideData['offline_zip_url'] ?? null,
            'svg_zip_url' => $iguideData['svg_zip_url'] ?? null,
            'dxf_zip_url' => $iguideData['dxf_zip_url'] ?? null,
            'jpg_metric' => $iguideData['jpg_metric'] ?? [],
            'jpg_imperial' => $iguideData['jpg_imperial'] ?? [],
            'property' => $iguideData['property'] ?? null,
            'billing' => $iguideData['billing'] ?? null,
            'address' => $iguideData['address'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    public function findShootByAddress(string $address): ?Shoot
    {
        $normalizedAddress = $this->normalizeAddress($address);
        if ($normalizedAddress === '') {
            return null;
        }

        return Shoot::query()
            ->whereNotNull('address')
            ->get()
            ->first(function (Shoot $shoot) use ($normalizedAddress) {
                $shootAddress = $this->buildFullAddress($shoot);
                if ($shootAddress === null) {
                    return false;
                }

                $normalizedShootAddress = $this->normalizeAddress($shootAddress);

                return $normalizedShootAddress === $normalizedAddress
                    || str_contains($normalizedShootAddress, $normalizedAddress)
                    || str_contains($normalizedAddress, $normalizedShootAddress);
            });
    }

    public function testConnection(): array
    {
        try {
            if ($this->hasPortalCredentials()) {
                $response = $this->portalRequest('post', '/integrations/test');

                return [
                    'success' => (bool) $response?->successful(),
                    'status' => $response?->status() ?? 0,
                    'message' => $response?->successful() ? 'Connection successful' : 'Connection failed',
                ];
            }

            $authToken = $this->authenticateLegacy();
            if (!$authToken) {
                return [
                    'success' => false,
                    'status' => 401,
                    'message' => 'Authentication failed',
                ];
            }

            return [
                'success' => true,
                'status' => 200,
                'message' => 'Connection successful',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    private function searchPortalByAddress(string $address, string $normalizedAddress): ?array
    {
        $queries = [
            ['search' => $address],
            ['q' => $address],
            ['address' => $address],
            ['fullAddress' => $address],
        ];

        foreach ($queries as $query) {
            $response = $this->portalRequest('get', '/iguides', $query);
            $match = $this->findAddressMatchInPayload($response, $normalizedAddress);
            if ($match) {
                return $match;
            }
        }

        $fallbackResponse = $this->portalRequest('get', '/iguides', ['limit' => 100]);

        return $this->findAddressMatchInPayload($fallbackResponse, $normalizedAddress);
    }

    private function findAddressMatchInPayload(?Response $response, string $normalizedAddress): ?array
    {
        if (!$response?->successful()) {
            if ($response) {
                Log::warning('iGUIDE Portal lookup request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return null;
        }

        foreach ($this->extractRecords($response->json()) as $record) {
            foreach ($this->extractCandidateAddresses($record) as $candidate) {
                $normalizedCandidate = $this->normalizeAddress($candidate);
                if ($normalizedCandidate === '') {
                    continue;
                }

                if (
                    $normalizedCandidate === $normalizedAddress
                    || str_contains($normalizedCandidate, $normalizedAddress)
                    || str_contains($normalizedAddress, $normalizedCandidate)
                ) {
                    return $this->parsePropertyData($record);
                }
            }
        }

        return null;
    }

    private function extractRecords(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        foreach (['data', 'items', 'results', 'iguides'] as $key) {
            $candidate = $payload[$key] ?? null;
            if (is_array($candidate)) {
                return $this->extractRecords($candidate);
            }
        }

        return [$payload];
    }

    private function extractCandidateAddresses(array $data): array
    {
        $candidates = [
            Arr::get($data, 'property.fullAddress'),
            Arr::get($data, 'address.fullAddress'),
            Arr::get($data, 'fullAddress'),
            Arr::get($data, 'location.fullAddress'),
        ];

        $flatAddress = Arr::get($data, 'address');
        if (is_string($flatAddress)) {
            $candidates[] = $flatAddress;
        } elseif (is_array($flatAddress)) {
            $candidates[] = implode(', ', array_filter([
                $flatAddress['street1'] ?? $flatAddress['street'] ?? null,
                $flatAddress['city'] ?? null,
                $flatAddress['state'] ?? null,
                $flatAddress['postalCode'] ?? $flatAddress['zip'] ?? null,
            ]));
        }

        return array_values(array_filter(array_map(function ($value) {
            return is_string($value) ? trim($value) : null;
        }, $candidates)));
    }

    private function syncLegacyProperty(string $propertyId): ?array
    {
        $authToken = $this->authenticateLegacy();
        if (!$authToken) {
            Log::error('iGUIDE authentication failed');
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $authToken,
            'Accept' => 'application/json',
        ])->get($this->legacyBaseUrl . '/properties/' . $propertyId);

        if (!$response->successful()) {
            Log::error('Legacy iGUIDE property fetch failed', [
                'property_id' => $propertyId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $this->parsePropertyData($response->json());
    }

    private function searchLegacyByAddress(string $address): ?array
    {
        $authToken = $this->authenticateLegacy();
        if (!$authToken) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $authToken,
            'Accept' => 'application/json',
        ])->get($this->legacyBaseUrl . '/properties/search', [
            'address' => $address,
        ]);

        if (!$response->successful()) {
            Log::warning('Legacy iGUIDE property search returned no results', [
                'address' => $address,
                'status' => $response->status(),
            ]);

            return null;
        }

        $data = $response->json();
        $properties = $data['properties'] ?? $data['data'] ?? ($data ? [$data] : []);

        if (empty($properties)) {
            return null;
        }

        return $this->parsePropertyData($properties[0]);
    }

    private function hasPortalCredentials(): bool
    {
        return filled($this->appId) && filled($this->appToken);
    }

    private function portalRequest(string $method, string $path, array $data = []): ?Response
    {
        if (!$this->hasPortalCredentials()) {
            return null;
        }

        $client = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(20)
            ->withHeaders([
                'X-Plntr-App-Id' => $this->appId,
                'X-Plntr-App-Token' => $this->appToken,
            ]);

        $path = '/' . ltrim($path, '/');

        return match (strtolower($method)) {
            'post' => $client->post($path, $data),
            'get' => $client->get($path, $data),
            default => throw new \InvalidArgumentException('Unsupported iGUIDE request method: ' . $method),
        };
    }

    private function authenticateLegacy(): ?string
    {
        try {
            if ($this->apiKey && !$this->appId) {
                return $this->apiKey;
            }

            if (!$this->apiUsername || !$this->apiPassword) {
                Log::error('iGUIDE credentials not configured');
                return null;
            }

            $response = Http::post($this->legacyBaseUrl . '/auth/login', [
                'username' => $this->apiUsername,
                'password' => $this->apiPassword,
            ]);

            if (!$response->successful()) {
                Log::error('iGUIDE authentication failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            return $data['token'] ?? $data['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::error('iGUIDE authentication exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractPropertyId(array $data): ?string
    {
        $value = Arr::get($data, 'iguideId')
            ?? Arr::get($data, 'propertyId')
            ?? Arr::get($data, 'property_id')
            ?? Arr::get($data, 'id');

        return $value !== null ? (string) $value : null;
    }

    private function extractTourUrl(array $data): ?string
    {
        foreach ([
            Arr::get($data, 'urls.publicUrl'),
            Arr::get($data, 'tourUrl'),
            Arr::get($data, 'tour_url'),
            Arr::get($data, 'url'),
            Arr::get($data, 'urls.unbrandedUrl'),
            Arr::get($data, 'links.public'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function extractWorkOrderId(array $data): ?string
    {
        foreach ([
            Arr::get($data, 'workOrderId'),
            Arr::get($data, 'work_order_id'),
            Arr::get($data, 'workOrder.id'),
        ] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractAccessToken(array $data): ?string
    {
        foreach ([
            Arr::get($data, 'authtoken'),
            Arr::get($data, 'authToken'),
            Arr::get($data, 'accessToken'),
            Arr::get($data, 'urls.accessToken'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Extract the documented mediaUrls block. We resolve the first available
     * locale (en first, then any other) and fold accessToken onto private docs.
     */
    private function extractMediaUrls(array $data, ?string $authToken): array
    {
        $mediaUrls = Arr::get($data, 'urls.mediaUrls');
        if (!is_array($mediaUrls) || empty($mediaUrls)) {
            $mediaUrls = Arr::get($data, 'mediaUrls');
        }
        if (!is_array($mediaUrls) || empty($mediaUrls)) {
            return [];
        }

        $locale = $mediaUrls['en'] ?? null;
        if (!is_array($locale)) {
            // Fallback to first locale-shaped entry.
            foreach ($mediaUrls as $key => $value) {
                if (is_string($key) && is_array($value)) {
                    $locale = $value;
                    break;
                }
            }
        }

        if (!is_array($locale)) {
            // Already flat shape (no locale wrapper).
            $locale = $mediaUrls;
        }

        $stringKeys = [
            'galleryFrontImage',
            'pdfMetric',
            'pdfImperial',
            'galleryZip',
            'galleryLowResZip',
            'sphereZip',
            'offlineZip',
            'svgZip',
            'dxfZip',
            'embedImage',
        ];

        $resolved = [];
        foreach ($stringKeys as $key) {
            $value = $locale[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $resolved[$key] = $this->withAccessToken(trim($value), $authToken);
            }
        }

        if (isset($locale['jpgMetric']) && is_array($locale['jpgMetric'])) {
            $resolved['jpgMetric'] = $locale['jpgMetric'];
        }
        if (isset($locale['jpgImperial']) && is_array($locale['jpgImperial'])) {
            $resolved['jpgImperial'] = $locale['jpgImperial'];
        }

        return $resolved;
    }

    /**
     * @return array<int, array{id: ?int, floor_name: ?string, url: string, units: string}>
     */
    private function extractJpgFloors(array $data, string $units, ?string $authToken): array
    {
        $key = $units === 'metric' ? 'jpgMetric' : 'jpgImperial';
        $items = Arr::get($data, "urls.mediaUrls.en.$key")
            ?? Arr::get($data, "mediaUrls.en.$key")
            ?? Arr::get($data, "urls.mediaUrls.$key")
            ?? Arr::get($data, "mediaUrls.$key");

        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = $item['url'] ?? null;
            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $out[] = [
                'id' => isset($item['id']) ? (int) $item['id'] : null,
                'floor_name' => isset($item['floorName']) ? (string) $item['floorName'] : null,
                'url' => $this->withAccessToken(trim($url), $authToken),
                'units' => $units,
            ];
        }

        return $out;
    }

    private function extractPropertyInfo(array $data): ?array
    {
        $property = Arr::get($data, 'property');
        if (!is_array($property)) {
            return null;
        }
        return array_filter([
            'fullAddress' => $property['fullAddress'] ?? null,
            'country' => $property['country'] ?? null,
            'city' => $property['city'] ?? null,
            'postalCode' => $property['postalCode'] ?? null,
            'stateProvince' => $property['stateProvince'] ?? null,
            'streetName' => $property['streetName'] ?? null,
            'streetNumber' => $property['streetNumber'] ?? null,
            'unit' => $property['unit'] ?? null,
            'lat' => Arr::get($property, 'location.lat'),
            'lng' => Arr::get($property, 'location.lng'),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private function extractBillingInfo(array $data): ?array
    {
        $billing = Arr::get($data, 'billingInfo') ?? Arr::get($data, 'billing');
        if (!is_array($billing)) {
            return null;
        }
        return array_filter([
            'iguideType' => $billing['iguideType'] ?? null,
            'package' => $billing['package'] ?? null,
            'addons' => is_array($billing['addons'] ?? null) ? array_values($billing['addons']) : null,
            'billableAreaSqFeet' => isset($billing['billableAreaSqFeet']) ? (float) $billing['billableAreaSqFeet'] : null,
            'billableAreaSqMeters' => isset($billing['billableAreaSqMeters']) ? (float) $billing['billableAreaSqMeters'] : null,
        ], static fn ($v) => $v !== null);
    }

    private function withAccessToken(?string $url, ?string $token): ?string
    {
        if (!is_string($url) || $url === '') {
            return $url;
        }
        if (!is_string($token) || $token === '') {
            return $url;
        }
        // Only append for youriguide.com private documents (per docs).
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if (!str_contains($host, 'youriguide.com')) {
            return $url;
        }
        if (str_contains($url, 'accessToken=')) {
            return $url;
        }
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'accessToken=' . rawurlencode($token);
    }

    private function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
        return null;
    }

    private function extractAddress(array $data): ?string
    {
        $candidates = $this->extractCandidateAddresses($data);

        return $candidates[0] ?? null;
    }

    /**
     * Build the normalized floorplans list (PDFs + JPG floors) used by the UI
     * and the asset-ingestion job. Each item carries enough metadata to be
     * matched idempotently against existing ShootFile records.
     */
    private function extractFloorplans(
        array $data,
        array $mediaUrls = [],
        array $jpgMetric = [],
        array $jpgImperial = []
    ): array {
        $items = [];

        if (!empty($mediaUrls['pdfMetric'])) {
            $items[] = [
                'asset_key' => 'pdf_metric',
                'url' => $mediaUrls['pdfMetric'],
                'filename' => $this->guessFilename($mediaUrls['pdfMetric'], 'floorplan-metric.pdf'),
                'label' => 'Floor Plan (Metric)',
                'type' => 'pdf',
                'units' => 'metric',
            ];
        }
        if (!empty($mediaUrls['pdfImperial'])) {
            $items[] = [
                'asset_key' => 'pdf_imperial',
                'url' => $mediaUrls['pdfImperial'],
                'filename' => $this->guessFilename($mediaUrls['pdfImperial'], 'floorplan-imperial.pdf'),
                'label' => 'Floor Plan (Imperial)',
                'type' => 'pdf',
                'units' => 'imperial',
            ];
        }

        foreach ($jpgMetric as $floor) {
            $items[] = [
                'asset_key' => 'jpg_metric_floor_' . ($floor['id'] ?? count($items)),
                'url' => $floor['url'],
                'filename' => $this->guessFilename(
                    $floor['url'],
                    sprintf('floor-%s-metric.jpg', $floor['id'] ?? 'x'),
                ),
                'label' => trim(($floor['floor_name'] ?? 'Floor') . ' (Metric)'),
                'type' => 'jpg',
                'units' => 'metric',
                'floor_name' => $floor['floor_name'] ?? null,
                'floor_id' => $floor['id'] ?? null,
            ];
        }
        foreach ($jpgImperial as $floor) {
            $items[] = [
                'asset_key' => 'jpg_imperial_floor_' . ($floor['id'] ?? count($items)),
                'url' => $floor['url'],
                'filename' => $this->guessFilename(
                    $floor['url'],
                    sprintf('floor-%s-imperial.jpg', $floor['id'] ?? 'x'),
                ),
                'label' => trim(($floor['floor_name'] ?? 'Floor') . ' (Imperial)'),
                'type' => 'jpg',
                'units' => 'imperial',
                'floor_name' => $floor['floor_name'] ?? null,
                'floor_id' => $floor['id'] ?? null,
            ];
        }

        // Backward-compat: include any pre-existing floorplans payload (legacy API shape).
        $existing = Arr::get($data, 'floorplans') ?? Arr::get($data, 'floor_plans');
        if (is_array($existing) && !empty($existing)) {
            $items = array_merge($items, $this->normalizeFloorplanItems($existing));
        }

        // Last-resort scan: existing legacy collector for unstructured payloads.
        if (empty($items)) {
            $collected = [];
            foreach ([
                Arr::get($data, 'urls.media'),
                Arr::get($data, 'mediaUrls'),
                Arr::get($data, 'assets.floorplans'),
            ] as $candidateCollection) {
                if (is_array($candidateCollection)) {
                    $this->collectFloorplanUrls($candidateCollection, $collected);
                }
            }
            $items = array_merge($items, $collected);
        }

        // De-duplicate by URL.
        $unique = [];
        foreach ($items as $item) {
            $url = $item['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            if (!isset($unique[$url])) {
                $unique[$url] = $item;
            }
        }

        return array_values($unique);
    }

    private function guessFilename(string $url, string $fallback): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $base = basename($path);
        if ($base === '' || $base === '/' || str_contains($base, '?')) {
            return $fallback;
        }
        return $base;
    }

    private function normalizeFloorplanItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $normalized[] = [
                    'url' => trim($value),
                    'filename' => basename(parse_url($value, PHP_URL_PATH) ?: $value),
                    'label' => is_string($key) ? $key : 'floorplan',
                ];
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $url = $value['url'] ?? $value['href'] ?? null;
            if (!is_string($url) || trim($url) === '') {
                continue;
            }

            $normalized[] = [
                'url' => trim($url),
                'filename' => $value['filename'] ?? basename(parse_url($url, PHP_URL_PATH) ?: $url),
                'label' => $value['label'] ?? (is_string($key) ? $key : 'floorplan'),
            ];
        }

        return $normalized;
    }

    private function collectFloorplanUrls(array $source, array &$collected, string $contextKey = 'floorplan'): void
    {
        foreach ($source as $key => $value) {
            $nextContext = is_string($key) ? $key : $contextKey;

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '' || !filter_var($trimmed, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $lowerKey = strtolower($nextContext);
                if (
                    str_contains($lowerKey, 'floor')
                    || str_contains($lowerKey, 'plan')
                    || str_contains($lowerKey, 'pdf')
                    || str_contains($lowerKey, 'metric')
                    || str_contains($lowerKey, 'imperial')
                ) {
                    $collected[] = [
                        'url' => $trimmed,
                        'filename' => basename(parse_url($trimmed, PHP_URL_PATH) ?: $trimmed),
                        'label' => $nextContext,
                    ];
                }

                continue;
            }

            if (is_array($value)) {
                $this->collectFloorplanUrls($value, $collected, $nextContext);
            }
        }
    }

    private function buildFullAddress(Shoot $shoot): ?string
    {
        $parts = array_filter([
            $shoot->address,
            $shoot->city,
            $shoot->state,
            $shoot->zip,
        ], fn ($part) => is_string($part) && trim($part) !== '');

        if (empty($parts)) {
            return null;
        }

        return trim(implode(', ', array_slice($parts, 0, 2)) . (count($parts) > 2 ? ', ' . implode(' ', array_slice($parts, 2)) : ''));
    }

    private function normalizeAddress(?string $address): string
    {
        if (!is_string($address)) {
            return '';
        }

        $address = strtoupper(trim($address));
        if ($address === '') {
            return '';
        }

        $address = preg_replace('/[^A-Z0-9]+/', ' ', $address) ?? '';
        $address = preg_replace('/\s+/', ' ', $address) ?? '';

        return trim($address);
    }
}
