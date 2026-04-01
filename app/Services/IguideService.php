<?php

namespace App\Services;

use App\Models\Shoot;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $shoot->iguide_last_synced_at = now();
        $shoot->save();

        return $shoot;
    }

    public function parsePropertyData(array $data): array
    {
        return [
            'property_id' => $this->extractPropertyId($data),
            'tour_url' => $this->extractTourUrl($data),
            'floorplans' => $this->extractFloorplans($data),
            'room_measurements' => Arr::get($data, 'roomMeasurements')
                ?? Arr::get($data, 'room_measurements')
                ?? Arr::get($data, 'measurements'),
            'address' => $this->extractAddress($data),
            'raw_data' => $data,
        ];
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
            Arr::get($data, 'tourUrl'),
            Arr::get($data, 'tour_url'),
            Arr::get($data, 'url'),
            Arr::get($data, 'urls.publicUrl'),
            Arr::get($data, 'urls.unbrandedUrl'),
            Arr::get($data, 'links.public'),
        ] as $candidate) {
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

    private function extractFloorplans(array $data): array
    {
        $existing = Arr::get($data, 'floorplans') ?? Arr::get($data, 'floor_plans');
        if (is_array($existing) && !empty($existing)) {
            return $this->normalizeFloorplanItems($existing);
        }

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

        $unique = [];
        foreach ($collected as $item) {
            if (!isset($item['url'])) {
                continue;
            }

            $unique[$item['url']] = $item;
        }

        return array_values($unique);
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
