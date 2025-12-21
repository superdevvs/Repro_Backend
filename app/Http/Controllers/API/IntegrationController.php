<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\ZillowPropertyService;
use App\Services\BrightMlsService;
use App\Services\IguideService;
use App\Services\DropboxWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    protected $zillowService;
    protected $brightMlsService;
    protected $iguideService;
    protected $dropboxService;

    public function __construct(
        ZillowPropertyService $zillowService,
        BrightMlsService $brightMlsService,
        IguideService $iguideService,
        DropboxWorkflowService $dropboxService
    ) {
        $this->zillowService = $zillowService;
        $this->brightMlsService = $brightMlsService;
        $this->iguideService = $iguideService;
        $this->dropboxService = $dropboxService;
    }

    /**
     * Lookup property details from Zillow/Bridge
     * Optionally saves to a shoot if shoot_id is provided
     */
    public function lookupProperty(Request $request)
    {
        $request->validate([
            'address' => 'required|string',
            'mls_id' => 'nullable|string',
            'shoot_id' => 'nullable|exists:shoots,id',
        ]);

        try {
            $propertyData = $this->zillowService->fetchPropertyDetails(
                $request->address,
                $request->mls_id
            );

            if (!$propertyData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }

            // Optionally save to shoot if shoot_id provided
            if ($request->shoot_id) {
                $shoot = Shoot::findOrFail($request->shoot_id);
                $shoot->property_details = $propertyData;
                if ($propertyData['mls_id'] && !$shoot->mls_id) {
                    $shoot->mls_id = $propertyData['mls_id'];
                }
                $shoot->save();
            }

            return response()->json([
                'success' => true,
                'data' => $propertyData,
            ]);

        } catch (\Exception $e) {
            Log::error('Property lookup failed', [
                'address' => $request->address,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to lookup property: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh property details for a shoot
     */
    public function refreshPropertyDetails($shootId)
    {
        try {
            $shoot = Shoot::findOrFail($shootId);

            $fullAddress = "{$shoot->address}, {$shoot->city}, {$shoot->state} {$shoot->zip}";
            $propertyData = $this->zillowService->fetchPropertyDetails($fullAddress, $shoot->mls_id);

            if ($propertyData) {
                // Update property details and also update basic fields if not set
                $shoot->property_details = $propertyData;
                
                // Optionally update mls_id if found in property data
                if ($propertyData['mls_id'] && !$shoot->mls_id) {
                    $shoot->mls_id = $propertyData['mls_id'];
                }
                
                $shoot->save();

                return response()->json([
                    'success' => true,
                    'data' => $propertyData,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Property not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('Refresh property details failed', [
                'shoot_id' => $shootId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh property details',
            ], 500);
        }
    }

    /**
     * Sync iGUIDE data for a shoot
     */
    public function syncIguide($shootId)
    {
        try {
            $shoot = Shoot::findOrFail($shootId);

            // Try to find iGUIDE property by address
            $fullAddress = "{$shoot->address}, {$shoot->city}, {$shoot->state} {$shoot->zip}";
            $iguideData = $this->iguideService->searchByAddress($fullAddress);

            if (!$iguideData && $shoot->iguide_property_id) {
                // If we have a stored property ID, try that
                $iguideData = $this->iguideService->syncProperty($shoot->iguide_property_id);
            }

            if (!$iguideData) {
                return response()->json([
                    'success' => false,
                    'message' => 'iGUIDE property not found',
                ], 404);
            }

            // Update shoot with iGUIDE data
            $shoot->iguide_tour_url = $iguideData['tour_url'];
            $shoot->iguide_floorplans = $iguideData['floorplans'] ?? [];
            $shoot->iguide_property_id = $iguideData['property_id'];
            $shoot->iguide_last_synced_at = now();
            $shoot->save();

            return response()->json([
                'success' => true,
                'data' => $iguideData,
            ]);

        } catch (\Exception $e) {
            Log::error('iGUIDE sync failed', [
                'shoot_id' => $shootId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync iGUIDE data',
            ], 500);
        }
    }

    /**
     * Publish to Bright MLS
     */
    public function publishToBrightMls(Request $request, $shootId)
    {
        $request->validate([
            'photos' => 'nullable|array',
            'photos.*.url' => 'required|string',
            'photos.*.selected' => 'nullable|boolean',
            'iguide_tour_url' => 'nullable|string',
            'slideshow_url' => 'nullable|string',
            'documents' => 'nullable|array',
        ]);

        try {
            $shoot = Shoot::with('files')->findOrFail($shootId);

            // Build manifest data
            $options = [
                'photos' => $request->photos ?? [],
                'iguide_tour_url' => $request->iguide_tour_url ?? $shoot->iguide_tour_url,
                'slideshow_url' => $request->slideshow_url,
                'documents' => $request->documents ?? [],
            ];

            $manifestData = $this->brightMlsService->buildManifestFromShoot($shoot->toArray(), $options);
            $result = $this->brightMlsService->publishManifest($manifestData);

            // Update shoot with publish status
            $shoot->bright_mls_publish_status = $result['status'];
            $shoot->bright_mls_last_published_at = $result['success'] ? now() : null;
            $shoot->bright_mls_response = json_encode($result);
            $shoot->bright_mls_manifest_id = $result['manifest_id'] ?? null;
            $shoot->save();

            return response()->json([
                'success' => $result['success'],
                'status' => $result['status'],
                'data' => $result,
            ], $result['success'] ? 200 : 400);

        } catch (\Exception $e) {
            Log::error('Bright MLS publish failed', [
                'shoot_id' => $shootId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to publish to Bright MLS',
            ], 500);
        }
    }

    /**
     * Get MLS publishing queue
     */
    public function getMlsQueue(Request $request)
    {
        try {
            $query = Shoot::with(['client', 'photographer'])
                ->whereNotNull('mls_id')
                ->orderBy('bright_mls_last_published_at', 'desc');

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('bright_mls_publish_status', $request->status);
            }

            $shoots = $query->get()->map(function ($shoot) {
                return [
                    'id' => $shoot->id,
                    'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state}",
                    'mls_id' => $shoot->mls_id,
                    'client' => $shoot->client ? $shoot->client->name : 'Unknown',
                    'photographer' => $shoot->photographer ? $shoot->photographer->name : 'Unassigned',
                    'status' => $shoot->bright_mls_publish_status,
                    'last_published' => $shoot->bright_mls_last_published_at,
                    'manifest_id' => $shoot->bright_mls_manifest_id,
                    'response' => $shoot->bright_mls_response ? (
                        is_string($shoot->bright_mls_response) 
                            ? json_decode($shoot->bright_mls_response, true) 
                            : $shoot->bright_mls_response
                    ) : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $shoots,
            ]);

        } catch (\Exception $e) {
            Log::error('Get MLS queue failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch MLS queue',
            ], 500);
        }
    }

    /**
     * Test integration connections
     */
    public function testConnection(Request $request)
    {
        $request->validate([
            'service' => 'required|in:zillow,bright_mls,iguide,dropbox',
        ]);

        try {
            $service = $request->service;
            $result = [];

            switch ($service) {
                case 'zillow':
                    $result = $this->zillowService->testConnection();
                    break;
                case 'bright_mls':
                    $result = $this->brightMlsService->testConnection();
                    break;
                case 'iguide':
                    $result = $this->iguideService->testConnection();
                    break;
                case 'dropbox':
                    $result = $this->dropboxService->testConnection();
                    break;
            }

            return response()->json([
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? ($result['success'] ? 'Connection successful' : 'Connection failed'),
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Dropbox storage status
     */
    public function getDropboxStatus()
    {
        try {
            $enabled = config('services.dropbox.enabled', false);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'enabled' => $enabled,
                    'configured' => !empty(config('services.dropbox.access_token')),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}


