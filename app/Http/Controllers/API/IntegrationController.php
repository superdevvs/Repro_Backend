<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\MmmPunchoutSession;
use App\Jobs\IngestIguideAssetsJob;
use App\Jobs\IngestCubiCasaAssetsJob;
use App\Jobs\SyncCubiCasaShootJob;
use App\Services\ZillowPropertyService;
use App\Services\BrightMlsService;
use App\Services\IguideService;
use App\Services\CubiCasaService;
use App\Services\DropboxWorkflowService;
use App\Services\MmmService;
use App\Services\ShootActivityLogger;
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
    protected $cubicasaService;
    protected $dropboxService;
    protected $mmmService;
    protected $activityLogger;

    public function __construct(
        ZillowPropertyService $zillowService,
        BrightMlsService $brightMlsService,
        IguideService $iguideService,
        CubiCasaService $cubicasaService,
        DropboxWorkflowService $dropboxService,
        MmmService $mmmService,
        ShootActivityLogger $activityLogger
    ) {
        $this->zillowService = $zillowService;
        $this->brightMlsService = $brightMlsService;
        $this->iguideService = $iguideService;
        $this->cubicasaService = $cubicasaService;
        $this->dropboxService = $dropboxService;
        $this->mmmService = $mmmService;
        $this->activityLogger = $activityLogger;
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

        $returnToken = Str::random(64);
        $payload = $this->mmmService->buildPunchoutPayload($shoot, $params);
        $payload['url_return'] = $this->appendMmmReturnToken($payload['url_return'] ?? null, $returnToken);
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
            'return_token' => $returnToken,
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
     * List recent MMM punchout sessions for a shoot with shoot-level summary.
     */
    public function mmmSessions(Request $request, Shoot $shoot)
    {
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

        $rows = $shoot->mmmPunchoutSessions()
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $sessions = $rows->map(function (MmmPunchoutSession $session) {
            $requestPayload = is_array($session->request_payload) ? $session->request_payload : [];
            $responsePayload = is_array($session->response_payload) ? $session->response_payload : [];

            $innerPayload = $requestPayload['payload'] ?? [];
            $deploymentMode = is_array($innerPayload) ? ($innerPayload['deployment_mode'] ?? null) : null;

            $order = $responsePayload['order'] ?? null;
            if (!is_array($order)) {
                $order = null;
            }

            return [
                'id' => $session->id,
                'shoot_id' => $session->shoot_id,
                'user_id' => $session->user_id,
                'status' => $session->status,
                'order_number' => $session->order_number,
                'buyer_cookie' => $session->buyer_cookie,
                'redirect_url' => $session->redirect_url,
                'redirected_at' => optional($session->redirected_at)->toIso8601String(),
                'returned_at' => optional($session->returned_at)->toIso8601String(),
                'last_error' => $session->last_error,
                'employee_email' => $session->employee_email,
                'username' => $session->username,
                'first_name' => $session->first_name,
                'last_name' => $session->last_name,
                'created_at' => optional($session->created_at)->toIso8601String(),
                'deployment_mode' => $deploymentMode,
                'order' => $order,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'sessions' => $sessions,
            'summary' => [
                'mmm_status' => $shoot->mmm_status,
                'mmm_order_number' => $shoot->mmm_order_number,
                'mmm_buyer_cookie' => $shoot->mmm_buyer_cookie,
                'mmm_redirect_url' => $shoot->mmm_redirect_url,
                'mmm_last_punchout_at' => optional($shoot->mmm_last_punchout_at)->toIso8601String(),
                'mmm_last_order_at' => optional($shoot->mmm_last_order_at)->toIso8601String(),
                'mmm_last_error' => $shoot->mmm_last_error,
            ],
        ]);
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

        // Require BuyerCookie and resolve session only by exact cookie match (never unconstrained latest()).
        if ($buyerCookie === null || $buyerCookie === '') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $session = MmmPunchoutSession::query()
            ->where('buyer_cookie', $buyerCookie)
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $requestToken = (string) ($request->query('return_token') ?? $request->input('return_token') ?? '');
        $sessionToken = (string) ($session->return_token ?? '');
        if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $shoot = $session->shoot;

        $orderSummary = [
            'items' => $parsed['items'] ?? [],
            'subtotal' => $parsed['subtotal'] ?? null,
            'tax' => $parsed['tax'] ?? null,
            'shipping' => $parsed['shipping'] ?? null,
            'total' => $parsed['total'] ?? null,
            'currency' => $parsed['currency'] ?? null,
        ];

        $session->update([
            'order_number' => $orderNumber ?? $session->order_number,
            'status' => 'returned',
            'returned_at' => now(),
            'response_payload' => array_merge($session->response_payload ?? [], [
                'order_xml' => $xml,
                'order' => $orderSummary,
            ]),
        ]);

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

            $iguideData = $this->iguideService->syncShoot($shoot);

            if (!$iguideData) {
                $reason = $this->iguideService->getLastFailureReason();
                if ($reason === \App\Services\IguideService::FAILURE_WEBHOOK_ONLY) {
                    return response()->json([
                        'success' => false,
                        'mode' => 'webhook-only',
                        'message' => 'iGUIDE App Tokens cannot fetch data on demand. The shoot will be auto-populated when the iGuide is published and the ready webhook fires.',
                    ], 409);
                }
                return response()->json([
                    'success' => false,
                    'mode' => 'not-found',
                    'message' => 'iGUIDE property not found',
                ], 404);
            }

            $floorplans = is_array($iguideData['floorplans'] ?? null) ? $iguideData['floorplans'] : [];
            // Only download deliverables when this shoot booked a floorplan /
            // iGuide service; otherwise just refresh metadata + auto-link slots.
            $shouldIngest = !empty($floorplans) && $shoot->hasIguideEligibleService();
            if ($shouldIngest) {
                IngestIguideAssetsJob::dispatch($shoot->id, $floorplans);
            }

            // Refresh model to expose newly persisted iguide_data / iguide_work_order_id.
            $shoot->refresh();

            return response()->json([
                'success' => true,
                'data' => $iguideData,
                'shoot' => [
                    'id' => $shoot->id,
                    'iguide_tour_url' => $shoot->iguide_tour_url,
                    'iguide_property_id' => $shoot->iguide_property_id,
                    'iguide_work_order_id' => $shoot->iguide_work_order_id,
                    'iguide_floorplans' => $shoot->iguide_floorplans,
                    'iguide_data' => $shoot->iguide_data,
                    'iguide_last_synced_at' => optional($shoot->iguide_last_synced_at)->toIso8601String(),
                ],
                'queued_assets' => $shouldIngest ? count($floorplans) : 0,
                'ingested' => $shouldIngest,
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
     * Sync CubiCasa data for a shoot. Mirrors syncIguide() but reads work
     * fully (no webhook-only mode), so success is the common case.
     */
    public function syncCubicasa(Request $request, $shootId)
    {
        try {
            $shoot = Shoot::findOrFail($shootId);

            // If the shoot has neither order_id nor external_id, there's nothing
            // to sync against. Surface a 409 with guidance instead of 500.
            if (empty($shoot->cubicasa_order_id) && empty($shoot->cubicasa_external_id)) {
                $this->cubicasaService->markSyncFailed(
                    $shoot,
                    CubiCasaService::SYNC_STATUS_NOT_LINKED,
                    'No CubiCasa order linked to this shoot.'
                );

                return response()->json([
                    'success' => false,
                    'mode' => 'not-linked',
                    'message' => 'No CubiCasa order linked to this shoot. Paste the CubiCasa Order ID in the Tour tab and try again.',
                    'sync' => $this->cubicasaService->syncStatePayload($shoot->fresh()),
                ], 409);
            }

            if ($this->cubicasaService->isSyncInProgress($shoot)) {
                return response()->json([
                    'success' => true,
                    'mode' => 'sync-in-progress',
                    'message' => 'CubiCasa sync is already in progress.',
                    'sync' => $this->cubicasaService->syncStatePayload($shoot),
                ], 202);
            }

            if ($request->boolean('async')) {
                $jobReference = (string) Str::uuid();
                $this->cubicasaService->markSyncQueued($shoot, $jobReference);
                SyncCubiCasaShootJob::dispatch($shoot->id, $jobReference);

                return response()->json([
                    'success' => true,
                    'mode' => 'queued',
                    'message' => 'CubiCasa sync queued.',
                    'sync' => $this->cubicasaService->syncStatePayload($shoot->fresh()),
                ], 202);
            }

            $parsed = $this->cubicasaService->syncShoot($shoot);
            if (!$parsed) {
                $reason = $this->cubicasaService->getLastFailureReason();
                $status = match ($reason) {
                    \App\Services\CubiCasaService::FAILURE_AUTH => 401,
                    \App\Services\CubiCasaService::FAILURE_NOT_FOUND => 404,
                    \App\Services\CubiCasaService::FAILURE_NOT_LINKED => 409,
                    default => 502,
                };
                $messages = [
                    \App\Services\CubiCasaService::FAILURE_AUTH => 'CubiCasa API key invalid or missing.',
                    \App\Services\CubiCasaService::FAILURE_NOT_FOUND => 'CubiCasa order not found.',
                    \App\Services\CubiCasaService::FAILURE_NOT_LINKED => 'No CubiCasa order linked to this shoot.',
                ];
                return response()->json([
                    'success' => false,
                    'mode' => $reason ?? 'error',
                    'message' => $messages[$reason] ?? 'Failed to fetch CubiCasa order.',
                    'sync' => $this->cubicasaService->syncStatePayload($shoot->fresh()),
                ], $status);
            }

            $floorplans = is_array($parsed['floorplans'] ?? null) ? $parsed['floorplans'] : [];
            $shouldIngest = !empty($floorplans) && $shoot->hasCubiCasaEligibleService();
            if ($shouldIngest) {
                IngestCubiCasaAssetsJob::dispatch($shoot->id, $floorplans);
            }

            $shoot->refresh();

            return response()->json([
                'success' => true,
                'data' => $parsed,
                'shoot' => [
                    'id' => $shoot->id,
                    'cubicasa_order_id' => $shoot->cubicasa_order_id,
                    'cubicasa_external_id' => $shoot->cubicasa_external_id,
                    'cubicasa_status' => $shoot->cubicasa_status,
                    'cubicasa_product_type' => $shoot->cubicasa_product_type,
                    'cubicasa_tour_url' => $shoot->cubicasa_tour_url,
                    'cubicasa_floorplans' => $shoot->cubicasa_floorplans,
                    'cubicasa_data' => $shoot->cubicasa_data,
                    'cubicasa_last_synced_at' => optional($shoot->cubicasa_last_synced_at)->toIso8601String(),
                    'cubicasa_sync_status' => $shoot->cubicasa_sync_status,
                    'cubicasa_sync_job_id' => $shoot->cubicasa_sync_job_id,
                    'cubicasa_sync_started_at' => optional($shoot->cubicasa_sync_started_at)->toIso8601String(),
                    'cubicasa_last_sync_error' => $shoot->cubicasa_last_sync_error,
                ],
                'sync' => $this->cubicasaService->syncStatePayload($shoot),
                'queued_assets' => $shouldIngest ? count($floorplans) : 0,
                'ingested' => $shouldIngest,
            ]);

        } catch (\Throwable $e) {
            Log::error('CubiCasa sync failed', [
                'shoot_id' => $shootId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync CubiCasa data',
                'sync' => isset($shoot) && $shoot instanceof Shoot
                    ? $this->cubicasaService->syncStatePayload($shoot->fresh())
                    : null,
            ], 500);
        }
    }

    /**
     * Manually create a CubiCasa order for a Shoot (Req 19.1).
     *
     * Delegates to {@see CubiCasaService::createOrder()} which:
     *  - syncs the existing order when the shoot is already linked, instead of
     *    creating a duplicate (AC 19.5);
     *  - sends `POST /orders` with a per-shoot Idempotency-Key (AC 19.6) so a
     *    retried/double-clicked request returns the same order;
     *  - links the order via `cubicasa_order_id` / `cubicasa_external_id` and
     *    updates `cubicasa_status` through `applyShootData()` (AC 19.2, 19.3);
     *  - writes an Audit_Log entry (via AuditLogService) for create or sync (AC 19.10).
     *
     * On failure the response code is derived from
     * {@see CubiCasaService::getLastFailureReason()} so the client can react
     * appropriately (auth → 401, not found → 404, other → 502).
     *
     * Note: `syncCubicasa()` continues to return 409 for unlinked shoots
     * (AC 19.4) — this endpoint is the only path that creates new orders.
     */
    public function createCubicasa(Request $request, $shootId)
    {
        try {
            $shoot = Shoot::findOrFail($shootId);

            $parsed = $this->cubicasaService->createOrder($shoot, $request->user());

            if (!$parsed) {
                $reason = $this->cubicasaService->getLastFailureReason();
                $status = match ($reason) {
                    \App\Services\CubiCasaService::FAILURE_AUTH => 401,
                    \App\Services\CubiCasaService::FAILURE_NOT_FOUND => 404,
                    default => 502,
                };
                $messages = [
                    \App\Services\CubiCasaService::FAILURE_AUTH => 'CubiCasa API key invalid or missing.',
                    \App\Services\CubiCasaService::FAILURE_NOT_FOUND => 'CubiCasa order not found.',
                ];

                return response()->json([
                    'success' => false,
                    'mode' => $reason ?? 'error',
                    'message' => $messages[$reason] ?? 'Failed to create CubiCasa order.',
                    'sync' => $this->cubicasaService->syncStatePayload($shoot->fresh()),
                ], $status);
            }

            // Mirror syncCubicasa(): when the order returned floorplans and the
            // shoot booked a CubiCasa-eligible service, queue asset ingestion.
            $floorplans = is_array($parsed['floorplans'] ?? null) ? $parsed['floorplans'] : [];
            $shouldIngest = !empty($floorplans) && $shoot->hasCubiCasaEligibleService();
            if ($shouldIngest) {
                IngestCubiCasaAssetsJob::dispatch($shoot->id, $floorplans);
            }

            $shoot->refresh();

            return response()->json([
                'success' => true,
                'data' => $parsed,
                'shoot' => [
                    'id' => $shoot->id,
                    'cubicasa_order_id' => $shoot->cubicasa_order_id,
                    'cubicasa_external_id' => $shoot->cubicasa_external_id,
                    'cubicasa_status' => $shoot->cubicasa_status,
                    'cubicasa_product_type' => $shoot->cubicasa_product_type,
                    'cubicasa_tour_url' => $shoot->cubicasa_tour_url,
                    'cubicasa_floorplans' => $shoot->cubicasa_floorplans,
                    'cubicasa_data' => $shoot->cubicasa_data,
                    'cubicasa_last_synced_at' => optional($shoot->cubicasa_last_synced_at)->toIso8601String(),
                    'cubicasa_idempotency_key' => $shoot->cubicasa_idempotency_key,
                    'cubicasa_sync_status' => $shoot->cubicasa_sync_status,
                    'cubicasa_sync_job_id' => $shoot->cubicasa_sync_job_id,
                    'cubicasa_sync_started_at' => optional($shoot->cubicasa_sync_started_at)->toIso8601String(),
                    'cubicasa_last_sync_error' => $shoot->cubicasa_last_sync_error,
                ],
                'sync' => $this->cubicasaService->syncStatePayload($shoot),
                'queued_assets' => $shouldIngest ? count($floorplans) : 0,
                'ingested' => $shouldIngest,
            ]);

        } catch (\Throwable $e) {
            Log::error('CubiCasa create failed', [
                'shoot_id' => $shootId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create CubiCasa order',
                'sync' => isset($shoot) && $shoot instanceof Shoot
                    ? $this->cubicasaService->syncStatePayload($shoot->fresh())
                    : null,
            ], 500);
        }
    }

    /**
     * Save admin-editable CubiCasa identifiers (order id + external id).
     * Used by the Tour tab "Save identifiers" button. Triggers a sync attempt.
     */
    public function saveCubicasaIdentifiers(Request $request, $shootId)
    {
        $validated = $request->validate([
            'cubicasa_order_id' => 'nullable|string|max:255',
            'cubicasa_external_id' => 'nullable|string|max:255',
        ]);

        try {
            $shoot = Shoot::findOrFail($shootId);
            if (array_key_exists('cubicasa_order_id', $validated)) {
                $shoot->cubicasa_order_id = $validated['cubicasa_order_id'] ?: null;
            }
            if (array_key_exists('cubicasa_external_id', $validated)) {
                $shoot->cubicasa_external_id = $validated['cubicasa_external_id'] ?: null;
            }
            $shoot->save();

            return response()->json([
                'success' => true,
                'shoot' => [
                    'id' => $shoot->id,
                    'cubicasa_order_id' => $shoot->cubicasa_order_id,
                    'cubicasa_external_id' => $shoot->cubicasa_external_id,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('CubiCasa saveCubicasaIdentifiers failed', [
                'shoot_id' => $shootId,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to save CubiCasa identifiers',
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
            'photos.*.sortOrder' => 'nullable|numeric',
            'iguide_tour_url' => 'nullable|string',
            'slideshow_url' => 'nullable|string',
            'matterport_url' => 'nullable|string',
            'cubicasa_url' => 'nullable|string',
            'additional_tour_urls' => 'nullable|array',
            'additional_tour_urls.*' => 'nullable|string',
            'documents' => 'nullable|array',
            'documents.*.id' => 'nullable|integer|exists:shoot_files,id',
            'documents.*.url' => 'nullable|string',
            'documents.*.filename' => 'nullable|string',
            'documents.*.visibility' => 'nullable|string',
            'documents.*.description' => 'nullable|string',
            'documents.*.type' => 'nullable|string',
        ]);

        try {
            $shoot = Shoot::with('files')->findOrFail($shootId);

            $resolvedPhotos = $this->resolveBrightMlsPhotos($shoot, $request->photos ?? []);
            $resolvedDocuments = $this->resolveBrightMlsDocuments($shoot, $request->documents ?? []);
            $defaultOptions = $this->resolveBrightMlsPublishDefaults($shoot);
            $requestAdditionalTourUrls = collect($request->input('additional_tour_urls', []))
                ->filter(fn ($url, $label) => is_string($label) && is_string($url) && filter_var($url, FILTER_VALIDATE_URL))
                ->all();

            // Build manifest data
            $options = [
                'photos' => $resolvedPhotos,
                'iguide_tour_url' => $request->filled('iguide_tour_url')
                    ? $request->input('iguide_tour_url')
                    : ($defaultOptions['iguide_tour_url'] ?? $shoot->iguide_tour_url),
                'slideshow_url' => $request->filled('slideshow_url')
                    ? $request->input('slideshow_url')
                    : ($defaultOptions['slideshow_url'] ?? null),
                'matterport_url' => $request->filled('matterport_url')
                    ? $request->input('matterport_url')
                    : ($defaultOptions['matterport_url'] ?? null),
                'cubicasa_url' => $request->filled('cubicasa_url')
                    ? $request->input('cubicasa_url')
                    : ($defaultOptions['cubicasa_url'] ?? null),
                'additional_tour_urls' => array_replace($defaultOptions['additional_tour_urls'] ?? [], $requestAdditionalTourUrls),
                'documents' => !empty($resolvedDocuments)
                    ? $resolvedDocuments
                    : ($defaultOptions['documents'] ?? []),
            ];

            $manifestData = $this->brightMlsService->buildManifestFromShoot($shoot->toArray(), $options);
            $result = $this->brightMlsService->publishManifest($manifestData);

            // Update shoot with publish status
            $this->brightMlsService->applyPublishResultToShoot($shoot, $result);

            if (($result['success'] ?? false) === true) {
                try {
                    $this->activityLogger->log(
                        $shoot,
                        'bright_mls_synced',
                        [
                            'manifest_id' => $result['manifest_id'] ?? null,
                            'mls_id' => $result['mls_id'] ?? $shoot->mls_id,
                            'status' => $result['status'] ?? null,
                            'mode' => $result['mode'] ?? null,
                            'environment' => $result['environment'] ?? null,
                            'auto_publish' => false,
                        ],
                        $request->user()
                    );
                } catch (\Exception $activityException) {
                    Log::warning('Failed to log Bright MLS publish activity', [
                        'shoot_id' => $shoot->id,
                        'error' => $activityException->getMessage(),
                    ]);
                }
            }

            $message = $result['error'] ?? $result['message'] ?? ($result['success'] ? 'Published to Bright MLS' : 'Bright MLS publish failed');

            // Append detailed validation errors if present
            $validationErrors = $result['validation_errors'] ?? [];
            if (!empty($validationErrors)) {
                $message .= ' — Details: ' . implode('; ', $validationErrors);
            }

            return response()->json([
                'success' => $result['success'],
                'status' => $result['status'],
                'message' => $message,
                'validation_errors' => $validationErrors,
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
            $user = $request->user();

            $query = Shoot::with(['client', 'photographer'])
                ->where(function ($q) {
                    $q->whereNotNull('mls_id')
                      ->where('mls_id', '!=', '')
                      ->orWhereNotNull('bright_mls_publish_status')
                      ->orWhereNotNull('bright_mls_manifest_id');
                })
                ->orderByRaw('bright_mls_last_published_at IS NULL, bright_mls_last_published_at DESC');

            // Clients can only see their own shoots
            if ($user && strtolower($user->role) === 'client') {
                $query->where('client_id', $user->id);
            }

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('bright_mls_publish_status', $request->status);
            }

            $shoots = $query->get()->map(function ($shoot) {
                $parsedResponse = null;
                if ($shoot->bright_mls_response) {
                    $parsedResponse = is_string($shoot->bright_mls_response)
                        ? json_decode($shoot->bright_mls_response, true)
                        : $shoot->bright_mls_response;
                }

                if (!is_array($parsedResponse)) {
                    $parsedResponse = [];
                }

                $integrationFlags = is_array($shoot->integration_flags) ? $shoot->integration_flags : [];

                return [
                    'id' => $shoot->id,
                    'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state}",
                    'mls_id' => $shoot->mls_id,
                    'client' => $shoot->client ? $shoot->client->name : 'Unknown',
                    'photographer' => $shoot->photographer ? $shoot->photographer->name : 'Unassigned',
                    'status' => $shoot->bright_mls_publish_status,
                    'last_published' => $shoot->bright_mls_last_published_at,
                    'manifest_id' => $shoot->bright_mls_manifest_id,
                    'mode' => $parsedResponse['mode'] ?? ($integrationFlags['bright_mls_mode'] ?? $this->brightMlsService->getMode()),
                    'environment' => $parsedResponse['environment'] ?? ($integrationFlags['bright_mls_environment'] ?? $this->brightMlsService->getEnvironment()),
                    'redirect_url' => $shoot->bright_mls_manifest_id
                        ? $this->brightMlsService->getRedirectUrl($shoot->bright_mls_manifest_id)
                        : null,
                    'response' => $parsedResponse,
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
     * Get Bright MLS redirect URL for a manifest
     */
    public function getBrightMlsRedirectUrl($manifestId)
    {
        try {
            $redirectUrl = $this->brightMlsService->getRedirectUrl($manifestId);

            return response()->json([
                'success' => true,
                'redirect_url' => $redirectUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate redirect URL',
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
            ->values()
            ->map(fn ($photo, $index) => ['photo' => $photo, 'index' => $index])
            ->sortBy(function ($entry) {
                $sortOrder = $entry['photo']['sortOrder'] ?? $entry['photo']['sort_order'] ?? null;

                return is_numeric($sortOrder) ? (float) $sortOrder : $entry['index'];
            })
            ->values()
            ->map(function ($entry) use ($shoot) {
                $photo = $entry['photo'];
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

    private function resolveBrightMlsPublishDefaults(Shoot $shoot): array
    {
        $documentOptions = [];
        if ($shoot->iguide_floorplans && is_array($shoot->iguide_floorplans)) {
            foreach ($shoot->iguide_floorplans as $floorplan) {
                $floorplanUrl = is_array($floorplan) ? ($floorplan['url'] ?? null) : $floorplan;
                if ($floorplanUrl) {
                    $documentOptions[] = [
                        'url' => $floorplanUrl,
                        'filename' => is_array($floorplan) ? ($floorplan['filename'] ?? 'floorplan.pdf') : 'floorplan.pdf',
                        'type' => 'floor_plan',
                        'visibility' => 'private',
                        'description' => 'Floor plan',
                    ];
                }
            }
        }

        $tourOptions = $this->extractBrightMlsTourOptions($shoot);

        return [
            'iguide_tour_url' => $tourOptions['iguide_tour_url'],
            'slideshow_url' => $tourOptions['slideshow_url'],
            'matterport_url' => $tourOptions['matterport_url'],
            'cubicasa_url' => $tourOptions['cubicasa_url'],
            'additional_tour_urls' => $tourOptions['additional_tour_urls'],
            'documents' => $documentOptions,
        ];
    }

    private function extractBrightMlsTourOptions(Shoot $shoot): array
    {
        $tourLinks = $shoot->tour_links ?? [];
        if (is_string($tourLinks)) {
            $tourLinks = json_decode($tourLinks, true) ?? [];
        }

        if (!is_array($tourLinks)) {
            $tourLinks = [];
        }

        $handledKeys = [
            // Dedicated payload slots
            'iguide_mls', 'iguide_branded', 'iguide', 'iGuide',
            'cubicasa', 'cubicasa_url',
            'matterport_mls', 'matterport_branded', 'matterport',
            'slideshow', 'slideshow_url', 'neo_tour', 'neotour',
            // Broadcast keys emitted explicitly below
            'branded', 'mls', 'generic_mls', 'genericMls', 'zillow_3d',
            'video_branded', 'video_mls', 'video_generic',
            // Intentionally excluded from Bright MLS sync
            'video_link', 'embeds', 'tour_style', 'featured_embed_id', 'featured_embed',
            'realtor_client', 'realtor_client_id', 'realtorClient', 'realtorClientId',
        ];

        $iguideTourUrl = $this->firstBrightMlsUrl(
            $tourLinks['iguide_mls'] ?? null,
            $shoot->iguide_tour_url,
            $tourLinks['iguide_branded'] ?? null,
            $tourLinks['iguide'] ?? null,
            $tourLinks['iGuide'] ?? null,
        );
        $slideshowUrl = $this->firstBrightMlsUrl(
            $tourLinks['mls'] ?? null,
            $tourLinks['generic_mls'] ?? null,
            $tourLinks['genericMls'] ?? null,
            $tourLinks['slideshow'] ?? null,
            $tourLinks['slideshow_url'] ?? null,
            $tourLinks['neo_tour'] ?? null,
            $tourLinks['neotour'] ?? null,
        );
        $matterportUrl = $this->firstBrightMlsUrl(
            $tourLinks['matterport_branded'] ?? null,
            $tourLinks['matterport'] ?? null,
            $tourLinks['matterport_mls'] ?? null,
        );
        $cubicasaUrl = $this->firstBrightMlsUrl(
            $tourLinks['cubicasa_url'] ?? null,
            $tourLinks['cubicasa'] ?? null,
        );

        return [
            'iguide_tour_url' => $iguideTourUrl,
            'slideshow_url' => $slideshowUrl,
            'matterport_url' => $matterportUrl,
            'cubicasa_url' => $cubicasaUrl,
            'additional_tour_urls' => $this->extractAdditionalBrightMlsTourUrls(
                $tourLinks,
                $handledKeys,
                [$iguideTourUrl, $slideshowUrl, $matterportUrl, $cubicasaUrl],
            ),
        ];
    }

    private function firstBrightMlsUrl(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $candidate = trim($value);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Tour link keys explicitly forwarded to Bright MLS with friendly labels.
     * `video_link` (in-page Video Embed) and the `embeds` iframe array are
     * intentionally skipped.
     */
    private const BRIGHT_MLS_BROADCAST_TOUR_KEYS = [
        'branded' => 'Branded Tour',
        'mls' => 'MLS Tour',
        'generic_mls' => 'MLS Tour',
        'genericMls' => 'MLS Tour',
        'zillow_3d' => 'Zillow 3D Home Tour',
        'matterport_branded' => 'Matterport 3D Tour (Branded)',
        'matterport_mls' => 'Matterport 3D Tour (MLS)',
        'iguide_branded' => 'iGUIDE 3D Tour (Branded)',
        'iguide_mls' => 'iGUIDE 3D Tour (MLS)',
        'video_branded' => 'Branded Video',
        'video_mls' => 'MLS Video',
        'video_generic' => 'Property Video',
    ];

    private function extractAdditionalBrightMlsTourUrls(array $tourLinks, array $handledKeys, array $dedupeUrls = []): array
    {
        $additionalTourUrls = [];
        $seenUrls = array_flip(array_filter($dedupeUrls));

        foreach (self::BRIGHT_MLS_BROADCAST_TOUR_KEYS as $key => $label) {
            $candidate = $this->firstBrightMlsUrl($tourLinks[$key] ?? null);
            if (!$candidate || isset($seenUrls[$candidate]) || isset($additionalTourUrls[$label])) {
                continue;
            }

            $additionalTourUrls[$label] = $candidate;
            $seenUrls[$candidate] = true;
        }

        foreach ($tourLinks as $key => $value) {
            if ($key === 'embeds') {
                // Skip the iframe embed array entirely per product requirements.
                continue;
            }

            if (in_array($key, $handledKeys, true) || !is_string($value)) {
                continue;
            }

            $candidate = trim($value);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_URL) || isset($seenUrls[$candidate])) {
                continue;
            }

            $label = ucwords(str_replace(['_', '-'], ' ', (string) $key));
            if (!isset($additionalTourUrls[$label])) {
                $additionalTourUrls[$label] = $candidate;
                $seenUrls[$candidate] = true;
            }
        }

        return $additionalTourUrls;
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
                    'type' => $doc['type'] ?? null,
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

        // 1. If candidate is already a full HTTP URL, use it directly
        if ($candidateUrl && Str::startsWith($candidateUrl, ['http://', 'https://'])) {
            return $candidateUrl;
        }

        // 2. If we have a ShootFile record, try its fields in priority order
        if ($file) {
            // Try fields that may already be full HTTP URLs
            foreach (['url', 'web_path', 'storage_path', 'path'] as $field) {
                $value = $file->{$field} ?? null;
                if ($value && Str::startsWith($value, ['http://', 'https://'])) {
                    return $value;
                }
            }

            // When reads are flipped to R2, delivered media is publicly fetchable
            // via the CDN custom domain — prefer that for MLS server-side fetches.
            $media = app(\App\Services\Media\MediaStorage::class);
            if ($media->readFromR2Enabled() || $media->r2Only()) {
                foreach (['web_path', 'storage_path', 'path'] as $field) {
                    $key = $media->normalizeKey($file->{$field} ?? null);
                    if ($key && $media->existsOnR2($key)) {
                        return $media->publicUrl($key);
                    }
                }
            }

            // When Dropbox is enabled, files live in Dropbox — not on local disk.
            // Prefer Dropbox temporary links over converting relative paths to
            // storage URLs that would 404 because the file isn't physically there.
            if ($file->dropbox_path && $this->dropboxService->isEnabled()) {
                $dropboxUrl = $this->dropboxService->getTemporaryLink($file->dropbox_path);
                if ($dropboxUrl) {
                    return $dropboxUrl;
                }
            }

            // Fallback: convert storage-relative paths to full URLs (works when
            // files are stored locally and the storage symlink is in place)
            foreach (['web_path', 'storage_path', 'path'] as $field) {
                $value = $file->{$field} ?? null;
                if ($value && !Str::startsWith($value, ['http://', 'https://'])) {
                    return $this->storagePathToUrl($value);
                }
            }
        }

        // 3. If candidate is a relative storage path, convert to full URL
        if ($candidateUrl) {
            return $this->storagePathToUrl($candidateUrl);
        }

        return null;
    }

    private function storagePathToUrl(string $path): string
    {
        $path = ltrim($path, '/');
        // URL-encode each path segment individually to handle spaces/special chars
        $segments = explode('/', $path);
        $encoded = implode('/', array_map('rawurlencode', $segments));
        return url('storage/' . $encoded);
    }

    /**
     * Append a high-entropy return_token query param to the MMM callback URL.
     */
    private function appendMmmReturnToken(?string $url, string $returnToken): string
    {
        $base = trim((string) $url);
        if ($base === '') {
            $base = (string) config('services.mmm.url_return', '');
        }

        if ($base === '') {
            return 'return_token=' . urlencode($returnToken);
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'return_token=' . urlencode($returnToken);
    }

}


