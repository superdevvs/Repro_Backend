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

            // Optional shared-secret verification. CubiCasa's exact header
            // is TBD on first delivery; accept either generic name.
            $secret = (string) (
                $this->loadCubicasaSetting('webhookSecret')
                ?? config('services.cubicasa.webhook_secret', '')
            );
            if ($secret !== '') {
                $signature = (string) (
                    $request->header('X-Cubicasa-Signature')
                    ?: $request->header('X-Hub-Signature-256')
                    ?: $request->header('X-Signature')
                    ?: ''
                );
                if (!$this->verifySignature($rawBody, $signature, $secret)) {
                    Log::warning('CubiCasa webhook: invalid signature');
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid signature',
                    ], 401);
                }
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
                // First-delivery debugging: log header keys so we can pin the
                // exact signature-header name CubiCasa actually sends.
                'headers' => $this->safeHeaderNames($request),
            ]);

            if (!is_string($orderId) || trim($orderId) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing order id',
                ], 200);
            }

            // Idempotency on (orderId, currentStatus, deliveryType) for 30 minutes.
            $idemKey = sprintf(
                'cubicasa:webhook:%s:%s:%s',
                $orderId,
                $currentStatus ?: 'none',
                $deliveryType ?: 'none',
            );
            if (Cache::has($idemKey)) {
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

            // Resolve a Shoot. Precedence: cubicasa_order_id -> external_id (in payload) -> address.
            $shoot = $this->matchShoot($orderId, $data);
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

            // Persist lightweight status info immediately (no follow-up call needed).
            $shoot->cubicasa_order_id = (string) $orderId;
            if (is_string($currentStatus) && $currentStatus !== '') {
                $shoot->cubicasa_status = $currentStatus;
                $shoot->cubicasa_last_status_at = now();
            }
            if (is_string($productType) && $productType !== '') {
                $shoot->cubicasa_product_type = $productType;
            }
            $shoot->save();

            // For Ready / Fixing-resolved: pull full delivery_assets and ingest.
            $shouldFetch = $this->shouldFetchDeliverables($currentStatus, $deliveryType);
            $assetCount = 0;

            if ($shouldFetch) {
                $raw = $this->cubicasa->getOrder((string) $orderId);
                if ($raw) {
                    $parsed = $this->cubicasa->parseOrderData($raw);
                    $this->cubicasa->applyShootData($shoot, $parsed);

                    $floorplans = is_array($parsed['floorplans'] ?? null) ? $parsed['floorplans'] : [];
                    if (!empty($floorplans) && $shoot->hasCubiCasaEligibleService()) {
                        IngestCubiCasaAssetsJob::dispatch($shoot->id, $floorplans);
                        $assetCount = count($floorplans);
                    }
                } else {
                    Log::warning('CubiCasa webhook: order detail fetch failed', [
                        'order_id' => $orderId,
                        'failure' => $this->cubicasa->getLastFailureReason(),
                    ]);
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

    private function matchShoot(string $orderId, array $data): ?Shoot
    {
        $byOrderId = Shoot::where('cubicasa_order_id', $orderId)->first();
        if ($byOrderId) {
            return $byOrderId;
        }

        $externalId = $data['external_id'] ?? data_get($data, 'info.external_id');
        if (is_string($externalId) && trim($externalId) !== '') {
            $woId = trim((string) $externalId);
            $shoot = Shoot::where('cubicasa_external_id', $woId)->first();
            if (!$shoot) {
                if (preg_match('/^shoot[:\-_](\d+)$/i', $woId, $m)) {
                    $shoot = Shoot::find((int) $m[1]);
                } elseif (ctype_digit($woId)) {
                    $shoot = Shoot::find((int) $woId);
                }
            }
            if ($shoot) {
                return $shoot;
            }
        }

        return null;
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
            return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
