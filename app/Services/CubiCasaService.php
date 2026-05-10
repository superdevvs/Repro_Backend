<?php

namespace App\Services;

use App\Models\Shoot;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound + parsing helper for the CubiCasa Integrate API v3.
 *
 * Mirrors the surface of {@see IguideService}: syncShoot(), parseOrderData(),
 * applyShootData(), plus connection/webhook utilities. Reads only — order
 * creation is intentionally out of scope for the first pass.
 *
 * Auth: CubiCasa uses an `api-key:` header (NOT `Authorization: Bearer`).
 * Docs: https://integrate.docs.cubi.casa/
 */
class CubiCasaService
{
    public const FAILURE_NONE = null;
    public const FAILURE_NOT_LINKED = 'not_linked'; // shoot has no cubicasa_order_id
    public const FAILURE_NOT_FOUND = 'not_found';
    public const FAILURE_AUTH = 'auth';
    public const FAILURE_OTHER = 'other';

    private ?string $apiKey;
    private string $baseUrl;
    private ?string $environment;
    private ?string $webhookUrl;
    private ?string $webhookSecret;
    private ?string $lastFailureReason = null;

    public function __construct()
    {
        $settings = $this->loadSettings('integrations.cubicasa');

        $this->apiKey = $settings['apiKey'] ?? config('services.cubicasa.api_key');
        $this->environment = $settings['environment'] ?? config('services.cubicasa.environment', 'production');
        $this->baseUrl = rtrim(
            $settings['baseUrl']
                ?? config('services.cubicasa.base_url')
                ?? ($this->environment === 'production'
                    ? 'https://app.cubi.casa/api/integrate/v3'
                    : 'https://qa-customers.cubi.casa/api/integrate/v3'),
            '/'
        );
        $this->webhookUrl = $settings['webhookUrl'] ?? config('services.cubicasa.webhook_url');
        $this->webhookSecret = $settings['webhookSecret'] ?? config('services.cubicasa.webhook_secret');
    }

    public function getLastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

    public function getEnvironment(): string
    {
        return (string) $this->environment;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getWebhookUrl(): string
    {
        if (!empty($this->webhookUrl)) {
            return (string) $this->webhookUrl;
        }
        return rtrim((string) config('app.url'), '/') . '/cubicasa_webhook.php';
    }

    public function hasCredentials(): bool
    {
        return is_string($this->apiKey) && trim((string) $this->apiKey) !== '';
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
     * Build a Laravel Http pending request preconfigured with auth and timeouts.
     */
    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'api-key' => (string) $this->apiKey,
            'Accept' => 'application/json',
        ])
            ->withOptions([
                'verify' => config('app.env') === 'production',
            ])
            ->timeout(30);
    }

    /**
     * Probe credentials with a cheap call. Returns ['ok' => bool, 'status' => int, 'message' => string].
     */
    public function testConnection(): array
    {
        if (!$this->hasCredentials()) {
            return ['ok' => false, 'status' => 0, 'message' => 'CUBICASA_API_KEY is not configured'];
        }
        try {
            $resp = $this->client()->get($this->baseUrl . '/orders', ['limit' => 1]);
            if ($resp->successful()) {
                $items = $resp->json('items') ?? [];
                return ['ok' => true, 'status' => $resp->status(), 'message' => 'OK', 'sample_count' => count($items)];
            }
            return [
                'ok' => false,
                'status' => $resp->status(),
                'message' => 'CubiCasa API returned ' . $resp->status() . ': ' . $resp->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('CubiCasa testConnection exception', ['error' => $e->getMessage()]);
            return ['ok' => false, 'status' => 0, 'message' => $e->getMessage()];
        }
    }

    /**
     * Register/update our webhook URL on CubiCasa side. Idempotent.
     * Endpoint: PATCH /companies/webhook
     */
    public function registerWebhook(): array
    {
        if (!$this->hasCredentials()) {
            return ['ok' => false, 'status' => 0, 'message' => 'CUBICASA_API_KEY is not configured'];
        }
        try {
            $payload = ['url' => $this->getWebhookUrl()];
            if (is_string($this->webhookSecret) && trim($this->webhookSecret) !== '') {
                $payload['secret'] = $this->webhookSecret;
            }
            $resp = $this->client()->patch($this->baseUrl . '/companies/webhook', $payload);
            if ($resp->successful()) {
                return ['ok' => true, 'status' => $resp->status(), 'message' => 'Webhook registered', 'url' => $payload['url']];
            }
            return ['ok' => false, 'status' => $resp->status(), 'message' => $resp->body()];
        } catch (\Throwable $e) {
            Log::error('CubiCasa registerWebhook exception', ['error' => $e->getMessage()]);
            return ['ok' => false, 'status' => 0, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch a single order by UUID.
     */
    public function getOrder(string $orderId): ?array
    {
        $this->lastFailureReason = null;
        if (!$this->hasCredentials()) {
            $this->lastFailureReason = self::FAILURE_AUTH;
            return null;
        }
        try {
            $resp = $this->client()->get($this->baseUrl . '/orders/' . rawurlencode($orderId));
            if ($resp->successful()) {
                return $resp->json();
            }
            $this->lastFailureReason = $this->classifyFailure($resp);
            Log::warning('CubiCasa getOrder failed', [
                'order_id' => $orderId,
                'status' => $resp->status(),
                'body' => substr((string) $resp->body(), 0, 500),
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->lastFailureReason = self::FAILURE_OTHER;
            Log::error('CubiCasa getOrder exception', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function classifyFailure(Response $resp): string
    {
        return match ($resp->status()) {
            401, 403 => self::FAILURE_AUTH,
            404 => self::FAILURE_NOT_FOUND,
            default => self::FAILURE_OTHER,
        };
    }

    /**
     * Fetch + parse + persist for a Shoot.
     * Matching precedence: cubicasa_order_id > cubicasa_external_id (via list).
     */
    public function syncShoot(Shoot $shoot): ?array
    {
        $this->lastFailureReason = null;

        $raw = null;
        if (!empty($shoot->cubicasa_order_id)) {
            $raw = $this->getOrder((string) $shoot->cubicasa_order_id);
        }

        if (!$raw && !empty($shoot->cubicasa_external_id)) {
            $raw = $this->findOrderByExternalId((string) $shoot->cubicasa_external_id);
        }

        if (!$raw) {
            if ($this->lastFailureReason === null) {
                $this->lastFailureReason = self::FAILURE_NOT_LINKED;
            }
            return null;
        }

        $parsed = $this->parseOrderData($raw);
        $this->applyShootData($shoot, $parsed);

        return $parsed;
    }

    /**
     * Find an order by external_id. Walks the paginated list (capped to 5 pages).
     */
    public function findOrderByExternalId(string $externalId): ?array
    {
        $this->lastFailureReason = null;
        if (!$this->hasCredentials()) {
            $this->lastFailureReason = self::FAILURE_AUTH;
            return null;
        }

        $offset = 0;
        $limit = 50;
        for ($page = 0; $page < 5; $page++) {
            try {
                $resp = $this->client()->get($this->baseUrl . '/orders', [
                    'limit' => $limit,
                    'offset' => $offset,
                ]);
                if (!$resp->successful()) {
                    $this->lastFailureReason = $this->classifyFailure($resp);
                    return null;
                }
                $items = $resp->json('items') ?? [];
                foreach ($items as $item) {
                    $candidate = Arr::get($item, 'info.external_id');
                    if (is_string($candidate) && $candidate === $externalId) {
                        return $item;
                    }
                }
                $hasMore = (bool) $resp->json('pagination.has_more');
                $next = $resp->json('pagination.next_offset');
                if (!$hasMore || !is_numeric($next)) {
                    break;
                }
                $offset = (int) $next;
            } catch (\Throwable $e) {
                $this->lastFailureReason = self::FAILURE_OTHER;
                Log::error('CubiCasa findOrderByExternalId exception', ['error' => $e->getMessage()]);
                return null;
            }
        }

        $this->lastFailureReason = self::FAILURE_NOT_FOUND;
        return null;
    }

    /**
     * Normalize a raw `GET /orders/{id}` payload into the shape we persist
     * + return to the frontend. Mirrors {@see IguideService::parsePropertyData()}.
     */
    public function parseOrderData(array $data): array
    {
        $info = (array) Arr::get($data, 'info', []);
        $address = (array) Arr::get($data, 'address', []);
        $delivery = (array) Arr::get($data, 'delivery_assets', []);
        $listing = (array) Arr::get($delivery, 'listing_floorplans', []);
        $homeReport = (array) Arr::get($delivery, 'home_report', []);
        $tour = Arr::get($delivery, 'tour'); // can be null
        $floorplans3d = Arr::get($delivery, 'floorplans_3d');

        $brandedTour = is_array($tour) ? Arr::get($tour, 'link') : null;
        $unbrandedTour = is_array($tour) ? Arr::get($tour, 'mls_compliance_link') : null;
        $tourType = is_array($tour) ? Arr::get($tour, 'type') : null;

        // Best fallback when tour is null: prefer the merged dimensioned PDF, then plain PDF.
        $pdfDim = (array) Arr::get($listing, 'pdf_urls_dim', []);
        $pdfPlain = (array) Arr::get($listing, 'pdf_urls', []);
        $primaryUrl = $brandedTour
            ?? Arr::first($pdfDim)
            ?? Arr::first($pdfPlain)
            ?? (is_array($floorplans3d) ? (Arr::get($floorplans3d, 'viewer_url') ?? Arr::get($floorplans3d, 'url')) : null);

        $floorplans = $this->buildFloorplanList($listing, $homeReport);

        return [
            'order_id' => Arr::get($data, 'id'),
            'external_id' => Arr::get($info, 'external_id'),
            'status' => Arr::get($info, 'status'),
            'product_type' => Arr::get($info, 'order_type'),
            'first_delivered_at' => Arr::get($info, 'first_delivered_at'),

            'tour_url' => $primaryUrl,
            'tour' => is_array($tour) ? [
                'link' => $brandedTour,
                'mls_compliance_link' => $unbrandedTour,
                'type' => $tourType,
            ] : null,

            'address' => [
                'full' => Arr::get($address, 'full_address'),
                'street' => Arr::get($address, 'street'),
                'suite' => Arr::get($address, 'suite'),
                'city' => Arr::get($address, 'city'),
                'state' => Arr::get($address, 'state'),
                'postal_code' => Arr::get($address, 'postalCode'),
                'country' => Arr::get($address, 'country'),
                'latitude' => Arr::get($address, 'latitude'),
                'longitude' => Arr::get($address, 'longitude'),
            ],

            'listing_floorplans' => [
                'pdf_dim' => $pdfDim,
                'pdf_plain' => $pdfPlain,
                'jpg_dim' => (array) Arr::get($listing, 'jpg_urls_dim', []),
                'jpg_plain' => (array) Arr::get($listing, 'jpg_urls', []),
                'png_dim' => (array) Arr::get($listing, 'png_urls_dim', []),
                'png_plain' => (array) Arr::get($listing, 'png_urls', []),
                'svg_dim' => (array) Arr::get($listing, 'svg_urls_dim', []),
                'svg_plain' => (array) Arr::get($listing, 'svg_urls', []),
                'zip' => (array) Arr::get($listing, 'zip_urls', []),
                'zip_dim' => (array) Arr::get($listing, 'zip_urls_dim', []),
            ],
            'home_report_pdfs' => (array) Arr::get($homeReport, 'pdf_urls', []),
            'snapshot_pdfs' => (array) Arr::get($delivery, 'snapshot.pdf_urls', []),
            'snapshot_images' => (array) Arr::get($delivery, 'snapshot.image_urls', []),
            'floorplans_3d' => $floorplans3d,
            'video_3d' => Arr::get($delivery, 'video_3d'),
            'cad_files' => Arr::get($delivery, 'cad_files'),
            'property_data' => (array) Arr::get($delivery, 'property_data', []),
            'gla_package' => Arr::get($delivery, 'gla_package'),

            'floorplans' => $floorplans,

            'raw_data' => $data,
        ];
    }

    /**
     * Build the slim list of asset specs the ingestion job consumes.
     *
     * Default keeplist (per plan, §"Open decisions" #4):
     *  - 1× pdf_urls_dim (merged dimensioned PDF) — primary deliverable
     *  - 1× pdf_urls (merged plain PDF)
     *  - jpg_urls_dim[*] (per-floor dimensioned previews for the gallery)
     *  - home_report.pdf_urls
     *
     * Skips: png/svg, plain jpg, zips. Zip and floorplans_3d/video_3d added later.
     */
    private function buildFloorplanList(array $listing, array $homeReport): array
    {
        $items = [];

        $pdfDim = (array) Arr::get($listing, 'pdf_urls_dim', []);
        foreach ($pdfDim as $i => $url) {
            if (!is_string($url) || $url === '') continue;
            $items[] = [
                'asset_key' => 'pdf_listing_dim_' . $i,
                'type' => 'pdf',
                'units' => 'imperial', // CubiCasa default; metric/imperial not split here.
                'url' => $url,
                'label' => 'Floor Plan PDF (Dimensioned)',
            ];
        }

        $pdfPlain = (array) Arr::get($listing, 'pdf_urls', []);
        foreach ($pdfPlain as $i => $url) {
            if (!is_string($url) || $url === '') continue;
            $items[] = [
                'asset_key' => 'pdf_listing_' . $i,
                'type' => 'pdf',
                'units' => 'imperial',
                'url' => $url,
                'label' => 'Floor Plan PDF',
            ];
        }

        $jpgDim = (array) Arr::get($listing, 'jpg_urls_dim', []);
        foreach ($jpgDim as $i => $url) {
            if (!is_string($url) || $url === '') continue;
            $items[] = [
                'asset_key' => 'jpg_listing_dim_' . $i,
                'type' => 'jpg',
                'units' => 'imperial',
                'url' => $url,
                'label' => 'Floor ' . ($i + 1) . ' (Dimensioned)',
                'floor_id' => $i,
            ];
        }

        $homeReportPdfs = (array) Arr::get($homeReport, 'pdf_urls', []);
        foreach ($homeReportPdfs as $i => $url) {
            if (!is_string($url) || $url === '') continue;
            $items[] = [
                'asset_key' => 'pdf_home_report_' . $i,
                'type' => 'pdf',
                'units' => 'imperial',
                'url' => $url,
                'label' => 'Home Report',
            ];
        }

        return $items;
    }

    /**
     * Persist parsed CubiCasa data on the Shoot. Idempotent. Mirrors
     * {@see IguideService::applyShootData()}.
     */
    public function applyShootData(Shoot $shoot, array $parsed): Shoot
    {
        // Identifiers
        if (!empty($parsed['order_id'])) {
            $shoot->cubicasa_order_id = (string) $parsed['order_id'];
        }
        if (!empty($parsed['external_id'])) {
            $shoot->cubicasa_external_id = (string) $parsed['external_id'];
        }
        if (!empty($parsed['status'])) {
            $shoot->cubicasa_status = (string) $parsed['status'];
            $shoot->cubicasa_last_status_at = now();
        }
        if (!empty($parsed['product_type'])) {
            $shoot->cubicasa_product_type = (string) $parsed['product_type'];
        }
        if (!empty($parsed['tour_url'])) {
            $shoot->cubicasa_tour_url = (string) $parsed['tour_url'];
        }

        $shoot->cubicasa_floorplans = $parsed['floorplans'] ?? $shoot->cubicasa_floorplans ?? [];

        if (Schema::hasColumn('shoots', 'cubicasa_data')) {
            $shoot->cubicasa_data = $this->buildShootDataPayload($parsed);
        }

        // Tour-link auto-fill is intentionally disabled. CubiCasa is purely a
        // floor-plan deliverable provider for our use case; the optional
        // visithome.ai tour link returned by some CubiCasa products is left
        // in `cubicasa_data.tour` for reference but is NOT promoted to the
        // managed `tour_links` slots.

        $shoot->cubicasa_last_synced_at = now();
        $shoot->save();

        return $shoot;
    }

    /**
     * Build the slim payload stored in the `cubicasa_data` JSON column.
     * Excludes the giant `raw_data` blob to keep row sizes reasonable.
     */
    private function buildShootDataPayload(array $parsed): array
    {
        return [
            'order_id' => $parsed['order_id'] ?? null,
            'external_id' => $parsed['external_id'] ?? null,
            'status' => $parsed['status'] ?? null,
            'product_type' => $parsed['product_type'] ?? null,
            'first_delivered_at' => $parsed['first_delivered_at'] ?? null,
            'tour' => $parsed['tour'] ?? null,
            'tour_url' => $parsed['tour_url'] ?? null,
            'address' => $parsed['address'] ?? null,
            'listing_floorplans' => $parsed['listing_floorplans'] ?? null,
            'home_report_pdfs' => $parsed['home_report_pdfs'] ?? [],
            'snapshot_pdfs' => $parsed['snapshot_pdfs'] ?? [],
            'snapshot_images' => $parsed['snapshot_images'] ?? [],
            'floorplans_3d' => $parsed['floorplans_3d'] ?? null,
            'video_3d' => $parsed['video_3d'] ?? null,
            'cad_files' => $parsed['cad_files'] ?? null,
            'property_data' => $parsed['property_data'] ?? [],
            'gla_package' => $parsed['gla_package'] ?? null,
        ];
    }

    /**
     * Merge auto-discovered CubiCasa branded/MLS URLs into tour_links without
     * overwriting any value an admin has already pasted.
     */
    private function mergeCubicasaTourLinks($existing, array $parsed): array
    {
        $tourLinks = is_array($existing) ? $existing : (
            is_string($existing) ? (json_decode($existing, true) ?: []) : []
        );

        $branded = Arr::get($parsed, 'tour.link');
        $unbranded = Arr::get($parsed, 'tour.mls_compliance_link');

        // Fallbacks when CubiCasa returns a 2D-only order (no visithome.ai tour).
        if (!is_string($branded) || $branded === '') {
            $branded = Arr::first(Arr::get($parsed, 'listing_floorplans.pdf_dim', []))
                ?? Arr::first(Arr::get($parsed, 'listing_floorplans.pdf_plain', []));
        }

        $isBlank = static fn ($value): bool => !is_string($value) || trim($value) === '';

        if ($isBlank($tourLinks['cubicasa_branded'] ?? null) && is_string($branded) && $branded !== '') {
            $tourLinks['cubicasa_branded'] = $branded;
        }
        if ($isBlank($tourLinks['cubicasa_mls'] ?? null) && is_string($unbranded) && $unbranded !== '') {
            $tourLinks['cubicasa_mls'] = $unbranded;
        }
        if ($isBlank($tourLinks['cubicasa'] ?? null) && is_string($branded) && $branded !== '') {
            // Legacy slot read by BrightMls publish pipeline (utils/brightMls.ts).
            $tourLinks['cubicasa'] = $branded;
        }

        return $tourLinks;
    }
}
