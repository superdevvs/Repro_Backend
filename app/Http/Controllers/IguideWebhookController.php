<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use App\Services\IguideService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IguideWebhookController extends Controller
{
    public function __construct(private readonly IguideService $iguideService)
    {
    }

    /**
     * Handle iGUIDE webhook requests
     */
    public function handle(Request $request)
    {
        try {
            $data = $request->all();
            
            Log::info('iGUIDE webhook received', [
                'data' => $data,
            ]);

            $propertyId = $data['property_id'] ?? $data['propertyId'] ?? $data['iguideId'] ?? null;
            $tourUrl = $data['tour_url']
                ?? $data['tourUrl']
                ?? data_get($data, 'urls.publicUrl')
                ?? data_get($data, 'urls.unbrandedUrl');
            $eventType = $data['event_type'] ?? $data['eventType'] ?? $data['type'] ?? null;
            $webhookAddress = data_get($data, 'property.fullAddress')
                ?? data_get($data, 'address.fullAddress')
                ?? (is_string($data['address'] ?? null) ? $data['address'] : null);

            $shoot = null;
            if ($propertyId) {
                $shoot = Shoot::where('iguide_property_id', $propertyId)->first();
            }

            if (!$shoot && $webhookAddress) {
                $shoot = $this->iguideService->findShootByAddress($webhookAddress);
            }

            if (!$shoot) {
                Log::warning('iGUIDE webhook: Shoot not found', [
                    'property_id' => $propertyId,
                    'address' => $webhookAddress,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Shoot not found',
                ], 404);
            }

            $iguideData = $this->iguideService->parsePropertyData($data);
            if ($tourUrl && empty($iguideData['tour_url'])) {
                $iguideData['tour_url'] = $tourUrl;
            }

            if ($propertyId && empty($iguideData['property_id'])) {
                $iguideData['property_id'] = $propertyId;
            }

            $this->iguideService->applyShootData($shoot, $iguideData);

            Log::info('iGUIDE webhook processed successfully', [
                'shoot_id' => $shoot->id,
                'property_id' => $propertyId,
                'event_type' => $eventType,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed',
            ]);

        } catch (\Exception $e) {
            Log::error('iGUIDE webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed',
            ], 500);
        }
    }
}


