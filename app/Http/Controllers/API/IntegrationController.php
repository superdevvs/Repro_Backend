<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\MmmPunchoutSession;
use App\Services\ZillowPropertyService;
use App\Services\BrightMlsService;
use App\Services\IguideService;
use App\Services\DropboxWorkflowService;
use App\Services\MmmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IntegrationController extends Controller
{
    protected $zillowService;
    protected $brightMlsService;
    protected $iguideService;
    protected $dropboxService;
    protected $mmmService;

    public function __construct(
        ZillowPropertyService $zillowService,
        BrightMlsService $brightMlsService,
        IguideService $iguideService,
        DropboxWorkflowService $dropboxService,
        MmmService $mmmService
    ) {
        $this->zillowService = $zillowService;
        $this->brightMlsService = $brightMlsService;
        $this->iguideService = $iguideService;
        $this->dropboxService = $dropboxService;
        $this->mmmService = $mmmService;
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
     * Create MMM punchout session and return redirect URL
     */
    public function mmmPunchout(Request $request, Shoot $shoot)
    {
        $request->validate([
            'file_ids' => 'nullable|array',
            'file_ids.*' => 'integer|exists:shoot_files,id',
            'artwork_url' => 'nullable|string',
            'artwork_file_id' => 'nullable|integer|exists:shoot_files,id',
            'cost_center_number' => 'nullable|string',
            'employee_email' => 'nullable|email',
            'username' => 'nullable|string',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'buyer_cookie' => 'nullable|string',
            'mls_id' => 'nullable|string',
            'price' => 'nullable|string',
            'address' => 'nullable|string',
            'description' => 'nullable|string',
            'start_point' => 'nullable|string',
            'template_external_number' => 'nullable|string',
            'deployment_mode' => 'nullable|string',
            'url_return' => 'nullable|string',
            'order_number' => 'nullable|string',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if ($user->role === 'client' && $shoot->client_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($user->role === 'salesRep' && $shoot->rep_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if (!in_array($user->role, ['admin', 'superadmin', 'client', 'salesRep'], true)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($configError = $this->mmmService->validateConfig()) {
            return response()->json($configError, 400);
        }

        $shoot->loadMissing(['files', 'client']);

        $params = $request->only([
            'artwork_url',
            'artwork_file_id',
            'cost_center_number',
            'employee_email',
            'username',
            'first_name',
            'last_name',
            'buyer_cookie',
            'mls_id',
            'price',
            'address',
            'description',
            'start_point',
            'template_external_number',
            'deployment_mode',
            'url_return',
            'order_number',
        ]);
        $params['file_ids'] = $request->input('file_ids', []);
        $params['user'] = $user;

        $payload = $this->mmmService->buildPunchoutPayload($shoot, $params);
        $result = $this->mmmService->sendPunchoutRequest($payload);

        $session = MmmPunchoutSession::create([
            'shoot_id' => $shoot->id,
            'user_id' => $user->id,
            'buyer_cookie' => $payload['buyer_cookie'] ?? null,
            'cost_center_number' => $payload['cost_center_number'] ?? null,
            'employee_email' => $payload['employee_email'] ?? null,
            'username' => $payload['username'] ?? null,
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'template_external_number' => $payload['template_external_number'] ?? null,
            'order_number' => $params['order_number'] ?? null,
            'redirect_url' => $result['redirect_url'] ?? null,
            'status' => $result['success'] ? 'redirect_ready' : 'error',
            'redirected_at' => $result['success'] ? now() : null,
            'last_error' => $result['success'] ? null : ($result['error'] ?? $result['status_text'] ?? 'MMM punchout failed'),
            'request_payload' => [
                'payload' => $payload,
                'xml' => $result['request_xml'] ?? null,
            ],
            'response_payload' => [
                'status_code' => $result['status_code'] ?? null,
                'status_text' => $result['status_text'] ?? null,
                'redirect_url' => $result['redirect_url'] ?? null,
                'xml' => $result['response_xml'] ?? null,
            ],
        ]);

        $shoot->mmm_status = $result['success'] ? 'punchout_ready' : 'error';
        $shoot->mmm_order_number = $params['order_number'] ?? $shoot->mmm_order_number;
        $shoot->mmm_buyer_cookie = $payload['buyer_cookie'] ?? $shoot->mmm_buyer_cookie;
        $shoot->mmm_redirect_url = $result['redirect_url'] ?? $shoot->mmm_redirect_url;
        $shoot->mmm_last_punchout_at = now();
        $shoot->mmm_last_error = $result['success'] ? null : ($result['error'] ?? $result['status_text'] ?? 'MMM punchout failed');
        $shoot->save();

        return response()->json([
            'success' => (bool) $result['success'],
            'status' => $result['status'] ?? null,
            'redirect_url' => $result['redirect_url'] ?? null,
            'session_id' => $session->id,
            'buyer_cookie' => $payload['buyer_cookie'] ?? null,
            'message' => $result['success'] ? 'MMM punchout created' : ($result['error'] ?? $result['status_text'] ?? 'MMM punchout failed'),
        ], $result['success'] ? 200 : 400);
    }

    /**
     * MMM punchout order return callback (BrowserFormPost)
     */
    public function mmmReturn(Request $request)
    {
        $xml = $request->input('xml') ?? $request->getContent();
        if (!$xml) {
            return response()->json(['success' => false, 'message' => 'Missing XML payload'], 400);
        }

        $parsed = $this->mmmService->parsePunchoutOrderMessage($xml);
        $buyerCookie = $parsed['buyer_cookie'] ?? null;
        $orderNumber = $parsed['order_number'] ?? null;

        $sessionQuery = MmmPunchoutSession::query();
        if ($buyerCookie) {
            $sessionQuery->where('buyer_cookie', $buyerCookie);
        } elseif ($orderNumber) {
            $sessionQuery->where('order_number', $orderNumber);
        }

        $session = $sessionQuery->latest()->first();
        $shoot = $session?->shoot;

        if ($session) {
            $session->update([
                'order_number' => $orderNumber ?? $session->order_number,
                'status' => 'returned',
                'returned_at' => now(),
                'response_payload' => array_merge($session->response_payload ?? [], [
                    'order_xml' => $xml,
                ]),
            ]);
        }

        if ($shoot) {
            $shoot->mmm_status = 'order_returned';
            $shoot->mmm_order_number = $orderNumber ?? $shoot->mmm_order_number;
            $shoot->mmm_last_order_at = now();
            $shoot->save();
        }

        $redirectUrl = $request->query('redirect')
            ?? config('services.mmm.return_redirect_url');

        if ($redirectUrl) {
            $query = http_build_query(array_filter([
                'shoot_id' => $shoot?->id,
                'order_number' => $orderNumber,
                'buyer_cookie' => $buyerCookie,
                'mmm_status' => 'returned',
            ]));
            $separator = str_contains($redirectUrl, '?') ? '&' : '?';
            return redirect()->away($redirectUrl . ($query ? $separator . $query : ''));
        }

        return response()->json([
            'success' => true,
            'message' => 'MMM order received',
            'buyer_cookie' => $buyerCookie,
            'order_number' => $orderNumber,
            'shoot_id' => $shoot?->id,
        ]);
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
            'photos.*.id' => 'nullable|integer|exists:shoot_files,id',
            'photos.*.url' => 'nullable|string',
            'photos.*.filename' => 'nullable|string',
            'photos.*.description' => 'nullable|string',
            'photos.*.roomType' => 'nullable|string',
            'photos.*.selected' => 'nullable|boolean',
            'iguide_tour_url' => 'nullable|string',
            'slideshow_url' => 'nullable|string',
            'documents' => 'nullable|array',
            'documents.*.id' => 'nullable|integer|exists:shoot_files,id',
            'documents.*.url' => 'nullable|string',
            'documents.*.filename' => 'nullable|string',
            'documents.*.visibility' => 'nullable|string',
            'documents.*.description' => 'nullable|string',
        ]);

        try {
            $shoot = Shoot::with('files')->findOrFail($shootId);

            $resolvedPhotos = $this->resolveBrightMlsPhotos($shoot, $request->photos ?? []);
            $resolvedDocuments = $this->resolveBrightMlsDocuments($shoot, $request->documents ?? []);

            // Build manifest data
            $options = [
                'photos' => $resolvedPhotos,
                'iguide_tour_url' => $request->iguide_tour_url ?? $shoot->iguide_tour_url,
                'slideshow_url' => $request->slideshow_url,
                'documents' => $resolvedDocuments,
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
                'message' => $result['error'] ?? $result['message'] ?? ($result['success'] ? 'Published to Bright MLS' : 'Bright MLS publish failed'),
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
            'service' => 'required|in:zillow,bright_mls,iguide,dropbox,mmm',
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
                case 'mmm':
                    $configError = $this->mmmService->validateConfig();
                    $result = $configError
                        ? ['success' => false, 'message' => $configError['error'] ?? 'MMM configuration error', 'details' => $configError]
                        : ['success' => true, 'message' => 'MMM configuration looks valid'];
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

    /**
     * Resolve media URLs for Bright MLS publish payload.
     * Uses file IDs when provided and falls back to request URLs.
     */
    private function resolveBrightMlsPhotos(Shoot $shoot, array $photos): array
    {
        return collect($photos)
            ->map(function ($photo) use ($shoot) {
                $file = null;
                if (!empty($photo['id'])) {
                    $file = $shoot->files->firstWhere('id', $photo['id']);
                }

                $resolvedUrl = $this->resolveBrightMlsMediaUrl($photo['url'] ?? null, $file);
                if (!$resolvedUrl) {
                    Log::warning('Bright MLS photo URL could not be resolved', [
                        'shoot_id' => $shoot->id,
                        'file_id' => $photo['id'] ?? null,
                        'url' => $photo['url'] ?? null,
                    ]);
                    return null;
                }

                return [
                    'url' => $resolvedUrl,
                    'filename' => $photo['filename']
                        ?? $file?->filename
                        ?? basename(parse_url($resolvedUrl, PHP_URL_PATH) ?: $resolvedUrl),
                    'description' => $photo['description'] ?? '',
                    'roomType' => $photo['roomType'] ?? '',
                    'selected' => $photo['selected'] ?? true,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resolveBrightMlsDocuments(Shoot $shoot, array $documents): array
    {
        return collect($documents)
            ->map(function ($doc) use ($shoot) {
                $file = null;
                if (!empty($doc['id'])) {
                    $file = $shoot->files->firstWhere('id', $doc['id']);
                }

                $resolvedUrl = $this->resolveBrightMlsMediaUrl($doc['url'] ?? null, $file);
                if (!$resolvedUrl) {
                    Log::warning('Bright MLS document URL could not be resolved', [
                        'shoot_id' => $shoot->id,
                        'file_id' => $doc['id'] ?? null,
                        'url' => $doc['url'] ?? null,
                    ]);
                    return null;
                }

                return [
                    'url' => $resolvedUrl,
                    'filename' => $doc['filename']
                        ?? $file?->filename
                        ?? basename(parse_url($resolvedUrl, PHP_URL_PATH) ?: $resolvedUrl),
                    'visibility' => $doc['visibility'] ?? null,
                    'description' => $doc['description'] ?? '',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resolveBrightMlsMediaUrl(?string $candidateUrl, ?ShootFile $file = null): ?string
    {
        $candidateUrl = is_string($candidateUrl) ? trim($candidateUrl) : null;

        if ($candidateUrl && Str::startsWith($candidateUrl, ['http://', 'https://'])) {
            return $candidateUrl;
        }

        if ($file) {
            if ($file->url && Str::startsWith($file->url, ['http://', 'https://'])) {
                return $file->url;
            }

            if ($file->path && Str::startsWith($file->path, ['http://', 'https://'])) {
                return $file->path;
            }

            if ($file->path && Storage::disk('public')->exists($file->path)) {
                return Storage::disk('public')->url($file->path);
            }

            if ($file->path && !Str::startsWith($file->path, ['http://', 'https://']) && !$file->dropbox_path) {
                return Storage::disk('public')->url($file->path);
            }

            if ($file->dropbox_path && $this->dropboxService->isEnabled()) {
                $dropboxUrl = $this->dropboxService->getTemporaryLink($file->dropbox_path);
                if ($dropboxUrl) {
                    return $dropboxUrl;
                }
            }
        }

        if ($candidateUrl) {
            if (Storage::disk('public')->exists($candidateUrl)) {
                return Storage::disk('public')->url($candidateUrl);
            }

            if ($this->dropboxService->isEnabled()) {
                $dropboxUrl = $this->dropboxService->getTemporaryLink($candidateUrl);
                if ($dropboxUrl) {
                    return $dropboxUrl;
                }
            }
        }

        return null;
    }
}


