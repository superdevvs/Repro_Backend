<?php

namespace App\Http\Controllers;

use App\Jobs\IngestIguideAssetsJob;
use App\Models\Shoot;
use App\Services\IguideService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IguideWebhookController extends Controller
{
    public function __construct(private readonly IguideService $iguideService)
    {
    }

    /**
     * Handle iGUIDE webhook requests.
     *
     * Per docs: must return 2xx for accepted/duplicate so iGUIDE does not retry;
     * return 5xx only when delivery should be re-attempted.
     */
    public function handle(Request $request)
    {
        try {
            $rawBody = $request->getContent();
            $data = $request->all();

            // Optional shared-secret verification (HMAC-SHA256 of raw body).
            $secret = (string) (
                $this->loadIguideSetting('webhookSecret')
                ?? config('services.iguide.webhook_secret', '')
            );
            if ($secret !== '') {
                $signature = (string) ($request->header('X-Iguide-Signature') ?: $request->header('X-Signature') ?: '');
                if (!$this->verifySignature($rawBody, $signature, $secret)) {
                    Log::warning('iGUIDE webhook: invalid signature');
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid signature',
                    ], 401);
                }
            }

            Log::info('iGUIDE webhook received', [
                'type' => $data['type'] ?? null,
                'iguide_id' => $data['iguideId'] ?? $data['property_id'] ?? null,
                'work_order_id' => $data['workOrderId'] ?? $data['work_order_id'] ?? null,
            ]);

            $propertyId = $data['property_id'] ?? $data['propertyId'] ?? $data['iguideId'] ?? null;
            $workOrderId = $data['workOrderId'] ?? $data['work_order_id'] ?? null;
            $tourUrl = $data['tour_url']
                ?? $data['tourUrl']
                ?? data_get($data, 'urls.publicUrl')
                ?? data_get($data, 'urls.unbrandedUrl');
            $eventType = $data['event_type'] ?? $data['eventType'] ?? $data['type'] ?? null;
            $webhookAddress = data_get($data, 'property.fullAddress')
                ?? data_get($data, 'address.fullAddress')
                ?? (is_string($data['address'] ?? null) ? $data['address'] : null);

            // Matching precedence: workOrderId -> property/iguide id -> address.
            $shoot = $this->matchShoot($workOrderId, $propertyId, $webhookAddress);

            if (!$shoot) {
                Log::warning('iGUIDE webhook: shoot not found', [
                    'property_id' => $propertyId,
                    'work_order_id' => $workOrderId,
                    'address' => $webhookAddress,
                ]);
                // Return 200 — there is nothing for iGuide to retry.
                return response()->json([
                    'success' => false,
                    'message' => 'Shoot not found',
                ], 200);
            }

            // Idempotency: skip duplicate `ready` events within 30 minutes for the same property+content hash.
            $idempotencyKey = $this->idempotencyKey($shoot->id, $propertyId, $eventType, $rawBody);
            if (Cache::has($idempotencyKey)) {
                Log::info('iGUIDE webhook: duplicate event ignored', [
                    'shoot_id' => $shoot->id,
                    'property_id' => $propertyId,
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Duplicate event ignored',
                    'shoot_id' => $shoot->id,
                ]);
            }

            $iguideData = $this->iguideService->parsePropertyData($data);
            if ($tourUrl && empty($iguideData['tour_url'])) {
                $iguideData['tour_url'] = $tourUrl;
            }
            if ($propertyId && empty($iguideData['property_id'])) {
                $iguideData['property_id'] = (string) $propertyId;
            }
            if ($workOrderId && empty($iguideData['work_order_id'])) {
                $iguideData['work_order_id'] = (string) $workOrderId;
            }

            $this->iguideService->applyShootData($shoot, $iguideData);

            $floorplans = is_array($iguideData['floorplans'] ?? null) ? $iguideData['floorplans'] : [];
            // Only ingest deliverables (PDFs / JPG floors) when this shoot
            // actually booked a floorplan / iGuide service. Light metadata
            // (tour URL, property id, link slots) is still applied above.
            if (!empty($floorplans) && $shoot->hasIguideEligibleService()) {
                IngestIguideAssetsJob::dispatch($shoot->id, $floorplans);
            }

            Cache::put($idempotencyKey, true, now()->addMinutes(30));

            Log::info('iGUIDE webhook processed successfully', [
                'shoot_id' => $shoot->id,
                'property_id' => $propertyId,
                'work_order_id' => $workOrderId,
                'event_type' => $eventType,
                'asset_count' => count($floorplans),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed',
                'shoot_id' => $shoot->id,
            ]);

        } catch (\Exception $e) {
            Log::error('iGUIDE webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 5xx so iGuide retries.
            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed',
            ], 500);
        }
    }

    /**
     * Resolve the matching Shoot using workOrderId -> property id -> address.
     */
    private function matchShoot(?string $workOrderId, ?string $propertyId, ?string $address): ?Shoot
    {
        if (is_string($workOrderId) && trim($workOrderId) !== '') {
            $woId = trim((string) $workOrderId);
            $shoot = Shoot::where('iguide_work_order_id', $woId)->first();
            if (!$shoot) {
                // Accept conventional `shoot:{id}` work order references too.
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

        if (is_string($propertyId) && trim($propertyId) !== '') {
            $shoot = Shoot::where('iguide_property_id', trim((string) $propertyId))->first();
            if ($shoot) {
                return $shoot;
            }
        }

        if (is_string($address) && trim($address) !== '') {
            return $this->iguideService->findShootByAddress($address);
        }

        return null;
    }

    private function idempotencyKey(int $shootId, ?string $propertyId, ?string $eventType, string $rawBody): string
    {
        return sprintf(
            'iguide:webhook:%d:%s:%s:%s',
            $shootId,
            $propertyId ?: 'none',
            $eventType ?: 'none',
            substr(sha1($rawBody), 0, 16),
        );
    }

    private function loadIguideSetting(string $key): ?string
    {
        try {
            $row = DB::table('settings')->where('key', 'integrations.iguide')->first();
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
        // Tolerate `sha256=` prefix.
        $provided = preg_replace('/^sha256=/', '', strtolower(trim($signatureHeader))) ?? '';
        return hash_equals($expected, $provided);
    }
}
