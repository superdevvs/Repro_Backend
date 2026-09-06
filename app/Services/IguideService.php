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
    public const FAILURE_NONE = null;
    public const FAILURE_WEBHOOK_ONLY = 'webhook_only';
    public const FAILURE_NOT_FOUND = 'not_found';
    public const FAILURE_AUTH = 'auth';
    public const FAILURE_OTHER = 'other';

    private ?string $apiUsername;
    private ?string $apiPassword;
    private ?string $apiKey;
    private ?string $appId;
    private ?string $appToken;
    private string $baseUrl;
    private string $legacyBaseUrl;
    private ?string $webhookUrl;
    private ?string $lastFailureReason = null;

    public function getLastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

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
        $this->lastFailureReason = null;
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
            if ($this->lastFailureReason === null) {
                $this->lastFailureReason = self::FAILURE_NOT_FOUND;
            }
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
                if ($this->isSignedAppForbidden($response)) {
                    // Token authenticates fine but the Portal blocks signed apps from
                    // reading iGuides on demand. Caller must rely on webhook delivery.
                    $this->lastFailureReason = self::FAILURE_WEBHOOK_ONLY;
                }

                // Portal is the live integration. api.iguide.com no longer serves
                // TLS for its own hostname (cURL 35, unrecognized name), so
                // falling through to it cannot succeed and only logs an error on
                // every reconciliation run.
                return null;
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
                $match = $this->searchPortalByAddress($address);
                if ($match) {
                    return $match;
                }

                // Same reason as syncProperty(): the legacy host is unreachable,
                // so attempting it per shoot per run only produces noise.
                return null;
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
            $existingIguideData = is_array($shoot->iguide_data) ? $shoot->iguide_data : [];
            $providerPayload = $this->buildIguideDataPayload($iguideData);
            // Provider sync/webhook data and a manually uploaded offline export
            // have independent lifecycles. A late provider event must never erase
            // the current private package pointer or its publication attestation.
            if (is_array($existingIguideData['manual_offline_package'] ?? null)) {
                $providerPayload['manual_offline_package'] = $existingIguideData['manual_offline_package'];
            }
            $shoot->iguide_data = $providerPayload;
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
        // Never label the branded/public tour as MLS. If iGUIDE did not send a
        // dedicated unbranded URL, the compliant slot must remain empty.
        $mlsCandidate = $iguideData['unbranded_url'] ?? null;

        $isBlank = static fn ($value): bool => !is_string($value) || trim($value) === '';

        if ($isBlank($tourLinks['iguide_branded'] ?? null) && is_string($brandedCandidate) && $brandedCandidate !== '') {
            $tourLinks['iguide_branded'] = $brandedCandidate;
        }
        if (is_string($mlsCandidate) && $mlsCandidate !== '') {
            if ($isBlank($tourLinks['iguide_mls'] ?? null)) {
                $tourLinks['iguide_mls'] = $mlsCandidate;
            }
            if (($tourLinks['iguide_mls'] ?? null) === $mlsCandidate) {
                $tourLinks['iguide_mls_source'] = 'unbranded_url';
            }
        } elseif (($tourLinks['iguide_mls'] ?? null) === $brandedCandidate) {
            // Repair the historical auto-fill on the next sync. A manual,
            // distinct MLS URL is retained.
            unset($tourLinks['iguide_mls'], $tourLinks['iguide_mls_source']);
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

    /**
     * Resolve the shoot a provider address belongs to.
     *
     * This is the path a delayed iGuide takes: the photographer produces the
     * iGuide hours or days after booking, and the webhook arrives carrying an
     * address but no identifier we have seen before. Matching is component
     * based (see addressesMatch) so a provider abbreviation still resolves
     * while a neighbouring house number cannot.
     */
    public function findShootByAddress(string $address): ?Shoot
    {
        $components = $this->parseAddressComponents($address);
        if ($components['house'] === '' || $components['street'] === '') {
            return null;
        }

        $query = Shoot::query()->whereNotNull('address');

        // Narrow on ZIP when the provider gave one so the common case does not
        // load every shoot into memory. Falls back to a full scan otherwise.
        if ($components['zip'] !== '') {
            $zip = $components['zip'];
            $query->where(function ($q) use ($zip) {
                // LIKE covers a ZIP+4 stored on our side. Rows without a ZIP
                // must stay in scope because buildFullAddress() can still carry
                // one inside the address line.
                $q->where('zip', 'like', $zip . '%')
                    ->orWhereNull('zip')
                    ->orWhere('zip', '');
            });
        }

        // A property can be shot many times: 6275 Kerrydale Drive has seven
        // shoots. Newest-first is the right default, but a cancelled booking
        // must never win, and a shoot that actually booked a floor plan is a
        // better home for an iGuide than one that did not. cursor() streams
        // rather than materialising the whole table.
        $best = null;
        $bestRank = -1;

        foreach ($query->orderByDesc('id')->cursor() as $shoot) {
            $shootAddress = $this->buildFullAddress($shoot);
            if ($shootAddress === null) {
                continue;
            }

            if (!$this->addressesMatch($address, $shootAddress)) {
                continue;
            }

            $rank = $this->addressMatchRank($shoot);

            // Strictly greater keeps the newest among equals, because the
            // cursor already walks newest first.
            if ($rank > $bestRank) {
                $best = $shoot;
                $bestRank = $rank;
            }

            // Live booking that owns floor-plan work: nothing can beat it.
            if ($bestRank === 2) {
                break;
            }
        }

        return $best;
    }

    /**
     * Preference among several shoots at one address: a live booking that owns
     * floor-plan work, then any live booking, then a dead one as a last resort.
     */
    private function addressMatchRank(Shoot $shoot): int
    {
        $dead = [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED];
        if (in_array($shoot->status, $dead, true) || in_array($shoot->workflow_status, $dead, true)) {
            return 0;
        }

        return $shoot->hasIguideEligibleService() ? 2 : 1;
    }

    /**
     * Whether the current credentials only support inbound webhook delivery
     * (i.e. iGUIDE App Tokens / "signed apps" which the Portal blocks from
     * reading iGuide data on demand).
     */
    public function isWebhookOnlyMode(): bool
    {
        // Legacy basic-auth supports outbound reads.
        if (!empty($this->apiUsername) && !empty($this->apiPassword)) {
            return false;
        }
        // App Tokens are signed apps — outbound reads of iGuides are blocked
        // by the Portal regardless of scope. Webhooks still deliver everything.
        return $this->hasPortalCredentials();
    }

    /**
     * Detect the iGuide Portal's specific "signed apps are not allowed on
     * this endpoint" 403 reply, which means the token type can't read iGuide
     * data on demand and must rely on the webhook flow instead.
     */
    private function isSignedAppForbidden(?Response $response): bool
    {
        if (!$response || $response->status() !== 403) {
            return false;
        }
        $payload = $response->json();
        $debug = is_array($payload) ? ($payload['debugInfo'] ?? '') : '';
        return is_string($debug) && stripos($debug, 'signed apps are not allowed') !== false;
    }

    public function testConnection(): array
    {
        try {
            if ($this->hasPortalCredentials()) {
                $response = $this->portalRequest('post', '/integrations/test');
                $payload = $response?->json();
                $appIdEcho = is_array($payload) ? ($payload['appId'] ?? null) : null;
                $authVerified = $response?->successful() && $appIdEcho === $this->appId;

                $message = $authVerified
                    ? 'Connection successful (webhook-only mode — iGuide data flows via webhook).'
                    : 'Connection failed';

                return [
                    'success' => $authVerified,
                    'status' => $response?->status() ?? 0,
                    'message' => $message,
                    'mode' => 'webhook-only',
                    'app_id_verified' => $authVerified,
                    'webhook_url' => $this->webhookUrl,
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
                'message' => 'The provider connection failed. Please check the configuration and try again.',
            ];
        }
    }

    private function searchPortalByAddress(string $address): ?array
    {
        $queries = [
            ['search' => $address],
            ['q' => $address],
            ['address' => $address],
            ['fullAddress' => $address],
        ];

        foreach ($queries as $query) {
            $response = $this->portalRequest('get', '/iguides', $query);
            if ($this->isSignedAppForbidden($response)) {
                $this->lastFailureReason = self::FAILURE_WEBHOOK_ONLY;
                return null;
            }
            $match = $this->findAddressMatchInPayload($response, $address);
            if ($match) {
                return $match;
            }
        }

        $fallbackResponse = $this->portalRequest('get', '/iguides', ['limit' => 100]);
        if ($this->isSignedAppForbidden($fallbackResponse)) {
            $this->lastFailureReason = self::FAILURE_WEBHOOK_ONLY;
            return null;
        }

        return $this->findAddressMatchInPayload($fallbackResponse, $address);
    }

    private function findAddressMatchInPayload(?Response $response, string $targetAddress): ?array
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
                if ($this->addressesMatch($candidate, $targetAddress)) {
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

    /**
     * Pull every usable address spelling out of a provider record.
     *
     * The provider exposes the same property in three different shapes and
     * only the webhook one carries a pre-joined fullAddress:
     *   list    address{streetNumber, streetName, city, provinceState, postalCode}
     *   detail  property{house, street, city, province, code}
     *   webhook property.fullAddress
     * Composing the component shapes is what lets a list/detail record be
     * compared at all; previously the list shape lost the street and state and
     * the detail shape produced nothing.
     */
    private function extractCandidateAddresses(array $data): array
    {
        $candidates = [
            Arr::get($data, 'property.fullAddress'),
            Arr::get($data, 'address.fullAddress'),
            Arr::get($data, 'fullAddress'),
            Arr::get($data, 'location.fullAddress'),
        ];

        foreach (['address', 'property', 'location'] as $key) {
            $node = Arr::get($data, $key);
            if (is_string($node)) {
                $candidates[] = $node;
            } elseif (is_array($node)) {
                $candidates[] = $this->composeAddressFromComponents($node);
            }
        }

        // Some payloads put the components at the root of the record.
        $candidates[] = $this->composeAddressFromComponents($data);

        $seen = [];
        $out = [];
        foreach ($candidates as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = $value;
        }

        return $out;
    }

    /**
     * Join provider address components into "house street, city, state zip",
     * tolerating the differing key names across payload shapes.
     */
    private function composeAddressFromComponents(array $c): ?string
    {
        $pick = function (array $keys) use ($c): string {
            foreach ($keys as $k) {
                $v = $c[$k] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
                if (is_int($v) || is_float($v)) {
                    return (string) $v;
                }
            }
            return '';
        };

        $house = $pick(['streetNumber', 'house', 'houseNumber', 'street_number', 'number']);
        $street = $pick(['streetName', 'street', 'street1', 'street_name', 'streetAddress']);
        $unit = $pick(['unitNumber', 'unit', 'unit_number', 'apt', 'apartment', 'suite']);
        $city = $pick(['city', 'town', 'locality']);
        $state = $pick(['provinceState', 'province', 'state', 'province_state', 'region']);
        $zip = $pick(['postalCode', 'zip', 'code', 'postal_code', 'postalcode']);

        // Without a house number and street this cannot identify a property, and
        // a partial string here would only invite a wrong match.
        if ($house === '' || $street === '') {
            return null;
        }

        $streetLine = $house . ' ' . $street;
        if ($unit !== '') {
            $streetLine .= ' Unit ' . $unit;
        }

        $tail = trim($state . ' ' . $zip);

        $parts = array_filter([$streetLine, $city, $tail], fn ($p) => is_string($p) && trim($p) !== '');
        if (count($parts) < 2) {
            return null;
        }

        return implode(', ', $parts);
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

    /**
     * Canonical token forms so that a provider spelling and our stored
     * spelling of the same street reduce to an identical string.
     *
     * Canonical form is the USPS-style abbreviation. "SAINT" is deliberately
     * absent: mapping it onto ST would conflate "Saint" with "Street".
     */
    private const STREET_TYPES = [
        'STREET' => 'ST', 'ST' => 'ST', 'STR' => 'ST',
        'ROAD' => 'RD', 'RD' => 'RD',
        'DRIVE' => 'DR', 'DR' => 'DR', 'DRV' => 'DR',
        'COURT' => 'CT', 'CT' => 'CT', 'CRT' => 'CT',
        'AVENUE' => 'AVE', 'AVE' => 'AVE', 'AV' => 'AVE', 'AVEN' => 'AVE',
        'BOULEVARD' => 'BLVD', 'BLVD' => 'BLVD', 'BLVD.' => 'BLVD',
        'LANE' => 'LN', 'LN' => 'LN',
        'PLACE' => 'PL', 'PL' => 'PL',
        'TERRACE' => 'TER', 'TERR' => 'TER', 'TER' => 'TER',
        'CIRCLE' => 'CIR', 'CIR' => 'CIR', 'CRCL' => 'CIR',
        'PARKWAY' => 'PKWY', 'PKWY' => 'PKWY', 'PKY' => 'PKWY',
        'TRAIL' => 'TRL', 'TRL' => 'TRL',
        'SQUARE' => 'SQ', 'SQ' => 'SQ',
        'HIGHWAY' => 'HWY', 'HWY' => 'HWY',
        'CRESCENT' => 'CRES', 'CRES' => 'CRES',
        'WAY' => 'WAY', 'WY' => 'WAY',
        'POINT' => 'PT', 'PT' => 'PT',
        'RIDGE' => 'RDG', 'RDG' => 'RDG',
        'HEIGHTS' => 'HTS', 'HTS' => 'HTS',
        'TURNPIKE' => 'TPKE', 'TPKE' => 'TPKE',
        'CROSSING' => 'XING', 'XING' => 'XING',
        'GARDENS' => 'GDNS', 'GDNS' => 'GDNS',
        'MANOR' => 'MNR', 'MNR' => 'MNR',
        'PLAZA' => 'PLZ', 'PLZ' => 'PLZ',
        'EXTENSION' => 'EXT', 'EXT' => 'EXT',
        'HOLLOW' => 'HOLW', 'HOLW' => 'HOLW',
        'LANDING' => 'LNDG', 'LNDG' => 'LNDG',
        'MEADOWS' => 'MDWS', 'MDWS' => 'MDWS',
        'JUNCTION' => 'JCT', 'JCT' => 'JCT',
        'STATION' => 'STA', 'STA' => 'STA',
        'VALLEY' => 'VLY', 'VLY' => 'VLY',
        'VILLAGE' => 'VLG', 'VLG' => 'VLG',
        'HARBOR' => 'HBR', 'HBR' => 'HBR',
        'SUMMIT' => 'SMT', 'SMT' => 'SMT',
        'MOUNTAIN' => 'MTN', 'MTN' => 'MTN',
        'CREEK' => 'CRK', 'CRK' => 'CRK',
        'HILLS' => 'HLS', 'HLS' => 'HLS',
        'HILL' => 'HL', 'HL' => 'HL',
        'LAKE' => 'LK', 'LK' => 'LK',
        'COVE' => 'CV', 'CV' => 'CV',
        'GLEN' => 'GLN', 'GLN' => 'GLN',
        'GROVE' => 'GRV', 'GRV' => 'GRV',
        'FOREST' => 'FRST', 'FRST' => 'FRST',
        'WOODS' => 'WDS', 'WDS' => 'WDS',
        'FIELD' => 'FLD', 'FLD' => 'FLD',
        'FALLS' => 'FLS', 'FLS' => 'FLS',
        'KNOLL' => 'KNL', 'KNL' => 'KNL',
        'BEND' => 'BND', 'BND' => 'BND',
        'SHORE' => 'SHR', 'SHR' => 'SHR',
        'SPRING' => 'SPG', 'SPG' => 'SPG',
        'ORCHARD' => 'ORCH', 'ORCH' => 'ORCH',
    ];

    private const DIRECTIONALS = [
        'NORTH' => 'N', 'N' => 'N',
        'SOUTH' => 'S', 'S' => 'S',
        'EAST' => 'E', 'E' => 'E',
        'WEST' => 'W', 'W' => 'W',
        'NORTHEAST' => 'NE', 'NE' => 'NE',
        'NORTHWEST' => 'NW', 'NW' => 'NW',
        'SOUTHEAST' => 'SE', 'SE' => 'SE',
        'SOUTHWEST' => 'SW', 'SW' => 'SW',
    ];

    private const UNIT_MARKERS = [
        'UNIT' => 'UNIT', 'APT' => 'UNIT', 'APARTMENT' => 'UNIT',
        'STE' => 'UNIT', 'SUITE' => 'UNIT', 'NO' => 'UNIT',
    ];

    /**
     * US states/territories plus Canadian provinces, since the provider is
     * Canadian. Used to reject a trailing 2-letter token that is not a region.
     */
    private const REGION_CODES = [
        'AL' => 1, 'AK' => 1, 'AZ' => 1, 'AR' => 1, 'CA' => 1, 'CO' => 1, 'CT' => 1,
        'DE' => 1, 'DC' => 1, 'FL' => 1, 'GA' => 1, 'HI' => 1, 'ID' => 1, 'IL' => 1,
        'IN' => 1, 'IA' => 1, 'KS' => 1, 'KY' => 1, 'LA' => 1, 'ME' => 1, 'MD' => 1,
        'MA' => 1, 'MI' => 1, 'MN' => 1, 'MS' => 1, 'MO' => 1, 'MT' => 1, 'NE' => 1,
        'NV' => 1, 'NH' => 1, 'NJ' => 1, 'NM' => 1, 'NY' => 1, 'NC' => 1, 'ND' => 1,
        'OH' => 1, 'OK' => 1, 'OR' => 1, 'PA' => 1, 'RI' => 1, 'SC' => 1, 'SD' => 1,
        'TN' => 1, 'TX' => 1, 'UT' => 1, 'VT' => 1, 'VA' => 1, 'WA' => 1, 'WV' => 1,
        'WI' => 1, 'WY' => 1, 'PR' => 1, 'VI' => 1, 'GU' => 1, 'AS' => 1, 'MP' => 1,
        'AB' => 1, 'BC' => 1, 'MB' => 1, 'NB' => 1, 'NL' => 1, 'NS' => 1, 'NT' => 1,
        'NU' => 1, 'ON' => 1, 'PE' => 1, 'QC' => 1, 'SK' => 1, 'YT' => 1,
    ];

    /**
     * Flatten an address to a comparable token string.
     *
     * Beyond casing/punctuation/whitespace this now canonicalizes street
     * types and directionals, and reduces ZIP+4 to its 5-digit base, so that
     * "7509 Amesbury Court, Alexandria, VA 22315-1234" and
     * "7509 Amesbury Ct, Alexandria, VA 22315" reduce to the same string.
     */
    private function normalizeAddress(?string $address): string
    {
        if (!is_string($address)) {
            return '';
        }

        $address = strtoupper(trim($address));
        if ($address === '') {
            return '';
        }

        // Collapse ZIP+4 to the 5-digit base before punctuation is stripped,
        // otherwise the +4 survives as a separate token.
        $address = preg_replace('/\b(\d{5})-\d{4}\b/', '$1', $address) ?? $address;

        $address = preg_replace('/[^A-Z0-9]+/', ' ', $address) ?? '';
        $address = preg_replace('/\s+/', ' ', $address) ?? '';
        $address = trim($address);

        if ($address === '') {
            return '';
        }

        $tokens = explode(' ', $address);
        foreach ($tokens as $i => $token) {
            if (isset(self::UNIT_MARKERS[$token])) {
                $tokens[$i] = self::UNIT_MARKERS[$token];
                continue;
            }
            if (isset(self::DIRECTIONALS[$token])) {
                $tokens[$i] = self::DIRECTIONALS[$token];
                continue;
            }
            if (isset(self::STREET_TYPES[$token])) {
                $tokens[$i] = self::STREET_TYPES[$token];
            }
        }

        return implode(' ', $tokens);
    }

    /**
     * Split an address into the components we can compare safely.
     *
     * Commas are honoured first because "street, city, state zip" is the
     * shape both our own records and the provider payloads use. When there
     * are no commas we fall back to positional parsing (trailing 5-digit ZIP,
     * preceding 2-letter state) and leave street+city fused.
     */
    public function parseAddressComponents(?string $address): array
    {
        $empty = [
            'house' => '', 'street' => '', 'unit' => '',
            'city' => '', 'state' => '', 'zip' => '', 'fused' => false,
        ];

        if (!is_string($address) || trim($address) === '') {
            return $empty;
        }

        $segments = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($s) => $s !== ''));

        $streetLine = '';
        $city = '';
        $state = '';
        $zip = '';
        $fused = false;

        if (count($segments) >= 3) {
            $streetLine = $segments[0];
            $city = $segments[1];
            $tail = $this->normalizeAddress($segments[count($segments) - 1]);
            [$state, $zip] = $this->splitStateZip($tail);
        } elseif (count($segments) === 2) {
            $streetLine = $segments[0];
            $tail = $this->normalizeAddress($segments[1]);
            [$state, $zip] = $this->splitStateZip($tail);
            $remainder = trim(preg_replace('/\s*' . preg_quote(trim($state . ' ' . $zip), '/') . '\s*$/', '', $tail) ?? '');
            $city = $remainder;
        } else {
            $normalized = $this->normalizeAddress($address);
            [$state, $zip] = $this->splitStateZip($normalized);
            $streetLine = trim(preg_replace('/\s*' . preg_quote(trim($state . ' ' . $zip), '/') . '\s*$/', '', $normalized) ?? '');
            $fused = true;
        }

        $normalizedStreetLine = $this->normalizeAddress($streetLine);

        // Leading house number, e.g. "7509" or "123A".
        $house = '';
        if (preg_match('/^(\d+[A-Z]?)\s+/', $normalizedStreetLine . ' ', $m)) {
            $house = $m[1];
            $normalizedStreetLine = trim(substr($normalizedStreetLine, strlen($house)));
        }

        // Explicit unit marker, e.g. "UNIT 4B".
        $unit = '';
        if (preg_match('/\bUNIT\s+([A-Z0-9]+)\b/', $normalizedStreetLine, $m)) {
            $unit = $m[1];
            $normalizedStreetLine = trim(preg_replace('/\bUNIT\s+' . preg_quote($unit, '/') . '\b/', ' ', $normalizedStreetLine) ?? $normalizedStreetLine);
            $normalizedStreetLine = trim(preg_replace('/\s+/', ' ', $normalizedStreetLine) ?? $normalizedStreetLine);
        }

        return [
            'house' => $house,
            'street' => $normalizedStreetLine,
            'unit' => $unit,
            'city' => $this->normalizeAddress($city),
            'state' => $state,
            'zip' => $zip,
            'fused' => $fused,
        ];
    }

    /**
     * Split a trailing "STATE ZIP" off a normalized address tail.
     *
     * The 2-letter token is only accepted as a state when it really is one, and
     * when it could also be a street type or directional it is only accepted if
     * a ZIP was found alongside it. Without that guard "7509 Amesbury Ct" parses
     * as a Connecticut address and loses its street type.
     */
    private function splitStateZip(string $normalizedTail): array
    {
        $state = '';
        $zip = '';

        if (preg_match('/\b(\d{5})\b\s*$/', $normalizedTail, $m)) {
            $zip = $m[1];
            $position = strrpos($normalizedTail, $zip);
            $normalizedTail = $position === false ? '' : trim(substr($normalizedTail, 0, $position));
        }

        if (preg_match('/\b([A-Z]{2})\s*$/', $normalizedTail, $m)) {
            $candidate = $m[1];
            $ambiguous = isset(self::STREET_TYPES[$candidate]) || isset(self::DIRECTIONALS[$candidate]);

            if (isset(self::REGION_CODES[$candidate]) && (!$ambiguous || $zip !== '')) {
                $state = $candidate;
            }
        }

        return [$state, $zip];
    }

    /**
     * Decide whether two addresses denote the same property.
     *
     * Deliberately strict: the house number must match exactly and at least
     * one locality component (ZIP, else city, else state) must corroborate.
     * This is what stops "509 Amesbury Ct" attaching to "7509 Amesbury Ct"
     * and stops a bare street name matching anything on that street.
     */
    public function addressesMatch(?string $left, ?string $right): bool
    {
        $a = $this->parseAddressComponents($left);
        $b = $this->parseAddressComponents($right);

        // A house number is mandatory on both sides. Without it the candidate
        // is a street, not a property.
        if ($a['house'] === '' || $b['house'] === '') {
            return false;
        }
        if ($a['house'] !== $b['house']) {
            return false;
        }

        // Distinct declared units are distinct properties.
        if ($a['unit'] !== '' && $b['unit'] !== '' && $a['unit'] !== $b['unit']) {
            return false;
        }

        // When either side left street and city fused (no commas), compare the
        // street prefix instead of demanding equality with a fused string.
        if ($a['fused'] || $b['fused']) {
            $short = strlen($a['street']) <= strlen($b['street']) ? $a['street'] : $b['street'];
            $long = strlen($a['street']) <= strlen($b['street']) ? $b['street'] : $a['street'];
            if ($short === '' || !str_starts_with($long, $short)) {
                return false;
            }
        } elseif ($a['street'] === '' || $a['street'] !== $b['street']) {
            return false;
        }

        // Locality corroboration, strongest available wins.
        if ($a['zip'] !== '' && $b['zip'] !== '') {
            return $a['zip'] === $b['zip'];
        }
        if ($a['city'] !== '' && $b['city'] !== '') {
            return $a['city'] === $b['city'];
        }
        if ($a['state'] !== '' && $b['state'] !== '') {
            return $a['state'] === $b['state'];
        }

        // House + street alone is not enough: the same street number exists in
        // many towns.
        return false;
    }
}
