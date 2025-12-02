<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IguideWebhookController extends Controller
{
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

            // Parse webhook data based on iGUIDE's format
            $propertyId = $data['property_id'] ?? $data['propertyId'] ?? null;
            $tourUrl = $data['tour_url'] ?? $data['tourUrl'] ?? null;
            $eventType = $data['event_type'] ?? $data['eventType'] ?? $data['type'] ?? null;

            if (!$propertyId) {
                Log::warning('iGUIDE webhook missing property_id', [
                    'data' => $data,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Missing property_id',
                ], 400);
            }

            // Find shoot by iGUIDE property ID
            $shoot = Shoot::where('iguide_property_id', $propertyId)->first();

            if (!$shoot) {
                Log::warning('iGUIDE webhook: Shoot not found for property_id', [
                    'property_id' => $propertyId,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Shoot not found',
                ], 404);
            }

            // Update shoot with latest iGUIDE data
            if ($tourUrl) {
                $shoot->iguide_tour_url = $tourUrl;
            }

            if (isset($data['floorplans'])) {
                $shoot->iguide_floorplans = is_array($data['floorplans']) 
                    ? $data['floorplans'] 
                    : json_decode($data['floorplans'], true);
            }

            $shoot->iguide_last_synced_at = now();
            $shoot->save();

            Log::info('iGUIDE webhook processed successfully', [
                'shoot_id' => $shoot->id,
                'property_id' => $propertyId,
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


