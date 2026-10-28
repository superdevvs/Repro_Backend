<?php

namespace App\Http\Controllers;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Shoot;
use App\Services\CubiCasaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handle CubiCasa Integrate API v3 webhook deliveries.
 *
 * The webhook is a status-only notification (no asset URLs):
 *   { id, current_status, previous_status, model_id, product_type, delivery_type }
 *
 * On Ready / Fixing-resolved we follow up with GET /orders/{id} via
 * {@see CubiCasaService} to fetch the full delivery_assets and ingest them.
 *
 * Always return 2xx for accepted/duplicate so CubiCasa doesn't retry; the
 * docs say CubiCasa retries 3 times and gives up.
 */
class CubiCasaWebhookController extends Controller
{
    public function __construct(private readonly CubiCasaService $cubicasa)
    {
    }

    public function handle(Request $request)
    {
        try {
            $rawBody = $request->getContent();
            $data = $request->all();

            // The documented v3 webhook sends the integration API key. Retain
            // HMAC support for existing registrations, but never accept unsigned
            // requests when neither credential validates.
            $secret = trim((string) (
                $this->loadCubicasaSetting('webhookSecret')
                ?? config('services.cubicasa.webhook_secret', '')
            ));
            $apiKey = trim((string) (
                $this->loadCubicasaSetting('apiKey')
                ?? config('services.cubicasa.api_key', '')
            ));
            if ($secret === '' && $apiKey === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Webhook credentials are not configured',
                ], 503);
            }

            $signature = (string) (
                $request->header('X-Cubicasa-Signature')
                ?: $request->header('X-Hub-Signature-256')
                ?: $request->header('X-Signature')
                ?: ''
            );
            $validApiKey = $apiKey !== '' && hash_equals($apiKey, (string) $request->header('api-key', ''));
            if (!$validApiKey && !$this->verifySignature($rawBody, $signature, $secret)) {
                Log::warning('CubiCasa webhook: invalid credentials');
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook credentials',
                ], 401);
            }

            $orderId = $data['id'] ?? $data['order_id'] ?? null;
            $currentStatus = $data['current_status'] ?? $data['status'] ?? null;
            $previousStatus = $data['previous_status'] ?? null;
            $deliveryType = $data['delivery_type'] ?? null;
            $productType = $data['product_type'] ?? null;
            $modelId = $data['model_id'] ?? null;

            Log::info('CubiCasa webhook received', [
                'order_id' => $orderId,
                'current_status' => $currentStatus,
                'previous_status' => $previousStatus,
                'delivery_type' => $deliveryType,
                'product_type' => $productType,
                'headers' => $this->safeHeaderNames($request),
            ]);

            if (!is_string($orderId) || trim($orderId) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing order id',
                ], 200);
            }

            // Ready notifications do not contain a unique revision ID. Even
            // identical payloads can represent a second Quick Edit within seconds.
            // Coalesce status-only events; deduplicate Ready assets by their bytes.
            $shouldFetch = $this->shouldFetchDeliverables($currentStatus, $deliveryType);
            $idemKey = sprintf(
                'cubicasa:webhook:%s:%s:%s',
                $orderId,
                $currentStatus ?: 'none',
                $deliveryType ?: 'none',
            );
            if (!$shouldFetch && Cache::has($idemKey)) {
                Log::info('CubiCasa webhook: duplicate event ignored', [
                    'order_id' => $orderId,
                    'current_status' => $currentStatus,
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Duplicate event ignored',
                    'order_id' => $orderId,
                ]);
            }

            // Resolve existing order links first. Ready notifications contain no
            // address/external ID, so retrieve authoritative order details before
            // attempting to reconcile a separately submitted scan.
            $shoot = $this->matchShootByOrderId($orderId);
            $raw = null;
            if ($shouldFetch) {
                $raw = $this->cubicasa->getOrder($orderId);
                if (!$raw || ($raw['id'] ?? null) !== $orderId) {
                    if ($shoot) {
                        $this->cubicasa->markSyncFailed(
                            $shoot,
                            CubiCasaService::SYNC_STATUS_FAILED,
                            'Webhook received but CubiCasa order detail fetch failed.'
                        );
                    }
                    Log::warning('CubiCasa webhook: order detail fetch failed', [
                        'order_id' => $orderId,
                        'failure' => $this->cubicasa->getLastFailureReason(),
                    ]);
                    // Do not cache this delivery: CubiCasa must be able to retry.
                    return response()->json([
                        'success' => false,
                        'message' => 'CubiCasa order detail fetch failed',
                        'order_id' => $orderId,
                    ], 503);
                }
            }
            if (!$shoot) {
                $shoot = $this->matchShootByExternalId($orderId, $raw ?? $data);
            }
            if (!$shoot && $raw) {
                $shoot = $this->matchDeliveredOrder($raw);
            }
            if (!$shoot) {
                Log::warning('CubiCasa webhook: shoot not found', [
                    'order_id' => $orderId,
                    'external_id' => $data['external_id'] ?? null,
                ]);
                Cache::put($idemKey, true, now()->addMinutes(30));
                return response()->json([
                    'success' => false,
                    'message' => 'Shoot not found',
                    'order_id' => $orderId,
                ], 200);
            }

            // Persist only after a required detail fetch succeeds, so a transient
            // failure cannot turn an unclaimed draft into a progressed order.
            $shoot->cubicasa_order_id = (string) $orderId;
            if (is_string($currentStatus) && $currentStatus !== '') {
                $shoot->cubicasa_status = $currentStatus;
                $shoot->cubicasa_last_status_at = now();
            }
            if (is_string($productType) && $productType !== '') {
                $shoot->cubicasa_product_type = $productType;
            }
            app(\App\Services\Shoots\ShootUnitTourScope::class)->persistProvider($shoot);

            // For Ready / Fixing-resolved: pull full delivery_assets and ingest.
            $assetCount = 0;

            if ($raw) {
                $parsed = $this->cubicasa->parseOrderData($raw);
                $this->cubicasa->applyShootData($shoot, $parsed);

                $floorplans = is_array($parsed['floorplans'] ?? null) ? $parsed['floorplans'] : [];
                if (!empty($floorplans) && $shoot->hasCubiCasaEligibleService()) {
                    IngestCubiCasaAssetsJob::dispatch($shoot->id, $floorplans, $shoot->relationLoaded('tourServiceLine') ? $shoot->getRelation('tourServiceLine')?->id : null, true);
                    $assetCount = count($floorplans);
                }
            }

            Cache::put($idemKey, true, now()->addMinutes(30));

            Log::info('CubiCasa webhook processed', [
                'shoot_id' => $shoot->id,
                'order_id' => $orderId,
                'current_status' => $currentStatus,
                'fetched_deliverables' => $shouldFetch,
                'asset_count' => $assetCount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed',
                'shoot_id' => $shoot->id,
                'order_id' => $orderId,
            ]);

        } catch (\Throwable $e) {
            Log::error('CubiCasa webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // 5xx so CubiCasa retries within its 3-attempt budget.
            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed',
            ], 500);
        }
    }

    private function shouldFetchDeliverables(?string $currentStatus, ?string $deliveryType): bool
    {
        if (!is_string($currentStatus)) {
            return false;
        }
        $status = strtolower(trim($currentStatus));
        if (in_array($status, ['ready'], true)) {
            return true;
        }
        // delivery_type=moved_to_ready also indicates a Ready transition for any product_type.
        if (is_string($deliveryType) && strtolower($deliveryType) === 'moved_to_ready') {
            return true;
        }
        return false;
    }

    private function matchShootByOrderId(string $orderId): ?Shoot
    {
        $scoped = app(\App\Services\Shoots\ShootUnitTourScope::class)->matchProvider('cubicasa', ['cubicasa_order_id' => $orderId]);
        return $scoped ?? Shoot::where('cubicasa_order_id', $orderId)->first();
    }

    private function matchShootByExternalId(string $orderId, array $data): ?Shoot
    {
        $externalId = $data['external_id'] ?? data_get($data, 'info.external_id');
        if (is_string($externalId) && trim($externalId) !== '') {
            $woId = trim((string) $externalId);
            $shoot = app(\App\Services\Shoots\ShootUnitTourScope::class)->matchProvider('cubicasa', ['cubicasa_external_id' => $woId]);
            if (!$shoot) {
                $matches = Shoot::where('cubicasa_external_id', $woId)->limit(2)->get();
                if ($matches->count() > 1) {
                    return null;
                }
                $shoot = $matches->first();
            }
            if (!$shoot) {
                if (preg_match('/^shoot[:\-_](\d+)$/i', $woId, $m)) {
                    $shoot = Shoot::find((int) $m[1]);
                } elseif (ctype_digit($woId)) {
                    $shoot = Shoot::find((int) $woId);
                }
            }
            if ($shoot && $this->canLinkExternalOrder($shoot, $orderId, $woId)) {
                return $shoot;
            }
        }

        return null;
    }

    private function canLinkExternalOrder(Shoot $shoot, string $orderId, string $externalId): bool
    {
        if ($shoot->isInternalTestShoot() || !$shoot->hasCubiCasaEligibleService()
            || in_array($shoot->status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
            || in_array($shoot->workflow_status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
            || (!$shoot->relationLoaded('tourUnit') && $shoot->units()->exists())) {
            return false;
        }
        $existingExternalId = trim((string) $shoot->cubicasa_external_id);
        if ($existingExternalId !== '' && $existingExternalId !== $externalId) {
            return false;
        }
        $existingOrderId = trim((string) $shoot->cubicasa_order_id);
        if ($existingOrderId !== '' && $existingOrderId !== $orderId) {
            return in_array(strtolower(trim((string) $shoot->cubicasa_status)), ['', 'draft', 'new'], true)
                && empty($shoot->cubicasa_floorplans)
                && !$shoot->files()->where('metadata->source', 'cubicasa')->exists();
        }
        return true;
    }

    private function matchDeliveredOrder(array $raw): ?Shoot
    {
        $match = null;
        foreach (Shoot::query()->whereNotNull('scheduled_at')->whereDoesntHave('units')->with('services.category')->lazyById(100) as $candidate) {
            if (!$this->cubicasa->matchesDeliveredOrderToShoot($candidate, $raw)) {
                continue;
            }
            if ($match !== null) {
                // Never let database ordering decide between repeat bookings.
                return null;
            }
            $match = $candidate;
        }
        return $match;
    }

    private function loadCubicasaSetting(string $key): ?string
    {
        try {
            $row = DB::table('settings')->where('key', 'integrations.cubicasa')->first();
            if (!$row) {
                return null;
            }
            $payload = json_decode($row->value ?? '', true);
            if (!is_array($payload)) {
                return null;
            }
            $value = $payload[$key] ?? null;
            return is_string($value) ? trim($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function verifySignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === '' || $secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $provided = preg_replace('/^sha256=/', '', strtolower(trim($signatureHeader))) ?? '';
        return hash_equals($expected, $provided);
    }

    private function safeHeaderNames(Request $request): array
    {
        // Log only header names (not values) so we can identify CubiCasa's
        // signature header without leaking secrets.
        return array_keys($request->headers->all());
    }
}
