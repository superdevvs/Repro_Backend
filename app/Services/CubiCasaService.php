<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Outbound + parsing helper for the CubiCasa Integrate API v3.
 *
 * Mirrors the surface of {@see IguideService}: syncShoot(), parseOrderData(),
 * applyShootData(), plus connection/webhook utilities. Manual order creation is
 * supported via createOrder() with a per-shoot Idempotency-Key (Req 19).
 *
 * Auth: CubiCasa uses an `api-key:` header (NOT `Authorization: Bearer`).
 * Docs: https://integrate.docs.cubi.casa/
 */
class CubiCasaService
{
    /** v3 requires a country on every order; we only serve US properties today. */
    private const DEFAULT_COUNTRY = 'United States';

    /** CubiCasa package tiers. A 2D floor plan is `base`, a 3D floor plan `plus_3d`. */
    private const PACKAGE_BASE = 'base';
    private const PACKAGE_PLUS_3D = 'plus_3d';

    public const FAILURE_NONE = null;
    public const FAILURE_NOT_LINKED = 'not_linked'; // shoot has no cubicasa_order_id
    public const FAILURE_NOT_FOUND = 'not_found';
    public const FAILURE_AUTH = 'auth';
    public const FAILURE_OTHER = 'other';
    /** Required configuration is missing; retrying will not help. */
    public const FAILURE_CONFIG = 'config';

    public const SYNC_STATUS_QUEUED = 'queued';
    public const SYNC_STATUS_RUNNING = 'running';
    public const SYNC_STATUS_SUCCEEDED = 'succeeded';
    public const SYNC_STATUS_FAILED = 'failed';
    public const SYNC_STATUS_NOT_LINKED = 'not_linked';

    private ?string $apiKey;
    private string $baseUrl;
    private ?string $environment;
    private ?string $webhookUrl;
    private ?string $webhookSecret;
    private ?string $lastFailureReason = null;
    private AuditLogService $auditLog;

    public function __construct(?AuditLogService $auditLog = null)
    {
        // Default-construct the audit facade so existing `new CubiCasaService()`
        // call sites (and the read-only tests) keep working without DI.
        $this->auditLog = $auditLog ?? new AuditLogService();

        $settings = $this->loadSettings('integrations.cubicasa');

        $this->apiKey = $settings['apiKey'] ?? config('services.cubicasa.api_key');
        $this->environment = $settings['environment'] ?? config('services.cubicasa.environment', 'production');
        $configuredBaseUrl = $settings['baseUrl'] ?? config('services.cubicasa.base_url');
        $defaultBaseUrl = $this->environment === 'production'
            ? 'https://app.cubi.casa/api/integrate/v3'
            : 'https://qa-customers.cubi.casa/api/integrate/v3';
        $this->baseUrl = rtrim(trim((string) ($configuredBaseUrl ?: $defaultBaseUrl)), '/');
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
        $this->markSyncRunning($shoot);

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
            $this->markSyncFailed(
                $shoot,
                $this->lastFailureReason === self::FAILURE_NOT_LINKED
                    ? self::SYNC_STATUS_NOT_LINKED
                    : self::SYNC_STATUS_FAILED,
                $this->failureMessage($this->lastFailureReason)
            );
            return null;
        }

        $parsed = $this->parseOrderData($raw);
        $this->applyShootData($shoot, $parsed);

        return $parsed;
    }

    /**
     * Manually create a CubiCasa order for a Shoot, or sync the existing one.
     *
     * AC 19.5 — when the Shoot is already linked (order_id or external_id), this
     * syncs the existing order rather than creating a duplicate.
     * AC 19.6 — a per-shoot Idempotency-Key (persisted on
     * `shoots.cubicasa_idempotency_key`) is reused on every create attempt, so a
     * retried or double-clicked request never produces a duplicate order.
     * AC 19.2/19.3 — on success the order is linked via
     * `cubicasa_order_id`/`cubicasa_external_id` and `cubicasa_status` is updated
     * (through {@see applyShootData()}).
     * AC 19.10 — every create or sync writes an Audit_Log entry via AuditLogService.
     *
     * @return array<string, mixed>|null parsed order data, or null on failure.
     */
    public function createOrder(Shoot $shoot, ?User $actor = null, string $source = 'manual'): ?array
    {
        $this->lastFailureReason = null;

        $createEvent = $source === 'auto' ? 'cubicasa.auto_create' : 'cubicasa.manual_create';

        // AC 19.5 — already linked: sync the existing order instead of creating.
        if (!empty($shoot->cubicasa_order_id) || !empty($shoot->cubicasa_external_id)) {
            $parsed = $this->syncShoot($shoot);
            $this->auditLog->record('cubicasa.manual_sync', $actor, $shoot, [
                'outcome' => $parsed ? 'ok' : 'failed',
                'failure_reason' => $parsed ? null : $this->lastFailureReason,
                'order_id' => $shoot->cubicasa_order_id,
            ]);
            return $parsed;
        }

        if (!$this->hasCredentials()) {
            $this->lastFailureReason = self::FAILURE_AUTH;
            return null;
        }

        // v3 rejects an order with no owner_email. Bail out before spending an
        // idempotency key or an HTTP round trip on a request that cannot succeed.
        // Logged at error level deliberately: warnings are dropped under
        // LOG_LEVEL=error, which is exactly how the /orders payload bug stayed
        // invisible for seven weeks.
        if (trim((string) config('services.cubicasa.owner_email')) === '') {
            $this->lastFailureReason = self::FAILURE_CONFIG;
            Log::error('CubiCasa createOrder skipped: CUBICASA_OWNER_EMAIL is not configured.', [
                'shoot_id' => $shoot->id,
            ]);
            $this->auditLog->record($createEvent, $actor, $shoot, [
                'outcome' => 'failed',
                'failure_reason' => $this->lastFailureReason,
            ]);

            return null;
        }

        // AC 19.6 — reuse a per-shoot Idempotency-Key so repeated create requests
        // never duplicate the order. Persist it on first use.
        $idempotencyKey = $shoot->cubicasa_idempotency_key
            ?? tap(Str::uuid()->toString(), function (string $key) use ($shoot): void {
                $shoot->cubicasa_idempotency_key = $key;
                $shoot->save();
            });

        try {
            $resp = $this->client()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($this->baseUrl . '/orders/draft', $this->buildOrderPayload($shoot));
        } catch (\Throwable $e) {
            $this->lastFailureReason = self::FAILURE_OTHER;
            Log::error('CubiCasa createOrder exception', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
            $this->auditLog->record($createEvent, $actor, $shoot, [
                'outcome' => 'failed',
                'failure_reason' => $this->lastFailureReason,
            ]);
            return null;
        }

        if (!$resp->successful()) {
            $this->lastFailureReason = $this->classifyFailure($resp);
            Log::warning('CubiCasa createOrder failed', [
                'shoot_id' => $shoot->id,
                'status' => $resp->status(),
                'body' => substr((string) $resp->body(), 0, 500),
            ]);
            $this->auditLog->record($createEvent, $actor, $shoot, [
                'outcome' => 'failed',
                'failure_reason' => $this->lastFailureReason,
            ]);
            return null;
        }

        $parsed = $this->parseOrderData($resp->json());
        // AC 19.2/19.3 — link via cubicasa_order_id/external_id + update status.
        $this->applyShootData($shoot, $parsed);

        $this->auditLog->record($createEvent, $actor, $shoot, [
            'outcome' => 'ok',
            'order_id' => $shoot->cubicasa_order_id,
            'external_id' => $shoot->cubicasa_external_id,
        ]);

        return $parsed;
    }

    /**
     * Build the `POST /orders/draft` request body.
     *
     * v3 takes a FLAT body — street/city/country are top-level and required,
     * `info` is a free-text string (not an object), and `owner_email` must name
     * a user in our CubiCasa company account. Nesting these under an `address`
     * object, as this builder used to, is rejected with HTTP 400
     * "field required". See the contract:
     * https://integrate.docs.cubi.casa/create-a-draft-order-20093452e0
     *
     * The external_id is scoped to the Shoot (`shoot-{id}`) so the order can be
     * matched back to this Shoot by {@see findOrderByExternalId()} and the
     * webhook resolver.
     *
     * @return array<string, mixed>
     */
    private function buildOrderPayload(Shoot $shoot): array
    {
        // property_details is cast to 'array' on the Shoot model, so an is_array() check suffices.
        $details = is_array($shoot->property_details) ? $shoot->property_details : [];

        $suite = $details['apt_suite'] ?? $details['aptSuite'] ?? $details['suite'] ?? null;

        $payload = array_filter([
            'street' => $shoot->address,
            'suite' => is_string($suite) && trim($suite) !== '' ? $suite : null, // Req 7.1
            'city' => $shoot->city,
            'state' => $shoot->state,
            'postalCode' => $shoot->zip,
            'external_id' => 'shoot-' . $shoot->id, // Req 7.3
            'info' => 'REPRO shoot ' . $shoot->id,
            'owner_email' => config('services.cubicasa.owner_email'),
            'package_type' => $this->resolvePackageType($shoot),
        ], static fn ($value): bool => is_string($value) ? trim($value) !== '' : $value !== null);

        // Req 7.2 — country is required by v3, so it is always sent. We only
        // serve US properties today and a Shoot has no country attribute.
        $payload['country'] = self::DEFAULT_COUNTRY;

        return $payload;
    }

    /**
     * Map the shoot's booked services onto a CubiCasa package tier.
     *
     * A shoot selling both a 2D and a 3D floor plan places one order at the
     * higher tier rather than two orders.
     */
    private function resolvePackageType(Shoot $shoot): string
    {
        $services = $shoot->relationLoaded('services')
            ? $shoot->services
            : $shoot->services()->get();

        foreach ($services as $service) {
            if (str_contains(strtolower((string) $service->name), '3d floor')) {
                return self::PACKAGE_PLUS_3D;
            }
        }

        return self::PACKAGE_BASE;
    }
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

        // Only claim success once the shoot is actually linked. A 2xx whose body
        // carries no order id leaves it unlinked, and stamping "succeeded" there
        // reports a healthy sync for a shoot that has no order — in the UI that
        // is indistinguishable from a real success, and it is precisely how a
        // broken integration can look fine.
        $isLinked = !empty($shoot->cubicasa_order_id) || !empty($shoot->cubicasa_external_id);

        if ($isLinked) {
            $shoot->cubicasa_last_synced_at = now();
            if (Schema::hasColumn('shoots', 'cubicasa_sync_status')) {
                $shoot->cubicasa_sync_status = self::SYNC_STATUS_SUCCEEDED;
            }
            if (Schema::hasColumn('shoots', 'cubicasa_last_sync_error')) {
                $shoot->cubicasa_last_sync_error = null;
            }
        } else {
            Log::error('CubiCasa returned a success response with no order id; shoot left unlinked.', [
                'shoot_id' => $shoot->id,
            ]);
            if (Schema::hasColumn('shoots', 'cubicasa_sync_status')) {
                $shoot->cubicasa_sync_status = self::SYNC_STATUS_FAILED;
            }
            if (Schema::hasColumn('shoots', 'cubicasa_last_sync_error')) {
                $shoot->cubicasa_last_sync_error = 'Provider returned no order id.';
            }
        }

        $shoot->save();

        return $shoot;
    }

    public function markSyncQueued(Shoot $shoot, ?string $jobId = null): Shoot
    {
        $jobId ??= (string) Str::uuid();

        $this->fillSyncColumns($shoot, [
            'cubicasa_sync_status' => self::SYNC_STATUS_QUEUED,
            'cubicasa_sync_job_id' => $jobId,
            'cubicasa_sync_started_at' => now(),
            'cubicasa_last_sync_error' => null,
        ]);

        $shoot->save();

        return $shoot;
    }

    public function markSyncRunning(Shoot $shoot, ?string $jobId = null): Shoot
    {
        $jobId ??= $shoot->cubicasa_sync_job_id ?: (string) Str::uuid();

        $this->fillSyncColumns($shoot, [
            'cubicasa_sync_status' => self::SYNC_STATUS_RUNNING,
            'cubicasa_sync_job_id' => $jobId,
            'cubicasa_sync_started_at' => now(),
            'cubicasa_last_sync_error' => null,
        ]);

        $shoot->save();

        return $shoot;
    }

    public function markSyncFailed(Shoot $shoot, string $status, string $error): Shoot
    {
        $this->fillSyncColumns($shoot, [
            'cubicasa_sync_status' => $status,
            'cubicasa_last_sync_error' => $error,
        ]);

        $shoot->save();

        return $shoot;
    }

    public function isSyncInProgress(Shoot $shoot): bool
    {
        $status = (string) ($shoot->cubicasa_sync_status ?? '');
        if (!in_array($status, [self::SYNC_STATUS_QUEUED, self::SYNC_STATUS_RUNNING], true)) {
            return false;
        }

        $startedAt = $shoot->cubicasa_sync_started_at;
        if (!$startedAt) {
            return true;
        }

        return $startedAt->greaterThan(now()->subMinutes(10));
    }

    public function syncStatePayload(Shoot $shoot): array
    {
        return [
            'sync_status' => $shoot->cubicasa_sync_status,
            'sync_job_id' => $shoot->cubicasa_sync_job_id,
            'sync_started_at' => optional($shoot->cubicasa_sync_started_at)->toIso8601String(),
            'last_synced_at' => optional($shoot->cubicasa_last_synced_at)->toIso8601String(),
            'last_sync_error' => $shoot->cubicasa_last_sync_error,
        ];
    }

    private function fillSyncColumns(Shoot $shoot, array $attributes): void
    {
        foreach ($attributes as $column => $value) {
            if (Schema::hasColumn('shoots', $column)) {
                $shoot->{$column} = $value;
            }
        }
    }

    private function failureMessage(?string $reason): string
    {
        return match ($reason) {
            self::FAILURE_AUTH => 'CubiCasa API key invalid or missing.',
            self::FAILURE_NOT_FOUND => 'CubiCasa order not found.',
            self::FAILURE_NOT_LINKED => 'No CubiCasa order linked to this shoot.',
            default => 'Failed to fetch CubiCasa order.',
        };
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
