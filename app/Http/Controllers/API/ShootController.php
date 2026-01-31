<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootMediaAlbum;
use App\Models\ShootNote;
use App\Models\User;
use App\Models\Service;
use App\Models\Payment;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\DropboxWorkflowService;
use App\Services\MailService;
use App\Services\ShootWorkflowService;
use App\Services\ShootActivityLogger;
use App\Services\ShootTaxService;
use App\Services\PhotographerAvailabilityService;
use App\Services\Messaging\AutomationService;
use App\Http\Requests\StoreShootRequest;
use App\Http\Requests\UpdateShootStatusRequest;
use App\Http\Resources\ShootResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShootController extends Controller
{
    protected $dropboxService;
    protected $mailService;
    protected $workflowService;
    protected $activityLogger;
    protected $taxService;
    protected $availabilityService;
    protected $invoiceService;
    protected $automationService;

    protected const TAB_STATUS_MAP = [
        'scheduled' => [
            Shoot::STATUS_SCHEDULED,
            Shoot::STATUS_REQUESTED, // Client-initiated requests pending approval
        ],
        'completed' => [
            Shoot::STATUS_UPLOADED,
            Shoot::STATUS_EDITING,
        ],
        'delivered' => [
            Shoot::STATUS_DELIVERED,
        ],
        'hold' => [
            Shoot::STATUS_ON_HOLD,
            Shoot::STATUS_CANCELLED,
        ],
    ];

    protected const HISTORY_ALLOWED_ROLES = [
        'admin',
        'superadmin',
        'finance',
        'accounting',
        'editor',
        'client',
        'salesRep',
    ];

    public function __construct(
        DropboxWorkflowService $dropboxService,
        MailService $mailService,
        ShootWorkflowService $workflowService,
        ShootActivityLogger $activityLogger,
        ShootTaxService $taxService,
        PhotographerAvailabilityService $availabilityService,
        InvoiceService $invoiceService,
        AutomationService $automationService
    ) {
        $this->dropboxService = $dropboxService;
        $this->mailService = $mailService;
        $this->workflowService = $workflowService;
        $this->activityLogger = $activityLogger;
        $this->taxService = $taxService;
        $this->availabilityService = $availabilityService;
        $this->invoiceService = $invoiceService;
        $this->automationService = $automationService;
    }

    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $tab = strtolower($request->query('tab', 'scheduled'));
            if (!$request->has('tab') && $request->query('private_listing') !== null) {
                $tab = 'delivered';
            }
            
            // Build cache key from request parameters - include ALL query params that affect results
            $page = (int) $request->query('page', 1);
            $perPage = min(50, max(9, (int) $request->query('per_page', 25)));
            $userId = $user ? $user->id : 'guest';
            $userRole = $user ? $user->role : 'guest';
            
            // Include impersonation context in cache key to prevent data leakage
            $isImpersonating = $request->attributes->get('is_impersonating', false);
            $impersonationSuffix = $isImpersonating ? '_imp' : '';
            
            $cacheKey = 'shoots_index_' . $userId . '_' . $userRole . $impersonationSuffix . '_' . $tab . '_' . $page . '_' . $perPage;
            
            // Include ALL filter parameters in cache key (must match applyOperationalFilters)
            $filterParams = $request->only([
                'client_id', 'photographer_id', 'services', 'search', 'address',
                'date_range', 'scheduled_start', 'scheduled_end',
                'completed_start', 'completed_end', 'custom_start', 'custom_end',
                'date_from', 'date_to', 'private_listing'
            ]);
            // Remove empty values and sort for consistent cache keys
            $filterParams = array_filter($filterParams, function($value) {
                return $value !== null && $value !== '';
            });
            ksort($filterParams);
            if (!empty($filterParams)) {
                $cacheKey .= '_' . md5(json_encode($filterParams));
            }

            $skipCache = filter_var($request->query('no_cache', false), FILTER_VALIDATE_BOOLEAN);
            // Cache for 30 seconds
            if (!$skipCache) {
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return response()->json($cached);
                }
            }

            // Optimize eager loading - only load necessary file columns to reduce memory usage
            // For dashboard/list views, we don't need all file details
            $needsFiles = $request->query('include_files', 'false') === 'true';
            $eagerLoads = [
                'client:id,name,email,company_name,phonenumber',
                'photographer:id,name,avatar',
                'editor:id,name,avatar',
                'service:id,name',
                'services:id,name',
            ];
            
            // Only load files if explicitly requested (reduces query time significantly)
            if ($needsFiles) {
                $eagerLoads['files'] = function ($query) {
                    $query->select('id', 'shoot_id', 'workflow_stage', 'is_favorite', 'is_cover', 'flag_reason', 'url', 'path', 'dropbox_path', 'thumbnail_path', 'web_path', 'placeholder_path', 'watermarked_storage_path', 'watermarked_thumbnail_path', 'watermarked_web_path', 'watermarked_placeholder_path');
                };
            }
            
            // Only load payments if needed
            $needsPayments = $request->query('include_payments', 'false') === 'true';
            if ($needsPayments) {
                $eagerLoads['payments'] = 'id,shoot_id,amount,paid_at,status';
            }
            
            $query = Shoot::with($eagerLoads);

            // Filter based on user role
            if ($user && $user->role === 'photographer') {
                $query->where('photographer_id', $user->id);
            } elseif ($user && $user->role === 'client') {
                $query->where('client_id', $user->id);
            } elseif ($user && $user->role === 'editor') {
                // Editors should only see shoots assigned to them or where they have activity
                $query->where(function (Builder $scope) use ($user) {
                    $scope->where('editor_id', $user->id)
                        ->orWhereHas('activityLogs', function (Builder $logQuery) use ($user) {
                            $logQuery->where('user_id', $user->id);
                        });
                });
                // Default to 'completed' tab for editors to see editing/review shoots
                if (!$request->has('tab')) {
                    $tab = 'completed';
                }
            }

            $this->applyTabScope($query, $tab);
            $this->applyOperationalFilters($query, $request, $tab);

            // Add safety limit to prevent memory exhaustion (can be overridden with limit parameter)
            $maxLimit = 1000; // Maximum shoots to load at once
            $requestLimit = (int) $request->query('limit', $maxLimit);
            if ($requestLimit > 0 && $requestLimit <= $maxLimit) {
                $query->limit($requestLimit);
            } else {
                $query->limit($maxLimit);
            }

            foreach ($this->determineTabOrdering($tab) as $ordering) {
                if (isset($ordering['raw'])) {
                    $query->orderByRaw($ordering['raw']);
                    continue;
                }

                $query->orderBy($ordering['column'], $ordering['direction'] ?? 'asc');
            }

            // Use simple pagination instead of chunk to avoid memory issues
            $page = (int) $request->query('page', 1);
            $perPage = min(50, max(9, (int) $request->query('per_page', 25)));
            
            $shoots = $query->paginate($perPage, ['*'], 'page', $page);
            
            // Transform the items without loading all into memory
            // Check if current user is a client (watermarks only apply to clients)
            $currentUser = $request->user();
            $isClientUser = $currentUser && $currentUser->role === 'client';
            
            $transformedShoots = $shoots->getCollection()->map(function ($shoot) use ($isClientUser) {
                $transformedShoot = $this->transformShoot($shoot);
                // Convert to array and ensure services_list is included
                $shootArray = $transformedShoot->toArray();
                // Get services_list from the model attribute (set in transformShoot)
                $servicesArray = $transformedShoot->getAttribute('services_list') ?? 
                                $transformedShoot->services->pluck('name')->filter()->values()->all();
                $shootArray['services_list'] = $servicesArray;
                // Also ensure services relationship is properly formatted
                if (isset($shootArray['services']) && is_array($shootArray['services'])) {
                    // Services already in array format, keep it
                } else {
                    // Convert services relationship to array of names
                    $shootArray['services'] = $servicesArray;
                }
                // Ensure created_by_name is included
                $createdByName = $transformedShoot->getAttribute('created_by_name');
                if ($createdByName) {
                    $shootArray['created_by'] = $createdByName;
                    $shootArray['createdBy'] = $createdByName;
                }
                // Include cancellation request fields
                $shootArray['cancellation_requested_at'] = $transformedShoot->cancellation_requested_at?->toIso8601String();
                $shootArray['cancellationRequestedAt'] = $transformedShoot->cancellation_requested_at?->toIso8601String();
                $shootArray['cancellation_reason'] = $transformedShoot->cancellation_reason;
                $shootArray['cancellationReason'] = $transformedShoot->cancellation_reason;
                
                // Transform files to include proper URLs
                // Check if shoot needs watermarked images (client user viewing unpaid shoot without bypass)
                $needsWatermark = $isClientUser && 
                                  !($shootArray['bypass_paywall'] ?? false) && 
                                  !in_array($shootArray['payment_status'] ?? '', ['paid', 'full']);
                
                Log::debug('Shoots list watermark check', [
                    'shoot_id' => $shootArray['id'] ?? 'unknown',
                    'is_client_user' => $isClientUser,
                    'needs_watermark' => $needsWatermark,
                    'payment_status' => $shootArray['payment_status'] ?? 'null',
                    'bypass_paywall' => $shootArray['bypass_paywall'] ?? 'null',
                    'files_count' => isset($shootArray['files']) ? count($shootArray['files']) : 0,
                ]);
                
                if (isset($shootArray['files']) && is_array($shootArray['files'])) {
                    $shootArray['files'] = collect($shootArray['files'])->map(function ($file) use ($needsWatermark) {
                        // Convert storage paths to URLs
                        $resolveUrl = function ($path) {
                            if (!$path) return null;
                            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                                return $path;
                            }
                            // Storage path - convert to URL
                            if (str_starts_with($path, 'shoots/') || str_starts_with($path, '/shoots/')) {
                                return url('storage/' . ltrim($path, '/'));
                            }
                            return url('storage/' . $path);
                        };
                        
                        // Use watermarked paths for unpaid shoots if available
                        if ($needsWatermark) {
                            $thumbUrl = $resolveUrl($file['watermarked_thumbnail_path'] ?? $file['thumbnail_path'] ?? null);
                            $webUrl = $resolveUrl($file['watermarked_web_path'] ?? $file['web_path'] ?? null);
                            $placeholderUrl = $resolveUrl($file['watermarked_placeholder_path'] ?? $file['placeholder_path'] ?? null);
                            
                            // Set both naming conventions for frontend compatibility
                            $file['thumbnail_url'] = $thumbUrl;
                            $file['thumb_url'] = $thumbUrl;
                            $file['thumb'] = $thumbUrl;
                            $file['web_url'] = $webUrl;
                            $file['medium_url'] = $webUrl;
                            $file['medium'] = $webUrl;
                            $file['large_url'] = $webUrl;
                            $file['large'] = $webUrl;
                            $file['placeholder_url'] = $placeholderUrl;
                            // For watermarked shoots, set original to watermarked version too
                            $file['original_url'] = $webUrl;
                            $file['original'] = $webUrl;
                            $file['url'] = $webUrl;
                            // Hide non-watermarked paths
                            $file['thumbnail_path'] = null;
                            $file['web_path'] = null;
                            $file['placeholder_path'] = null;
                            $file['path'] = null;
                        } else {
                            $thumbUrl = $resolveUrl($file['thumbnail_path'] ?? null);
                            $webUrl = $resolveUrl($file['web_path'] ?? null);
                            $placeholderUrl = $resolveUrl($file['placeholder_path'] ?? null);
                            
                            $file['thumbnail_url'] = $thumbUrl;
                            $file['thumb_url'] = $thumbUrl;
                            $file['thumb'] = $thumbUrl;
                            $file['web_url'] = $webUrl;
                            $file['medium_url'] = $webUrl;
                            $file['medium'] = $webUrl;
                            $file['large_url'] = $webUrl;
                            $file['large'] = $webUrl;
                            $file['placeholder_url'] = $placeholderUrl;
                        }
                        
                        return $file;
                    })->toArray();
                }
                
                return $shootArray;
            });
            
            $shoots->setCollection($transformedShoots);
            
            // Build filter metadata from current page only (cached separately later)
            $filterMeta = $this->buildOperationalFilterMeta($shoots->getCollection());

            $response = [
                'data' => $shoots->items(),
                'meta' => [
                    'tab' => $tab,
                    'count' => $shoots->total(),
                    'current_page' => $shoots->currentPage(),
                    'per_page' => $shoots->perPage(),
                    'last_page' => $shoots->lastPage(),
                    'filters' => $filterMeta,
                ],
            ];
            
            // Cache the response for 30 seconds
            if (!$skipCache) {
                Cache::put($cacheKey, $response, now()->addSeconds(30));
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Shoot index error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'request_params' => $request->all(),
            ]);

            return response()->json([
                'message' => 'Failed to load shoots',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred while loading shoots',
                'data' => [],
                'meta' => [
                    'tab' => $request->query('tab', 'scheduled'),
                    'count' => 0,
                    'filters' => [
                        'clients' => [],
                        'photographers' => [],
                        'services' => [],
                    ],
                ],
            ], 500);
        }
    }

    /**
     * Assign an editor to a shoot (auto-assign if not provided)
     * POST /api/shoots/{shoot}/assign-editor
     */
    public function assignEditor(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'editor_id' => 'nullable|exists:users,id',
        ]);

        $editorId = $validated['editor_id'] ?? null;
        $selectedEditor = null;

        if ($editorId) {
            $selectedEditor = User::find($editorId);
            if (!$selectedEditor || $selectedEditor->role !== 'editor') {
                return response()->json(['message' => 'Selected user is not an editor'], 422);
            }
        } else {
            $editors = User::where('role', 'editor')->get(['id', 'name']);
            if ($editors->isEmpty()) {
                return response()->json(['message' => 'No editors available'], 422);
            }

            $editorIds = $editors->pluck('id');
            $loadMap = Shoot::whereIn('editor_id', $editorIds)
                ->whereIn('workflow_status', [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING])
                ->select('editor_id', DB::raw('count(*) as total'))
                ->groupBy('editor_id')
                ->get()
                ->pluck('total', 'editor_id');

            $selectedEditor = $editors->sortBy(fn($editor) => $loadMap[$editor->id] ?? 0)->first();
            $editorId = $selectedEditor->id;
        }

        $shoot->editor_id = $editorId;
        $shoot->save();

        if ($this->activityLogger) {
            $this->activityLogger->log(
                $shoot,
                'editor_assigned',
                [
                    'editor_id' => $editorId,
                    'editor_name' => $selectedEditor?->name,
                ],
                $user
            );
        }

        return response()->json([
            'message' => 'Editor assigned successfully',
            'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services', 'editor'])),
        ]);
    }

    protected function applyTabScope(Builder $query, string $tab): void
    {
        $tabKey = array_key_exists($tab, self::TAB_STATUS_MAP) ? $tab : 'scheduled';

        $statuses = self::TAB_STATUS_MAP[$tabKey] ?? self::TAB_STATUS_MAP['scheduled'];

        if ($tabKey === 'delivered') {
            $workflowStatuses = array_unique(array_merge($statuses, [
                'ready_for_client',
                'admin_verified',
                'ready',
                'workflow_completed',
                'client_delivered',
            ]));

            $query->where(function (Builder $scope) use ($statuses, $workflowStatuses) {
                $scope->whereIn('status', $statuses)
                    ->orWhereIn('workflow_status', $workflowStatuses);
            });
            return;
        }

        if ($tabKey === 'completed') {
            $workflowStatuses = array_unique(array_merge($statuses, [
                'completed',
                'editing_complete',
                'editing_uploaded',
                'editing_issue',
                'pending_review',
                'ready_for_review',
                'qc',
                'review',
                'in_progress',
                'raw_issue',
                'raw_uploaded',
                'photos_uploaded',
            ]));

            $query->where(function (Builder $scope) use ($statuses, $workflowStatuses) {
                $scope->whereIn('status', $statuses)
                    ->orWhereIn('workflow_status', $workflowStatuses);
            });
            return;
        }

        // Filter by unified status column only (migration normalized all legacy values)
        $query->whereIn('status', $statuses);
    }

    protected function applyOperationalFilters(Builder $query, Request $request, string $tab): void
    {
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $this->applySearchFilter($query, $search);
        }

        $clientIds = $this->normalizeArrayQuery($request, 'client_id');
        if (!empty($clientIds)) {
            $query->whereIn('client_id', $clientIds);
        }

        $photographerIds = $this->normalizeArrayQuery($request, 'photographer_id');
        if (!empty($photographerIds)) {
            $query->whereIn('photographer_id', $photographerIds);
        }

        $address = trim((string) $request->query('address', ''));
        if ($address !== '') {
            $query->where(function (Builder $scope) use ($address) {
                $scope->where('address', 'like', "%{$address}%")
                    ->orWhere('city', 'like', "%{$address}%")
                    ->orWhere('state', 'like', "%{$address}%")
                    ->orWhere('zip', 'like', "%{$address}%");
            });
        }

        $services = $this->normalizeArrayQuery($request, 'services');
        if (!empty($services)) {
            $query->whereHas('services', function (Builder $serviceQuery) use ($services) {
                $serviceQuery->whereIn('services.id', $services)
                    ->orWhereIn(DB::raw('LOWER(services.name)'), array_map(function ($service) {
                        return strtolower((string) $service);
                    }, $services));
            });
        }

        $bracket = $request->query('bracket');
        if ($bracket === 'none') {
            $query->whereNull('bracket_mode');
        } elseif (in_array($bracket, ['3', '5'], true)) {
            $query->where('bracket_mode', (int) $bracket);
        }

        $missing = $request->query('missing');
        if ($missing === 'raw') {
            $query->where('missing_raw', true);
        } elseif ($missing === 'final') {
            $query->where('missing_final', true);
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        if ($dateFrom || $dateTo) {
            $column = $tab === 'delivered' ? 'admin_verified_at' : 'scheduled_date';
            $this->applyDateRangeFilter($query, $column, $dateFrom, $dateTo);
        }

        // Filter by private listing flag
        $privateListing = $request->query('private_listing');
        if ($privateListing !== null) {
            $query->where('is_private_listing', filter_var($privateListing, FILTER_VALIDATE_BOOLEAN));
        }
    }

    protected function determineTabOrdering(string $tab): array
    {
        switch ($tab) {
            case 'delivered':
                return [
                    ['raw' => 'COALESCE(admin_verified_at, editing_completed_at, scheduled_date) DESC'],
                ];
            case 'completed':
                return [
                    ['column' => 'created_at', 'direction' => 'desc'],
                ];
            case 'hold':
                return [
                    ['column' => 'created_at', 'direction' => 'desc'],
                ];
            case 'scheduled':
                // Sort by most recent booking first (created_at DESC)
                return [
                    ['column' => 'created_at', 'direction' => 'desc'],
                ];
            default:
                // Default to most recent booking first
                return [
                    ['column' => 'created_at', 'direction' => 'desc'],
                ];
        }
    }

    protected function buildOperationalFilterMeta(Collection $shoots): array
    {
        // Use cache to avoid rebuilding filter metadata on every request
        $cacheKey = 'shoots_filter_meta_' . auth()->id() . '_' . request()->query('tab', 'scheduled');
        
        return Cache::remember($cacheKey, now()->addHour(), function () {
            // Get all unique clients, photographers, and services for filters
            $clients = User::where('role', 'client')
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(function ($client) {
                    return [
                        'id' => $client->id,
                        'name' => $client->name ?? 'Unknown',
                    ];
                })
                ->values();

            $photographers = User::where('role', 'photographer')
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(function ($photographer) {
                    return [
                        'id' => $photographer->id,
                        'name' => $photographer->name ?? 'Unknown',
                    ];
                })
                ->values();

            $services = Service::select('id', 'name')
                ->orderBy('name')
                ->get()
                ->pluck('name')
                ->filter()
                ->values();

            return [
                'clients' => $clients,
                'photographers' => $photographers,
                'services' => $services,
            ];
        });
    }

    protected function applySearchFilter(Builder $query, string $term): void
    {
        $query->where(function (Builder $scope) use ($term) {
            $scope->where('address', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%")
                ->orWhere('state', 'like', "%{$term}%")
                ->orWhere('zip', 'like', "%{$term}%")
                ->orWhereHas('client', function (Builder $clientQuery) use ($term) {
                    $clientQuery->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phonenumber', 'like', "%{$term}%")
                        ->orWhere('company_name', 'like', "%{$term}%");
                })
                ->orWhereHas('photographer', function (Builder $photographerQuery) use ($term) {
                    $photographerQuery->where('name', 'like', "%{$term}%");
                });
        });
    }

    protected function normalizeArrayQuery(Request $request, string $key): array
    {
        $value = $request->query($key);

        if (is_null($value)) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, static fn($entry) => $entry !== '' && $entry !== null));
        }

        $parts = array_map('trim', explode(',', (string) $value));
        return array_values(array_filter($parts, static fn($entry) => $entry !== ''));
    }

    protected function applyDateRangeFilter(Builder $query, string $column, ?string $start, ?string $end): void
    {
        if ($start) {
            try {
                $query->whereDate($column, '>=', Carbon::parse($start)->toDateString());
            } catch (\Throwable $e) {
                // Ignore invalid date input
            }
        }

        if ($end) {
            try {
                $query->whereDate($column, '<=', Carbon::parse($end)->toDateString());
            } catch (\Throwable $e) {
                // Ignore invalid date input
            }
        }
    }

    public function history(Request $request)
    {
        try {
            $user = auth()->user();
            $isImpersonating = $request->attributes->get('is_impersonating', false);
            
            Log::debug('History endpoint called', [
                'user_id' => $user?->id,
                'user_role' => $user?->role,
                'user_name' => $user?->name,
                'is_impersonating' => $isImpersonating,
                'impersonate_header' => $request->header('X-Impersonate-User-Id'),
            ]);
            
            if (!$this->userCanViewHistory($user)) {
                Log::warning('User cannot view history', ['user_id' => $user?->id, 'role' => $user?->role]);
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $groupBy = strtolower($request->query('group_by', 'shoot'));
            $perPage = (int) min(200, max(9, (int) $request->query('per_page', 25)));

            $query = Shoot::with(['client', 'photographer', 'services', 'payments']);
            $this->applyHistoryFilters($query, $request);

            if ($groupBy === 'services') {
                $aggregateQuery = Shoot::query();
                $this->applyHistoryFilters($aggregateQuery, $request);
                $aggregates = $this->buildHistoryServiceAggregates($aggregateQuery);

                return response()->json([
                    'data' => $aggregates,
                    'meta' => [
                        'group_by' => 'services',
                        'filters' => [
                            'clients' => [],
                            'photographers' => [],
                            'services' => [],
                        ],
                    ],
                ]);
            }

            $paginator = $query
                ->orderByRaw('COALESCE(admin_verified_at, editing_completed_at, scheduled_date, created_at) DESC')
                ->paginate($perPage);

            $clientCounts = $this->loadClientShootCounts($paginator->getCollection());

            $collection = $paginator->getCollection()->map(function (Shoot $shoot) use ($clientCounts) {
                return $this->transformHistoryShoot($shoot, $clientCounts);
            });

            $paginator->setCollection($collection);

            return response()->json([
                'data' => $collection,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'group_by' => 'shoot',
                    'filters' => $this->buildHistoryFilterMetaFromRecords($collection),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Shoot history error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'request_params' => $request->all(),
            ]);

            return response()->json([
                'message' => 'Failed to load shoot history',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred while loading history',
            ], 500);
        }
    }

    public function exportHistory(Request $request): StreamedResponse
    {
        $user = auth()->user();
        if (!$this->userCanViewHistory($user)) {
            abort(403, 'Forbidden');
        }

        $query = Shoot::with(['client', 'photographer', 'services', 'payments']);
        $this->applyHistoryFilters($query, $request);

        $shoots = $query
            ->orderByRaw('COALESCE(admin_verified_at, editing_completed_at, scheduled_date, created_at) DESC')
            ->get();

        $clientCounts = $this->loadClientShootCounts($shoots);

        $rows = $shoots->map(function (Shoot $shoot) use ($clientCounts) {
            return $this->transformHistoryShoot($shoot, $clientCounts);
        });

        $filename = 'shoot-history-' . now()->format('Ymd-His') . '.csv';

        $includeClientDetails = strtolower($user->role ?? '') !== 'editor';

        return response()->streamDownload(function () use ($rows, $includeClientDetails) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $this->historyCsvHeaders($includeClientDetails));

            foreach ($rows as $row) {
                fputcsv($handle, $this->buildHistoryCsvRow($row, $includeClientDetails));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function userCanViewHistory(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return in_array($user->role, self::HISTORY_ALLOWED_ROLES, true);
    }

    protected function applyHistoryFilters(Builder $query, Request $request): void
    {
        $user = auth()->user();
        
        Log::debug('applyHistoryFilters', [
            'user_id' => $user?->id,
            'user_role' => $user?->role,
        ]);
        
        // Apply role-based filtering
        if ($user) {
            if ($user->role === 'client') {
                // Clients can only see their own shoots
                Log::debug('Filtering shoots for client', ['client_id' => $user->id]);
                $query->where('client_id', $user->id);
            } elseif ($user->role === 'salesRep') {
                // Sales reps can only see shoots for their clients
                $repId = $user->id;
                $query->where(function ($q) use ($repId) {
                    $q->where('rep_id', $repId)
                      ->orWhereHas('client', function ($clientQuery) use ($repId) {
                          $clientQuery->where(function ($cq) use ($repId) {
                              $cq->whereRaw("JSON_EXTRACT(metadata, '$.accountRepId') = ?", [$repId])
                                 ->orWhereRaw("JSON_EXTRACT(metadata, '$.account_rep_id') = ?", [$repId])
                                 ->orWhereRaw("JSON_EXTRACT(metadata, '$.repId') = ?", [$repId])
                                 ->orWhereRaw("JSON_EXTRACT(metadata, '$.rep_id') = ?", [$repId])
                                 ->orWhere('created_by_id', $repId);
                          });
                      });
                });
            } elseif ($user->role === 'editor') {
                // Editors can see shoots assigned to them or where they have activity
                $query->where(function ($q) use ($user) {
                    $q->where('editor_id', $user->id)
                        ->orWhereHas('activityLogs', function ($logQuery) use ($user) {
                            $logQuery->where('user_id', $user->id);
                        });
                });
            }
            // Admin, superadmin, finance, accounting can see all (no additional filter)
        }
        
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $this->applySearchFilter($query, $search);
        }

        $clientIds = array_merge(
            $this->normalizeArrayQuery($request, 'client_id'),
            $this->normalizeArrayQuery($request, 'client_ids')
        );
        if (!empty($clientIds)) {
            $query->whereIn('client_id', $clientIds);
        }

        $photographerIds = array_merge(
            $this->normalizeArrayQuery($request, 'photographer_id'),
            $this->normalizeArrayQuery($request, 'photographer_ids')
        );
        if (!empty($photographerIds)) {
            $query->whereIn('photographer_id', $photographerIds);
        }

        $services = $this->normalizeArrayQuery($request, 'services');
        if (!empty($services)) {
            $query->whereHas('services', function (Builder $serviceQuery) use ($services) {
                $serviceQuery->whereIn('services.id', $services)
                    ->orWhereIn(DB::raw('LOWER(services.name)'), array_map('strtolower', $services));
            });
        }

        $scheduledStart = $request->query('scheduled_start');
        $scheduledEnd = $request->query('scheduled_end');
        if ($scheduledStart || $scheduledEnd) {
            $this->applyDateRangeFilter($query, 'scheduled_date', $scheduledStart, $scheduledEnd);
        }

        $completedStart = $request->query('completed_start');
        $completedEnd = $request->query('completed_end');
        if ($completedStart || $completedEnd) {
            $this->applyDateRangeFilter($query, 'admin_verified_at', $completedStart, $completedEnd);
        }

        $dateRange = strtolower((string) $request->query('date_range', ''));
        // Skip date filtering for clients - they should see all their shoots
        if ($user && $user->role === 'client') {
            // Clients see all their shoots without date restriction
            return;
        }
        
        if ($dateRange === 'custom') {
            $this->applyDateRangeFilter(
                $query,
                'scheduled_date',
                $request->query('custom_start'),
                $request->query('custom_end')
            );
        } elseif ($dateRange !== '') {
            $this->applyHistoryDatePreset($query, $dateRange);
        }
    }

    protected function applyHistoryDatePreset(Builder $query, string $preset): void
    {
        $year = now()->year;
        switch ($preset) {
            case 'q1':
                $start = Carbon::create($year, 1, 1)->startOfDay();
                $end = Carbon::create($year, 3, 31)->endOfDay();
                break;
            case 'q2':
                $start = Carbon::create($year, 4, 1)->startOfDay();
                $end = Carbon::create($year, 6, 30)->endOfDay();
                break;
            case 'q3':
                $start = Carbon::create($year, 7, 1)->startOfDay();
                $end = Carbon::create($year, 9, 30)->endOfDay();
                break;
            case 'q4':
                $start = Carbon::create($year, 10, 1)->startOfDay();
                $end = Carbon::create($year, 12, 31)->endOfDay();
                break;
            case 'this_month':
                $start = now()->copy()->startOfMonth();
                $end = now()->copy()->endOfMonth();
                break;
            case 'this_quarter':
                $start = now()->copy()->firstOfQuarter();
                $end = now()->copy()->lastOfQuarter()->endOfDay();
                break;
            default:
                return;
        }

        $this->applyDateRangeFilter($query, 'scheduled_date', $start->toDateString(), $end->toDateString());
    }

    protected function loadClientShootCounts(Collection $shoots): array
    {
        $clientIds = $shoots->pluck('client_id')->filter()->unique()->values();
        if ($clientIds->isEmpty()) {
            return [];
        }

        return Shoot::select('client_id', DB::raw('COUNT(*) as shoots_count'))
            ->whereIn('client_id', $clientIds)
            ->groupBy('client_id')
            ->pluck('shoots_count', 'client_id')
            ->toArray();
    }

    protected function buildHistoryServiceAggregates(Builder $query): array
    {
        return $query
            ->join('shoot_service', 'shoots.id', '=', 'shoot_service.shoot_id')
            ->join('services', 'shoot_service.service_id', '=', 'services.id')
            ->leftJoinSub(
                Payment::select('shoot_id', DB::raw('SUM(amount) as paid_sum'))
                    ->where('status', Payment::STATUS_COMPLETED)
                    ->groupBy('shoot_id'),
                'shoot_payments',
                'shoots.id',
                '=',
                'shoot_payments.shoot_id'
            )
            ->select([
                'services.id as service_id',
                'services.name as service_name',
                DB::raw('COUNT(DISTINCT shoots.id) as shoots_count'),
                DB::raw('SUM(shoots.base_quote) as base_quote_sum'),
                DB::raw('SUM(shoots.tax_amount) as tax_amount_sum'),
                DB::raw('SUM(shoots.total_quote) as total_quote_sum'),
                DB::raw('COALESCE(SUM(shoot_payments.paid_sum), 0) as total_paid_sum'),
            ])
            ->groupBy('services.id', 'services.name')
            ->orderByDesc(DB::raw('COUNT(DISTINCT shoots.id)'))
            ->get()
            ->map(function ($row) {
                return [
                    'serviceId' => (int) $row->service_id,
                    'serviceName' => $row->service_name,
                    'shootCount' => (int) $row->shoots_count,
                    'baseQuoteTotal' => (float) $row->base_quote_sum,
                    'taxTotal' => (float) $row->tax_amount_sum,
                    'totalQuote' => (float) $row->total_quote_sum,
                    'totalPaid' => (float) $row->total_paid_sum,
                ];
            })
            ->values()
            ->toArray();
    }

    protected function transformHistoryShoot(Shoot $shoot, array $clientCounts): array
    {
        $shoot->loadMissing(['client', 'photographer', 'services', 'payments']);
        $client = $shoot->client;
        $services = $shoot->services->pluck('name')->filter()->values()->all();
        $completedDate = $this->resolveCompletedDate($shoot);
        $payments = $this->resolvePaymentsSummary($shoot);

        $taxPercent = 0.0;
        if ((float) $shoot->base_quote > 0 && (float) $shoot->tax_amount > 0) {
            $taxPercent = round(((float) $shoot->tax_amount / (float) $shoot->base_quote) * 100, 2);
        }

        return [
            'id' => (int) $shoot->id,
            'scheduledDate' => optional($shoot->scheduled_date)->toDateString(),
            'completedDate' => $completedDate,
            'status' => $shoot->workflow_status ?? $shoot->status,
            'client' => [
                'id' => $client->id ?? null,
                'name' => $client->name ?? null,
                'email' => $client->email ?? null,
                'phone' => $client->phonenumber ?? null,
                'company' => $client->company_name ?? null,
                'totalShoots' => $clientCounts[$shoot->client_id] ?? 0,
            ],
            'address' => [
                'street' => $shoot->address,
                'city' => $shoot->city,
                'state' => $shoot->state,
                'zip' => $shoot->zip,
                'full' => $this->formatFullAddress($shoot),
            ],
            'photographer' => [
                'id' => $shoot->photographer->id ?? null,
                'name' => $shoot->photographer->name ?? null,
            ],
            'services' => $services,
            'financials' => [
                'baseQuote' => (float) $shoot->base_quote,
                'taxPercent' => $taxPercent,
                'taxAmount' => (float) $shoot->tax_amount,
                'totalQuote' => (float) $shoot->total_quote,
                'totalPaid' => $payments['totalPaid'],
                'lastPaymentDate' => $payments['lastPaymentDate'],
                'lastPaymentType' => $shoot->payment_type,
            ],
            'tourPurchased' => $this->determineTourPurchased($shoot),
            'notes' => [
                'shoot' => $shoot->shoot_notes ?? $shoot->notes,
                'photographer' => $shoot->photographer_notes,
                'company' => $shoot->company_notes,
            ],
            'userCreatedBy' => $shoot->created_by,
            'mls_id' => $shoot->mls_id,
            'bright_mls_publish_status' => $shoot->bright_mls_publish_status,
            'bright_mls_last_published_at' => $shoot->bright_mls_last_published_at?->toIso8601String(),
        ];
    }

    protected function formatFullAddress(Shoot $shoot): string
    {
        return trim(sprintf(
            '%s, %s, %s %s',
            $shoot->address,
            $shoot->city,
            $shoot->state,
            $shoot->zip
        ), ', ');
    }

    protected function resolveCompletedDate(Shoot $shoot): ?string
    {
        if ($shoot->admin_verified_at) {
            return $shoot->admin_verified_at->toDateString();
        }

        if ($shoot->editing_completed_at) {
            return $shoot->editing_completed_at->toDateString();
        }

        if ($shoot->photos_uploaded_at) {
            return $shoot->photos_uploaded_at->toDateString();
        }

        return optional($shoot->scheduled_date)->toDateString();
    }

    protected function resolvePaymentsSummary(Shoot $shoot): array
    {
        $completedPayments = $shoot->payments
            ->where('status', Payment::STATUS_COMPLETED)
            ->sortByDesc('processed_at');

        $totalPaid = (float) $completedPayments->sum('amount');
        $lastPayment = $completedPayments->first();

        return [
            'totalPaid' => $totalPaid,
            'lastPaymentDate' => $lastPayment && $lastPayment->processed_at
                ? $lastPayment->processed_at->toDateString()
                : null,
        ];
    }

    protected function determineTourPurchased(Shoot $shoot): bool
    {
        if ($shoot->service_category && str_contains(strtolower($shoot->service_category), 'tour')) {
            return true;
        }

        return $shoot->services
            ->pluck('name')
            ->filter()
            ->map(fn($name) => strtolower($name))
            ->contains(function ($name) {
                return str_contains($name, 'tour') || str_contains($name, '360') || str_contains($name, 'virtual');
            });
    }

    protected function buildHistoryFilterMetaFromRecords(Collection $records): array
    {
        $clients = $records->pluck('client')->filter()->map(function ($client) {
            return [
                'id' => $client['id'] ?? null,
                'name' => $client['name'] ?? null,
            ];
        })->unique(function ($client) {
            return $client['id'] ?? $client['name'];
        })->values();

        $photographers = $records->pluck('photographer')->filter()->map(function ($photographer) {
            return [
                'id' => $photographer['id'] ?? null,
                'name' => $photographer['name'] ?? null,
            ];
        })->unique(function ($photographer) {
            return $photographer['id'] ?? $photographer['name'];
        })->values();

        $services = $records->flatMap(function ($record) {
            return collect($record['services'] ?? []);
        })->filter()->unique()->values();

        return [
            'clients' => $clients,
            'photographers' => $photographers,
            'services' => $services,
        ];
    }

    protected function historyCsvHeaders(bool $includeClientDetails = true): array
    {
        $headers = [
            'Scheduled Date',
            'Completed Date',
        ];

        if ($includeClientDetails) {
            $headers = array_merge($headers, [
                'Client Name',
                'Client Email Address',
                'Client Phone Number',
                'Company Name',
                'Total Number of Shoots',
            ]);
        }

        return array_merge($headers, [
            'Full Address',
            'Photographer Name',
            'Shoot Services',
            'Base Quote',
            'Tax %',
            'Tax Amount',
            'Total Quote',
            'Total Paid',
            'Last Payment Date',
            'Last Payment Type',
            'Tour Purchased',
            'Shoot Notes',
            'Photographer Notes',
            'User Account Created By',
        ]);
    }

    protected function buildHistoryCsvRow(array $record, bool $includeClientDetails = true): array
    {
        $client = $record['client'] ?? [];
        $address = $record['address']['full'] ?? '';
        $photographer = $record['photographer']['name'] ?? '';
        $services = implode(' | ', $record['services'] ?? []);
        $financials = $record['financials'] ?? [];
        $notes = $record['notes'] ?? [];

        $row = [
            $record['scheduledDate'] ?? '',
            $record['completedDate'] ?? '',
        ];

        if ($includeClientDetails) {
            $row = array_merge($row, [
                $client['name'] ?? '',
                $client['email'] ?? '',
                $client['phone'] ?? '',
                $client['company'] ?? '',
                $client['totalShoots'] ?? 0,
            ]);
        }

        return array_merge($row, [
            $address,
            $photographer,
            $services,
            number_format((float) ($financials['baseQuote'] ?? 0), 2, '.', ''),
            number_format((float) ($financials['taxPercent'] ?? 0), 2, '.', ''),
            number_format((float) ($financials['taxAmount'] ?? 0), 2, '.', ''),
            number_format((float) ($financials['totalQuote'] ?? 0), 2, '.', ''),
            number_format((float) ($financials['totalPaid'] ?? 0), 2, '.', ''),
            $financials['lastPaymentDate'] ?? '',
            $financials['lastPaymentType'] ?? '',
            ($record['tourPurchased'] ?? false) ? 'Yes' : 'No',
            $notes['shoot'] ?? '',
            $notes['photographer'] ?? '',
            $record['userCreatedBy'] ?? '',
        ]);
    }

    public function show($id)
    {
        $shoot = Shoot::with([
            'client', 'photographer', 'service', 'services', 'files', 'payments', 
            'dropboxFolders', 'workflowLogs.user', 'verifiedBy'
        ])->findOrFail($id);

        return response()->json(['data' => $this->transformShoot($shoot)]);
    }

    public function store(StoreShootRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();

        try {
            return DB::transaction(function () use ($validated, $user, $request) {
            // 1. Calculate base quote from services
            $baseQuote = $this->calculateBaseQuote($validated['services']);

            // 2. Determine tax region and calculate tax
            $taxRegion = $validated['tax_region'] ?? $this->taxService->determineTaxRegion($validated['state']);
            $taxCalculation = $this->taxService->calculateTotal($baseQuote, $taxRegion);

            // 3. Get client's rep if not provided
            $repId = $validated['rep_id'] ?? $this->getClientRep($validated['client_id']);

            // 4. Determine initial status based on user role and whether client is booking for themselves
            $scheduledAt = $validated['scheduled_at'] ? new \DateTime($validated['scheduled_at']) : null;
            
            // Clients create "requested" shoots that need approval
            // Admin/Rep/Photographer can create directly scheduled shoots
            $userRole = strtolower($user->role ?? '');
            $isClient = $userRole === 'client';
            $isAdminOrRep = in_array($userRole, ['admin', 'superadmin', 'rep', 'salesrep']);
            
            // Check if this is a client booking for themselves (client_id matches user id)
            // This handles the case where a client is logged in and booking their own shoot
            $isClientSelfBooking = $validated['client_id'] == $user->id;
            
            // Check if frontend explicitly marked this as a client request
            // This is sent when booking from client dashboard
            $isClientRequestFlag = $request->input('is_client_request', false);
            
            // Log role detection for debugging
            \Log::info('Shoot creation role check', [
                'user_id' => $user->id,
                'client_id' => $validated['client_id'],
                'user_role_raw' => $user->role,
                'user_role_normalized' => $userRole,
                'is_client' => $isClient,
                'is_admin_or_rep' => $isAdminOrRep,
                'is_client_self_booking' => $isClientSelfBooking,
                'is_client_request_flag' => $isClientRequestFlag,
            ]);

            // Treat as client request if:
            // 1. User role is 'client', OR
            // 2. Client is booking for themselves (client_id == user_id), OR
            // 3. Frontend explicitly marked this as a client request
            $treatAsClientRequest = $isClient || $isClientSelfBooking || $isClientRequestFlag;

            if ($treatAsClientRequest) {
                // Client-submitted shoots start as "requested" - awaiting approval
                $initialStatus = Shoot::STATUS_REQUESTED;
                $workflowStatus = Shoot::STATUS_REQUESTED;
                // Keep photographer if client selected one during booking
                $photographerId = $validated['photographer_id'] ?? null;
                \Log::info('Client shoot - setting requested status', ['status' => $initialStatus, 'photographer_id' => $photographerId]);
            } else {
                // Admin/Rep can directly schedule shoots
                $initialStatus = Shoot::STATUS_ON_HOLD;
                $workflowStatus = Shoot::STATUS_SCHEDULED;
                $photographerId = $validated['photographer_id'] ?? null;
            }

            // 5. Check photographer availability if scheduled (with lock to prevent race conditions)
            // Only check for non-client roles since clients don't assign photographers
            if (!$treatAsClientRequest && $photographerId && $scheduledAt) {
                // Lock photographer's shoots for this date to prevent concurrent bookings
                $carbonDate = \Carbon\Carbon::parse($scheduledAt);
                DB::table('shoots')
                    ->where('photographer_id', $photographerId)
                    ->whereDate('scheduled_at', $carbonDate->toDateString())
                    ->lockForUpdate()
                    ->get();
                
                // Now check availability (lock is held, preventing race conditions)
                // Calculate duration from services (in minutes)
                $durationMinutes = $this->calculateShootDurationFromServices($validated['services']);
                $this->checkPhotographerAvailability($photographerId, $scheduledAt, $durationMinutes);
            }

            // 6. Create shoot
            $shoot = Shoot::create([
                'client_id' => $validated['client_id'],
                'rep_id' => $repId,
                'photographer_id' => $photographerId,
                'service_id' => $validated['services'][0]['id'], // Legacy support
                'address' => $validated['address'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'zip' => $validated['zip'],
                'mls_id' => $validated['mls_id'] ?? null,
                'listing_source' => $validated['listing_source'] ?? null,
                'property_details' => $validated['property_details'] ?? null,
                'scheduled_at' => $scheduledAt,
                'scheduled_date' => $scheduledAt ? $scheduledAt->format('Y-m-d') : null, // Legacy
                'time' => $scheduledAt ? $scheduledAt->format('H:i') : ($validated['time'] ?? null), // Legacy
                'status' => $initialStatus,
                'workflow_status' => $workflowStatus,
                'base_quote' => $taxCalculation['base_quote'],
                'tax_region' => $taxCalculation['tax_region'],
                'tax_percent' => $taxCalculation['tax_percent'],
                'tax_amount' => $taxCalculation['tax_amount'],
                'total_quote' => $taxCalculation['total_quote'],
                'bypass_paywall' => $validated['bypass_paywall'] ?? false,
                'payment_status' => 'unpaid',
                'created_by' => $user->name,
                'updated_by' => $user->name,
                'package_name' => $validated['package_name'] ?? null,
                'expected_final_count' => $validated['expected_final_count'] ?? null,
                'bracket_mode' => $validated['bracket_mode'] ?? null,
                'expected_raw_count' => $this->calculateExpectedRawCount(
                    $validated['expected_final_count'] ?? null,
                    $validated['bracket_mode'] ?? null
                ),
                // Notes fields - save directly to shoot model
                'shoot_notes' => $validated['shoot_notes'] ?? null,
                'company_notes' => $validated['company_notes'] ?? null,
                'photographer_notes' => $validated['photographer_notes'] ?? null,
                'editor_notes' => $validated['editor_notes'] ?? null,
        ]);

            // 7. Attach services
            $this->attachServices($shoot, $validated['services']);

            // 8. Auto-create invoice if shoot is scheduled (not a client request)
            if ($scheduledAt && !$treatAsClientRequest) {
                try {
                    $this->invoiceService->generateForShoot($shoot);
                } catch (\Exception $e) {
                    Log::warning('Failed to auto-create invoice for shoot', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage()
                    ]);
                    // Don't fail shoot creation if invoice creation fails
                }
            }

            // 9. Create notes if provided
            $this->createNotes($shoot, $validated, $user);

            // 10. Initialize workflow state (only for non-client shoots)
            // Client-submitted requests stay in 'requested' status until approved
            if (!$treatAsClientRequest && $scheduledAt) {
                $this->workflowService->schedule($shoot, $scheduledAt, $user);
            }

            // 11. Log activity
            $activityType = $treatAsClientRequest ? 'shoot_requested' : 'shoot_created';
            $this->activityLogger->log(
                $shoot,
                $activityType,
                [
                    'by' => $user->name,
                    'status' => $initialStatus,
                    'scheduled_at' => $scheduledAt ? \Carbon\Carbon::instance($scheduledAt)->toIso8601String() : null,
                ],
                $user
            );

            // 12. Create Dropbox folders if scheduled (only for non-client shoots)
            // For client requests, folders are created when the shoot is approved
            if (!$treatAsClientRequest && $scheduledAt) {
                $this->dropboxService->createShootFolders($shoot);
            }

            // 12b. Trigger booking automation for non-client requests
            if (!$treatAsClientRequest) {
                $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
                $context = $this->automationService->buildShootContext($shoot);
                if ($shoot->rep) {
                    $context['rep'] = $shoot->rep;
                }
                $this->automationService->handleEvent('SHOOT_BOOKED', $context);
            }

            // 13. Dispatch notification job (async)
            // TODO: Create SendShootBookedNotifications job
            // dispatch(new SendShootBookedNotifications($shoot));

            // Return appropriate message based on role
            $message = $treatAsClientRequest 
                ? 'Shoot request submitted successfully. It will be reviewed by our team.'
                : 'Shoot created successfully';

            return response()->json([
                'message' => $message,
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services', 'notes']))
            ], 201);
            });
        } catch (\Exception $e) {
            \Log::error('Error creating shoot', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'validated' => $validated,
                'user_id' => $user->id ?? null,
            ]);
            
            return response()->json([
                'message' => 'Failed to create shoot: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getTraceAsString() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Calculate base quote from services
     */
    protected function calculateBaseQuote(array $services): float
    {
        $total = 0;
        $serviceIds = collect($services)->pluck('id');
        $serviceModels = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

        foreach ($services as $service) {
            $serviceModel = $serviceModels->get($service['id']);
            $price = $service['price'] ?? $serviceModel?->price ?? 0;
            $quantity = $service['quantity'] ?? 1;
            $total += $price * $quantity;
        }

        return round($total, 2);
    }

    /**
     * Get client's rep (sales rep)
     * Looks up the most recent shoot for the client and returns the rep_id
     * If no shoot exists, returns null
     */
    protected function getClientRep(int $clientId): ?int
    {
        $mostRecentShoot = Shoot::where('client_id', $clientId)
            ->whereNotNull('rep_id')
            ->orderBy('created_at', 'desc')
            ->first();
        
        return $mostRecentShoot?->rep_id;
    }

    /**
     * Check photographer availability
     * 
     * @param int $photographerId
     * @param \DateTime $scheduledAt
     * @param int|null $durationMinutes Duration in minutes (default: 120)
     * @param int|null $excludeShootId Shoot ID to exclude (for updates)
     * @throws ValidationException
     */
    protected function checkPhotographerAvailability(int $photographerId, \DateTime $scheduledAt, ?int $durationMinutes = 120, ?int $excludeShootId = null): void
    {
        $carbonDate = \Carbon\Carbon::parse($scheduledAt);
        
        // Use availability service to check with duration
        if (!$this->availabilityService->isAvailable($photographerId, $carbonDate, $durationMinutes, $excludeShootId)) {
            $validator = \Illuminate\Support\Facades\Validator::make([], []);
            $validator->errors()->add('photographer_id', 'Photographer is not available at the selected time.');
            throw new ValidationException($validator);
        }
    }

    /**
     * Calculate shoot duration in minutes from services array
     * 
     * @param array $services Array of service data with 'id' key
     * @return int Duration in minutes
     */
    protected function calculateShootDurationFromServices(array $services): int
    {
        $defaultDurationMinutes = config('availability.default_shoot_duration_minutes', 120);
        $minDurationMinutes = config('availability.min_shoot_duration_minutes', 60);
        $maxDurationMinutes = config('availability.max_shoot_duration_minutes', 240);
        
        $serviceIds = collect($services)->pluck('id')->unique();
        $serviceModels = Service::whereIn('id', $serviceIds)->get();
        
        if ($serviceModels->isEmpty()) {
            return $defaultDurationMinutes;
        }
        
        // Use max delivery_time from services (assuming it represents shoot duration)
        // Convert hours to minutes
        $maxHours = $serviceModels->max('delivery_time') ?? ($defaultDurationMinutes / 60);
        $durationMinutes = (int)($maxHours * 60);
        
        // Ensure within min/max bounds from config
        return min(max($durationMinutes, $minDurationMinutes), $maxDurationMinutes);
    }

    /**
     * Calculate shoot duration in minutes from existing shoot
     * 
     * @param Shoot $shoot
     * @return int Duration in minutes
     */
    protected function calculateShootDurationFromShoot(Shoot $shoot): int
    {
        $defaultDurationMinutes = config('availability.default_shoot_duration_minutes', 120);
        $minDurationMinutes = config('availability.min_shoot_duration_minutes', 60);
        $maxDurationMinutes = config('availability.max_shoot_duration_minutes', 240);
        
        $services = $shoot->services;
        
        if (!$services || $services->isEmpty()) {
            return $defaultDurationMinutes;
        }
        
        // Use max delivery_time from services
        $maxHours = $services->max('delivery_time') ?? ($defaultDurationMinutes / 60);
        $durationMinutes = (int)($maxHours * 60);
        
        // Ensure within min/max bounds from config
        return min(max($durationMinutes, $minDurationMinutes), $maxDurationMinutes);
    }

    /**
     * Get photographer availability
     * GET /api/photographers/{id}/availability?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function getPhotographerAvailability(Request $request, int $id)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = \Carbon\Carbon::parse($validated['from']);
        $to = \Carbon\Carbon::parse($validated['to']);

        // Limit range to 3 months max
        if ($from->diffInDays($to) > 90) {
            return response()->json([
                'message' => 'Date range cannot exceed 90 days'
            ], 422);
        }

        $availability = $this->availabilityService->getAvailabilitySummary($id, $from, $to);

        return response()->json([
            'data' => $availability,
            'photographer_id' => $id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    /**
     * Attach services to shoot
     */
    protected function attachServices(Shoot $shoot, array $services): void
    {
        $serviceIds = collect($services)->pluck('id');
        $serviceModels = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

        $pivotData = collect($services)->mapWithKeys(function ($service) use ($serviceModels) {
            $serviceModel = $serviceModels->get($service['id']);
                return [
                    $service['id'] => [
                    'price' => $service['price'] ?? $serviceModel?->price ?? 0,
                        'quantity' => $service['quantity'] ?? 1,
                        'photographer_pay' => $service['photographer_pay'] ?? null,
                    ],
                ];
            })->toArray();

            $shoot->services()->sync($pivotData);
    }

    /**
     * Create notes for shoot
     */
    protected function createNotes(Shoot $shoot, array $validated, User $user): void
    {
        $notesToCreate = [];

        if (!empty($validated['shoot_notes'])) {
            $notesToCreate[] = [
                'type' => 'shoot',
                'visibility' => 'client_visible',
                'content' => $validated['shoot_notes'],
            ];
        }

        if (!empty($validated['company_notes'])) {
            $notesToCreate[] = [
                'type' => 'company',
                'visibility' => 'internal',
                'content' => $validated['company_notes'],
            ];
        }

        if (!empty($validated['photographer_notes'])) {
            $notesToCreate[] = [
                'type' => 'photographer',
                'visibility' => 'photographer_only',
                'content' => $validated['photographer_notes'],
            ];
        }

        if (!empty($validated['editor_notes'])) {
            $notesToCreate[] = [
                'type' => 'editing',
                'visibility' => 'internal',
                'content' => $validated['editor_notes'],
            ];
        }

        foreach ($notesToCreate as $noteData) {
            $shoot->notes()->create([
                'author_id' => $user->id,
                'type' => $noteData['type'],
                'visibility' => $noteData['visibility'],
                'content' => $noteData['content'],
            ]);
        }
    }

    /**
     * Calculate expected raw count from final count and bracket mode
     */
    protected function calculateExpectedRawCount(?int $expectedFinal, ?int $bracketMode): int
    {
        if ($expectedFinal && $bracketMode) {
            return $expectedFinal * $bracketMode;
        }
        return 0;
    }

    /**
     * Schedule a shoot (move from hold_on to scheduled)
     * POST /api/shoots/{shoot}/schedule
     */
    public function schedule(UpdateShootStatusRequest $request, Shoot $shoot)
    {
        $validated = $request->validated();
        $user = $request->user();
        $originalPhotographerId = $shoot->photographer_id;

        // Check authorization - photographer can only schedule their own shoots
        if ($user->role === 'photographer' && $shoot->photographer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $scheduledAt = $validated['scheduled_at'] 
                ? new \DateTime($validated['scheduled_at']) 
                : null;

            if (!$scheduledAt) {
                return response()->json(['message' => 'scheduled_at is required'], 422);
            }

            // Check photographer availability if photographer_id is provided (with lock)
            $photographerId = $validated['photographer_id'] ?? $shoot->photographer_id;
            if ($photographerId) {
                // Lock photographer's shoots for this date to prevent concurrent bookings
                $carbonDate = \Carbon\Carbon::parse($scheduledAt);
                DB::table('shoots')
                    ->where('photographer_id', $photographerId)
                    ->whereDate('scheduled_at', $carbonDate->toDateString())
                    ->where('id', '!=', $shoot->id) // Exclude current shoot
                    ->lockForUpdate()
                    ->get();
                
                // Now check availability (lock is held, preventing race conditions)
                // Calculate duration from shoot's services (in minutes)
                $durationMinutes = $this->calculateShootDurationFromShoot($shoot);
                $this->checkPhotographerAvailability($photographerId, $scheduledAt, $durationMinutes, $shoot->id);
                
                // Update photographer if different
                if ($photographerId !== $shoot->photographer_id) {
                    $shoot->photographer_id = $photographerId;
                    $shoot->save();
                }
            }

            // Check if shoot was on hold and remove cancellation fee if it was added
            $wasOnHold = ($shoot->status === 'hold_on' || $shoot->workflow_status === 'on_hold');
            if ($wasOnHold) {
                // Remove cancellation fee (typically $60) that was added when put on hold
                // We'll check if the current total is higher than expected and remove the fee
                $cancellationFee = 60; // Standard cancellation fee
                $currentBase = $shoot->base_quote ?? 0;
                $currentTotal = $shoot->total_quote ?? 0;
                
                // If the quotes are high enough to contain the cancellation fee, remove it
                if ($currentBase >= $cancellationFee && $currentTotal >= $cancellationFee) {
                    $shoot->base_quote = max(0, $currentBase - $cancellationFee);
                    $shoot->total_quote = max(0, $currentTotal - $cancellationFee);
                    $shoot->save();
                }
            }

            $this->workflowService->schedule($shoot, $scheduledAt, $user);

            // Create Dropbox folders if not already created
            if (!$shoot->dropbox_raw_folder) {
                $this->dropboxService->createShootFolders($shoot);
            }
            
            // Reload the shoot to get the latest status from database
            $shoot->refresh();
            $shoot->load(['client', 'rep', 'photographer', 'services', 'createdByUser']);

            $context = $this->automationService->buildShootContext($shoot);
            if ($shoot->rep) {
                $context['rep'] = $shoot->rep;
            }
            $context['scheduled_at'] = $shoot->scheduled_at?->toISOString();
            $this->automationService->handleEvent('SHOOT_SCHEDULED', $context);
            $this->automationService->handleEvent('SHOOT_UPDATED', $context);

            if ($originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id) {
                $context['previous_photographer_id'] = $originalPhotographerId;
                $this->automationService->handleEvent('PHOTOGRAPHER_ASSIGNED', $context);
            }

            if ($shoot->client) {
                $this->mailService->sendShootUpdatedEmail($shoot->client, $shoot);
            }
            
            // Send notification to photographer when assigned
            if ($shoot->photographer) {
                $this->mailService->sendShootScheduledEmail($shoot->photographer, $shoot, '');
            }
            
            return response()->json([
                'message' => 'Shoot scheduled successfully',
                'data' => new ShootResource($shoot)
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Start editing (photographer has uploaded media)
     * POST /api/shoots/{shoot}/start-editing
     */
    public function startEditing(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only photographer assigned to shoot can start editing
        if ($user->role === 'photographer' && $shoot->photographer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Admin/super admin can also trigger this
        if (!in_array($user->role, ['admin', 'superadmin', 'superadmin', 'photographer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            if (Schema::hasColumn('shoots', 'editor_id') && empty($shoot->editor_id)) {
                $shoot->editor_id = 377;
                $shoot->save();
            }

            $this->workflowService->startEditing($shoot, $user);

            return response()->json([
                'message' => 'Editing started successfully',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Mark as ready for review (editor has completed editing)
     * POST /api/shoots/{shoot}/ready-for-review
     */
    public function readyForReview(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only editor or admin can mark as ready for review
        if (!in_array($user->role, ['admin', 'superadmin', 'superadmin', 'editor'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->workflowService->markReadyForReview($shoot, $user);

            return response()->json([
                'message' => 'Shoot marked as ready for review',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Mark shoot as completed (admin/super admin finalizes)
     * POST /api/shoots/{shoot}/complete
     */
    public function complete(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only admin and super admin can complete shoots
        if (!in_array($user->role, ['admin', 'superadmin', 'superadmin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->workflowService->markCompleted($shoot, $user);

            $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
            $context = $this->automationService->buildShootContext($shoot);
            if ($shoot->rep) {
                $context['rep'] = $shoot->rep;
            }
            $this->automationService->handleEvent('SHOOT_COMPLETED', $context);

            return response()->json([
                'message' => 'Shoot completed successfully',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Put shoot on hold
     * POST /api/shoots/{shoot}/put-on-hold
     */
    public function putOnHold(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Admin, super admin, rep, or assigned photographer can put on hold
        if ($user->role === 'photographer' && $shoot->photographer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($user->role, ['admin', 'superadmin', 'superadmin', 'rep', 'representative', 'photographer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $reason = $request->input('reason');
        $cancellationFee = $request->input('cancellation_fee', 0);

        try {
            $this->workflowService->putOnHold($shoot, $user, $reason);

            // Add cancellation fee if provided
            // Cancellation fee is a flat fee added to both base and total (doesn't affect tax)
            if ($cancellationFee > 0) {
                $currentBase = $shoot->base_quote ?? 0;
                $currentTotal = $shoot->total_quote ?? 0;
                
                // Add cancellation fee to base quote and total quote
                // Tax amount remains unchanged (cancellation fee is not taxed)
                $shoot->base_quote = $currentBase + $cancellationFee;
                $shoot->total_quote = $currentTotal + $cancellationFee;
                $shoot->save();
            }

            return response()->json([
                'message' => 'Shoot put on hold',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Get client-submitted shoot requests/issues from admin_issue_notes
     * GET /api/client-requests
     */
    public function clientRequests(Request $request)
    {
        $user = $request->user();

        // Only admin, superadmin, or rep can view client requests
        if (!in_array($user->role, ['admin', 'superadmin', 'rep', 'representative'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Find shoots with admin_issue_notes that contain client requests
        $query = Shoot::query()
            ->whereNotNull('admin_issue_notes')
            ->where('admin_issue_notes', '!=', '')
            ->with(['client:id,name,email'])
            ->orderByDesc('updated_at');

        // Rep can only see requests for their assigned clients
        if (in_array($user->role, ['rep', 'representative'])) {
            $query->whereHas('client', function ($q) use ($user) {
                $q->where('rep_id', $user->id);
            });
        }

        $shoots = $query->limit(100)->get();

        // Parse requests from admin_issue_notes and collect client-raised ones
        $allRequests = [];
        foreach ($shoots as $shoot) {
            if (!$shoot->admin_issue_notes) continue;
            
            $notes = explode("\n\n", $shoot->admin_issue_notes);
            foreach ($notes as $index => $note) {
                if (preg_match('/\[Request from ([^\]]+)\]:\s*(.+)/s', $note, $matches)) {
                    $raisedByName = $matches[1];
                    $noteText = trim($matches[2]);
                    
                    // Remove media IDs and assignment tags from note text for display
                    $noteText = preg_replace('/\[MediaIds: [^\]]+\]/', '', $noteText);
                    $noteText = preg_replace('/\[Assigned: [^\]]+\]/', '', $noteText);
                    $noteText = trim($noteText);
                    
                    // Check if this was raised by a client
                    $raisedByUser = \App\Models\User::where('name', $raisedByName)->first();
                    $isClientRequest = !$raisedByUser || $raisedByUser->role === 'client';
                    
                    if ($isClientRequest) {
                        $allRequests[] = [
                            'id' => 'req_' . $shoot->id . '_' . $index,
                            'note' => $noteText ?: $shoot->address . ', ' . $shoot->city,
                            'status' => $shoot->is_flagged ? 'open' : 'resolved',
                            'created_at' => $shoot->updated_at?->toISOString(),
                            'shoot' => [
                                'id' => $shoot->id,
                                'address' => $shoot->address,
                                'city' => $shoot->city,
                                'state' => $shoot->state,
                                'scheduled_date' => $shoot->scheduled_date,
                                'client' => $shoot->client ? [
                                    'id' => $shoot->client->id,
                                    'name' => $shoot->client->name,
                                ] : null,
                            ],
                        ];
                    }
                }
            }
        }

        // Sort by most recent and limit
        usort($allRequests, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        $allRequests = array_slice($allRequests, 0, 50);

        return response()->json(['data' => $allRequests]);
    }

    /**
     * Approve a requested shoot
     * POST /api/shoots/{shoot}/approve
     */
    public function approve(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only admin, superadmin, or rep can approve shoots
        if (!in_array($user->role, ['admin', 'superadmin', 'rep', 'representative'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Rep can only approve shoots for their assigned clients
        if (in_array($user->role, ['rep', 'representative'])) {
            $client = $shoot->client;
            if ($client && $client->rep_id !== $user->id) {
                return response()->json(['message' => 'You can only approve shoots for your assigned clients'], 403);
            }
        }

        // Verify shoot is in requested status
        if ($shoot->status !== Shoot::STATUS_REQUESTED && $shoot->workflow_status !== Shoot::STATUS_REQUESTED) {
            return response()->json(['message' => 'Only requested shoots can be approved'], 422);
        }

        $validated = $request->validate([
            'photographer_id' => 'nullable|exists:users,id',
            'scheduled_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        // Use existing scheduled_at if not provided
        $scheduledAt = isset($validated['scheduled_at']) 
            ? new \DateTime($validated['scheduled_at']) 
            : ($shoot->scheduled_at ? new \DateTime($shoot->scheduled_at) : new \DateTime());

        try {
            // Assign photographer if provided
            if (!empty($validated['photographer_id'])) {
                // Check photographer availability
                $durationMinutes = $this->calculateShootDurationFromServices(
                    $shoot->services->map(fn($s) => ['id' => $s->id])->toArray()
                );
                $this->checkPhotographerAvailability($validated['photographer_id'], $scheduledAt, $durationMinutes);
                $shoot->photographer_id = $validated['photographer_id'];
                $shoot->save();
            }

            // Approve the shoot
            $this->workflowService->approve($shoot, $scheduledAt, $user, $validated['notes'] ?? null);

            // Create Dropbox folders now that the shoot is approved
            $this->dropboxService->createShootFolders($shoot);

            // Auto-create invoice when shoot is approved and scheduled
            if ($scheduledAt) {
                try {
                    $this->invoiceService->generateForShoot($shoot->fresh());
                } catch (\Exception $e) {
                    Log::warning('Failed to auto-create invoice for approved shoot', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage()
                    ]);
                    // Don't fail approval if invoice creation fails
                }
            }

            $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
            $context = $this->automationService->buildShootContext($shoot);
            if ($shoot->rep) {
                $context['rep'] = $shoot->rep;
            }
            $context['scheduled_at'] = $shoot->scheduled_at?->toISOString();
            $this->automationService->handleEvent('SHOOT_BOOKED', $context);
            $this->automationService->handleEvent('SHOOT_SCHEDULED', $context);

            if ($shoot->client) {
                $this->mailService->sendShootUpdatedEmail($shoot->client, $shoot);
            }
            
            // Send notification to photographer when shoot is approved/scheduled
            if ($shoot->photographer) {
                $this->mailService->sendShootScheduledEmail($shoot->photographer, $shoot, '');
            }

            return response()->json([
                'message' => 'Shoot approved successfully',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Decline a requested shoot
     * POST /api/shoots/{shoot}/decline
     */
    public function decline(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only admin, superadmin, or rep can decline shoots
        if (!in_array($user->role, ['admin', 'superadmin', 'rep', 'representative'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Rep can only decline shoots for their assigned clients
        if (in_array($user->role, ['rep', 'representative'])) {
            $client = $shoot->client;
            if ($client && $client->rep_id !== $user->id) {
                return response()->json(['message' => 'You can only decline shoots for your assigned clients'], 403);
            }
        }

        // Verify shoot is in requested status
        if ($shoot->status !== Shoot::STATUS_REQUESTED && $shoot->workflow_status !== Shoot::STATUS_REQUESTED) {
            return response()->json(['message' => 'Only requested shoots can be declined'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        try {
            $this->workflowService->decline($shoot, $user, $validated['reason']);

            $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
            $context = $this->automationService->buildShootContext($shoot);
            if ($shoot->rep) {
                $context['rep'] = $shoot->rep;
            }
            if ($shoot->client) {
                $this->mailService->sendShootRemovedEmail($shoot->client, $shoot);
            }
            $this->automationService->handleEvent('SHOOT_CANCELED', $context);

            return response()->json([
                'message' => 'Shoot request declined',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Client requests cancellation of their shoot
     * POST /api/shoots/{shoot}/request-cancellation
     */
    public function requestCancellation(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only the client who owns the shoot can request cancellation
        if ($shoot->client_id !== $user->id && $user->role !== 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Cannot request cancellation for already cancelled/declined shoots
        if (in_array($shoot->status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])) {
            return response()->json(['message' => 'This shoot is already cancelled or declined'], 422);
        }

        // Cannot request cancellation if already requested
        if ($shoot->cancellation_requested_at) {
            return response()->json(['message' => 'Cancellation has already been requested for this shoot'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $shoot->cancellation_requested_at = now();
        $shoot->cancellation_requested_by = $user->id;
        $shoot->cancellation_reason = $validated['reason'] ?? null;
        $shoot->save();

        // Log activity
        $this->activityLogger->log(
            $shoot,
            'cancellation_requested',
            [
                'by' => $user->name,
                'reason' => $validated['reason'] ?? 'No reason provided',
            ],
            $user
        );

        return response()->json([
            'message' => 'Cancellation request submitted. Pending admin approval.',
            'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
        ]);
    }

    /**
     * Admin approves cancellation request
     * POST /api/shoots/{shoot}/approve-cancellation
     */
    public function approveCancellation(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only admin/superadmin can approve cancellation
        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Must have a cancellation request pending
        if (!$shoot->cancellation_requested_at) {
            return response()->json(['message' => 'No cancellation request pending for this shoot'], 422);
        }

        // Update shoot status to cancelled
        $shoot->status = Shoot::STATUS_CANCELLED;
        $shoot->workflow_status = Shoot::STATUS_CANCELLED;
        $shoot->updated_by = $user->id;
        $shoot->save();

        // Log activity
        $this->activityLogger->log(
            $shoot,
            'cancellation_approved',
            [
                'by' => $user->name,
                'original_reason' => $shoot->cancellation_reason,
            ],
            $user
        );

        $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
        $context = $this->automationService->buildShootContext($shoot);
        if ($shoot->rep) {
            $context['rep'] = $shoot->rep;
        }
        if ($shoot->client) {
            $this->mailService->sendShootRemovedEmail($shoot->client, $shoot);
        }
        $this->automationService->handleEvent('SHOOT_CANCELED', $context);

        return response()->json([
            'message' => 'Cancellation approved. Shoot has been cancelled.',
            'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
        ]);
    }

    /**
     * Admin rejects cancellation request
     * POST /api/shoots/{shoot}/reject-cancellation
     */
    public function rejectCancellation(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only admin/superadmin can reject cancellation
        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Must have a cancellation request pending
        if (!$shoot->cancellation_requested_at) {
            return response()->json(['message' => 'No cancellation request pending for this shoot'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        // Clear cancellation request
        $shoot->cancellation_requested_at = null;
        $shoot->cancellation_requested_by = null;
        $shoot->cancellation_reason = null;
        $shoot->save();

        // Log activity
        $this->activityLogger->log(
            $shoot,
            'cancellation_rejected',
            [
                'by' => $user->name,
                'rejection_reason' => $validated['reason'] ?? 'No reason provided',
            ],
            $user
        );

        return response()->json([
            'message' => 'Cancellation request rejected.',
            'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
        ]);
    }

    /**
     * Admin directly cancels a shoot (no client request required)
     * POST /api/shoots/{shoot}/cancel
     */
    public function cancel(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only admin/superadmin can directly cancel shoots
        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Cannot cancel already cancelled/declined shoots
        if (in_array($shoot->status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])) {
            return response()->json(['message' => 'This shoot is already cancelled or declined'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
            'cancellation_fee' => 'nullable|numeric|min:0',
            'notify_client' => 'nullable|boolean',
        ]);

        try {
            // Update shoot status to cancelled
            $shoot->status = Shoot::STATUS_CANCELLED;
            $shoot->workflow_status = Shoot::STATUS_CANCELLED;
            $shoot->cancellation_reason = $validated['reason'] ?? null;
            $shoot->updated_by = $user->id;

            // Add cancellation fee if provided
            $cancellationFee = $validated['cancellation_fee'] ?? 0;
            if ($cancellationFee > 0) {
                $currentBase = $shoot->base_quote ?? 0;
                $currentTotal = $shoot->total_quote ?? 0;
                $shoot->base_quote = $currentBase + $cancellationFee;
                $shoot->total_quote = $currentTotal + $cancellationFee;
            }

            $shoot->save();

            // Log activity
            $this->activityLogger->log(
                $shoot,
                'shoot_cancelled',
                [
                    'by' => $user->name,
                    'reason' => $validated['reason'] ?? 'No reason provided',
                    'cancellation_fee' => $cancellationFee,
                ],
                $user
            );

            // Notify client if requested (default: true)
            $notifyClient = $validated['notify_client'] ?? true;
            if ($notifyClient && $shoot->client) {
                $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
                $this->mailService->sendShootRemovedEmail($shoot->client, $shoot);
            }

            // Trigger automation event
            $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
            $context = $this->automationService->buildShootContext($shoot);
            if ($shoot->rep) {
                $context['rep'] = $shoot->rep;
            }
            $this->automationService->handleEvent('SHOOT_CANCELED', $context);

            return response()->json([
                'message' => 'Shoot has been cancelled.',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel shoot', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to cancel shoot',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get shoots with pending cancellation requests (admin only)
     * GET /api/shoots/pending-cancellations
     */
    public function pendingCancellations(Request $request)
    {
        $user = $request->user();

        // Only admin/superadmin can view pending cancellations
        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $shoots = Shoot::whereNotNull('cancellation_requested_at')
            ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])
            ->with(['client', 'rep', 'photographer', 'services'])
            ->orderBy('cancellation_requested_at', 'desc')
            ->get();

        return response()->json([
            'data' => ShootResource::collection($shoots),
            'count' => $shoots->count(),
        ]);
    }

    /**
     * Minimal update endpoint: allow admins to update status and dates.
     */
    public function update(Request $request, $shoot)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Route is /shoots/{shoot}. This may be a model-bound Shoot or a raw id.
        if (!$shoot instanceof Shoot) {
            $shoot = Shoot::findOrFail($shoot);
        }
        $isAdmin = in_array($user->role, ['admin', 'superadmin', 'superadmin']);
        $isClient = $user->role === 'client';
        $isRep = $user->role === 'salesRep';

        $requestKeys = array_keys($request->all());
        $onlyPrivateListing = count($requestKeys) > 0 && count(array_diff($requestKeys, ['is_private_listing'])) === 0;

        if (!$isAdmin) {
            if (!$onlyPrivateListing) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $ownsShoot = $isClient && (string) $shoot->client_id === (string) $user->id;
            $assignedRep = $isRep && (string) $shoot->rep_id === (string) $user->id;

            if (!$ownsShoot && !$assignedRep) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $validated = $request->validate([
            'status' => 'nullable|string|in:scheduled,completed,uploaded,editing,delivered,on_hold,cancelled',
            'workflow_status' => 'nullable|string|in:scheduled,completed,uploaded,editing,delivered,on_hold,cancelled',
            'scheduled_date' => 'nullable|date',
            'scheduled_at' => 'nullable|date',
            'time' => 'nullable|string',
            'services' => 'nullable|array',
            'services.*.id' => 'required_with:services|integer|exists:services,id',
            'services.*.price' => 'nullable|numeric|min:0',
            'services.*.quantity' => 'nullable|integer|min:1',
            // Location fields
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10',
            // Client and photographer
            'client_id' => 'nullable|exists:users,id',
            'photographer_id' => 'nullable|exists:users,id',
            // Payment fields
            'base_quote' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'total_quote' => 'nullable|numeric|min:0',
            // Property details
            'property_details' => 'nullable|array',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|numeric|min:0',
            'sqft' => 'nullable|integer|min:0',
            'is_private_listing' => 'nullable|boolean',
            // Tour links
            'tour_links' => 'nullable|array',
            // Notes fields
            'shoot_notes' => 'nullable|string',
            'company_notes' => 'nullable|string',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',
        ]);

        $previousPrivateListing = (bool) ($shoot->is_private_listing ?? false);
        $originalStatus = $shoot->status;
        $originalWorkflow = $shoot->workflow_status;
        $originalScheduledAt = $shoot->scheduled_at?->toISOString();
        $originalScheduledDate = $shoot->scheduled_date?->toDateString();
        $originalTime = $shoot->time;
        $originalPhotographerId = $shoot->photographer_id;

        if (array_key_exists('is_private_listing', $validated)) {
            $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status ?? ''));
            if (!in_array($currentStatus, [
                'delivered',
                'ready_for_client',
                'admin_verified',
                'ready',
                'completed',
                'workflow_completed',
                'client_delivered',
            ], true)) {
                return response()->json([
                    'message' => 'Only delivered/completed shoots can be marked as Private Exclusive',
                ], 422);
            }
            $shoot->is_private_listing = (bool) $validated['is_private_listing'];
        }

        if (array_key_exists('status', $validated)) {
            $shoot->status = $validated['status'];
        }
        // If marking delivered, stamp admin_verified_at
        $markDelivered = false;
        
        // Handle scheduled_at (ISO datetime from frontend) - parse into scheduled_date, time, and scheduled_at
        if (array_key_exists('scheduled_at', $validated) && $validated['scheduled_at']) {
            $scheduledAt = new \DateTime($validated['scheduled_at']);
            $shoot->scheduled_at = $scheduledAt;
            $shoot->scheduled_date = $scheduledAt->format('Y-m-d');
            $shoot->time = $scheduledAt->format('H:i');
        }
        
        if (array_key_exists('scheduled_date', $validated)) {
            $shoot->scheduled_date = $validated['scheduled_date'];
        }
        if (array_key_exists('time', $validated)) {
            $shoot->time = $validated['time'];
        }

        if (array_key_exists('workflow_status', $validated)) {
            $shoot->workflow_status = $validated['workflow_status'];
            if ($validated['workflow_status'] === Shoot::STATUS_DELIVERED) {
                $markDelivered = true;
            }
        }

        if (array_key_exists('status', $validated) && $validated['status'] === Shoot::STATUS_DELIVERED) {
            $markDelivered = true;
        }

        // Update services if provided
        if (array_key_exists('services', $validated) && is_array($validated['services'])) {
            $this->attachServices($shoot, $validated['services']);
        }

        // Update location fields
        if (array_key_exists('address', $validated)) {
            $shoot->address = $validated['address'];
        }
        if (array_key_exists('city', $validated)) {
            $shoot->city = $validated['city'];
        }
        if (array_key_exists('state', $validated)) {
            $shoot->state = $validated['state'];
        }
        if (array_key_exists('zip', $validated)) {
            $shoot->zip = $validated['zip'];
        }

        // Update client if provided
        if (array_key_exists('client_id', $validated)) {
            $shoot->client_id = $validated['client_id'];
        }

        // Update photographer if provided
        if (array_key_exists('photographer_id', $validated)) {
            $shoot->photographer_id = $validated['photographer_id'];
        }

        // Update payment fields
        if (array_key_exists('base_quote', $validated)) {
            $shoot->base_quote = $validated['base_quote'];
        }
        if (array_key_exists('tax_amount', $validated)) {
            $shoot->tax_amount = $validated['tax_amount'];
        }
        if (array_key_exists('total_quote', $validated)) {
            $shoot->total_quote = $validated['total_quote'];
        }

        // Update property details (all stored in property_details JSON column)
        $pd = $shoot->property_details ?? [];
        if (is_string($pd)) {
            $pd = json_decode($pd, true) ?? [];
        }
        
        $propertyDetailsUpdated = false;
        
        if (array_key_exists('property_details', $validated) && is_array($validated['property_details'])) {
            $pd = array_merge($pd, $validated['property_details']);
            $propertyDetailsUpdated = true;
        }
        if (array_key_exists('bedrooms', $validated)) {
            $pd['bedrooms'] = $validated['bedrooms'];
            $pd['beds'] = $validated['bedrooms'];
            $propertyDetailsUpdated = true;
        }
        if (array_key_exists('bathrooms', $validated)) {
            $pd['bathrooms'] = $validated['bathrooms'];
            $pd['baths'] = $validated['bathrooms'];
            $propertyDetailsUpdated = true;
        }
        if (array_key_exists('sqft', $validated)) {
            $pd['sqft'] = $validated['sqft'];
            $pd['squareFeet'] = $validated['sqft'];
            $propertyDetailsUpdated = true;
        }
        
        if ($propertyDetailsUpdated) {
            $shoot->property_details = $pd;
        }

        // Update tour_links if provided
        if (array_key_exists('tour_links', $validated) && is_array($validated['tour_links'])) {
            $currentTourLinks = $shoot->tour_links ?? [];
            if (is_string($currentTourLinks)) {
                $currentTourLinks = json_decode($currentTourLinks, true) ?? [];
            }
            // Merge new tour_links with existing ones
            $shoot->tour_links = array_merge($currentTourLinks, $validated['tour_links']);
        }

        // Update notes fields
        if (array_key_exists('shoot_notes', $validated)) {
            $shoot->shoot_notes = $validated['shoot_notes'];
        }
        if (array_key_exists('company_notes', $validated)) {
            $shoot->company_notes = $validated['company_notes'];
        }
        if (array_key_exists('photographer_notes', $validated)) {
            $shoot->photographer_notes = $validated['photographer_notes'];
        }
        if (array_key_exists('editor_notes', $validated)) {
            $shoot->editor_notes = $validated['editor_notes'];
        }

        $shoot->save();

        if ($previousPrivateListing !== (bool) ($shoot->is_private_listing ?? false)) {
            try {
                $this->activityLogger->log(
                    $shoot,
                    $shoot->is_private_listing ? 'private_listing_marked' : 'private_listing_unmarked',
                    [
                        'is_private_listing' => (bool) $shoot->is_private_listing,
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                    ],
                    $user
                );
            } catch (\Exception $e) {
                // ignore activity logging errors
            }
        }

        if ($markDelivered) {
            // Set admin_verified_at if not already set
            if (empty($shoot->admin_verified_at)) {
                $shoot->admin_verified_at = now();
                $shoot->save();
            }
            // Ensure workflow_status reflects delivery
            if ($shoot->workflow_status !== Shoot::STATUS_DELIVERED) {
                $shoot->workflow_status = Shoot::STATUS_DELIVERED;
                $shoot->save();
            }
        }

        $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
        $context = $this->automationService->buildShootContext($shoot);
        if ($shoot->rep) {
            $context['rep'] = $shoot->rep;
        }

        $client = $shoot->client;
        if ($client) {
            $this->mailService->sendShootUpdatedEmail($client, $shoot);
        }

        $this->automationService->handleEvent('SHOOT_UPDATED', $context);

        if ($originalPhotographerId !== $shoot->photographer_id && $shoot->photographer_id) {
            $context['previous_photographer_id'] = $originalPhotographerId;
            $this->automationService->handleEvent('PHOTOGRAPHER_ASSIGNED', $context);
        }

        $scheduledAtChanged = $originalScheduledAt !== $shoot->scheduled_at?->toISOString()
            || $originalScheduledDate !== $shoot->scheduled_date?->toDateString()
            || $originalTime !== $shoot->time;
        if ($scheduledAtChanged) {
            $context['previous_scheduled_at'] = $originalScheduledAt;
            $context['previous_scheduled_date'] = $originalScheduledDate;
            $context['previous_time'] = $originalTime;
            $this->automationService->handleEvent('SHOOT_SCHEDULED', $context);
        }

        if ($originalStatus !== $shoot->status || $originalWorkflow !== $shoot->workflow_status) {
            if (in_array($shoot->status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
                || in_array($shoot->workflow_status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)) {
                $removedRecipient = $client ?? User::find($shoot->client_id);
                if ($removedRecipient) {
                    $this->mailService->sendShootRemovedEmail($removedRecipient, $shoot);
                }
                $this->automationService->handleEvent('SHOOT_CANCELED', $context);
            }

            if ($shoot->status === Shoot::STATUS_DELIVERED || $shoot->workflow_status === Shoot::STATUS_DELIVERED) {
                $this->automationService->handleEvent('SHOOT_COMPLETED', $context);
            }

            if ($shoot->status === Shoot::STATUS_UPLOADED || $shoot->workflow_status === Shoot::STATUS_UPLOADED) {
                $this->automationService->handleEvent('PHOTO_UPLOADED', $context);
                $this->automationService->handleEvent('MEDIA_UPLOAD_COMPLETE', $context);
            }
        }

        return response()->json([
            'message' => 'Shoot updated',
            'data' => $this->transformShoot($shoot->fresh(['client','photographer','service','services','files']))
        ]);
    }

    public function destroy($shootId)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'superadmin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $shoot = Shoot::findOrFail($shootId);
        $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
        $context = $this->automationService->buildShootContext($shoot);
        if ($shoot->rep) {
            $context['rep'] = $shoot->rep;
        }
        if ($shoot->client) {
            $this->mailService->sendShootRemovedEmail($shoot->client, $shoot);
        }
        $this->automationService->handleEvent('SHOOT_REMOVED', $context);
        $shoot->delete();

        return response()->json([
            'message' => 'Shoot deleted successfully',
        ]);
    }

    public function uploadFiles(Request $request, $shootId)
    {
        // Check PHP upload errors
        $phpFileUploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
        ];
        
        Log::info('Upload request received', [
            'shoot_id' => $shootId,
            'has_files' => $request->hasFile('files'),
            'file_count' => $request->hasFile('files') ? (is_array($request->file('files')) ? count($request->file('files')) : 1) : 0,
            'all_keys' => array_keys($request->all()),
            'php_files' => array_keys($_FILES),
            'content_length' => $request->header('Content-Length'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        ]);

        // Handle both single file and array of files
        $files = $request->file('files');
        
        // If no files, check if upload was truncated due to size limits
        if (!$files) {
            $contentLength = (int) $request->header('Content-Length', 0);
            $postMaxSize = $this->parseSize(ini_get('post_max_size'));
            
            if ($contentLength > $postMaxSize) {
                return response()->json([
                    'message' => 'Upload too large. Maximum allowed: ' . ini_get('post_max_size'),
                    'errors' => ['files' => ['The uploaded file exceeds the server limit of ' . ini_get('post_max_size')]],
                ], 413);
            }
            
            return response()->json([
                'message' => 'No files received. The file may have been too large or the upload was interrupted.',
                'errors' => ['files' => ['No valid files were received by the server.']],
                'debug' => [
                    'post_max_size' => ini_get('post_max_size'),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'content_length' => $contentLength,
                ],
            ], 422);
        }
        
        // Normalize to array
        if (!is_array($files)) {
            $files = [$files];
        }
        
        // Validate each file
        foreach ($files as $file) {
            if (!$file->isValid()) {
                return response()->json([
                    'message' => 'Invalid file uploaded',
                    'errors' => ['files' => ['One or more files failed to upload properly.']],
                ], 422);
            }
            
            // Check file size (500MB limit per file - effectively unlimited for most use cases)
            $maxFileSize = 500 * 1024 * 1024; // 500MB in bytes
            if ($file->getSize() > $maxFileSize) {
                return response()->json([
                    'message' => 'File too large: ' . $file->getClientOriginalName(),
                    'errors' => ['files' => ['File exceeds 500MB limit: ' . $file->getClientOriginalName()]],
                ], 422);
            }
        }
        
        // Store normalized files back for processing
        $request->files->set('files', $files);

        $shoot = Shoot::findOrFail($shootId);
        $uploadType = $request->input('upload_type', 'raw');
        
        // Update bracket_mode if provided (for raw uploads)
        if ($uploadType === 'raw' && $request->has('bracket_mode')) {
            $bracketMode = (int) $request->input('bracket_mode');
            $shoot->bracket_mode = $bracketMode;
            
            // Calculate expected raw count based on bracket mode and expected final count
            $expectedFinalCount = $shoot->expected_final_count ?? $shoot->package?->expectedDeliveredCount ?? 0;
            $shoot->expected_raw_count = $expectedFinalCount * $bracketMode;
            $shoot->save();
        }
        
        // Update photographer notes if provided
        if ($request->has('photographer_notes')) {
            $shoot->photographer_notes = $request->input('photographer_notes');
            $shoot->save();
        }

        // Check if user is admin (admins can upload at any stage)
        $user = auth()->user();
        $isAdmin = $user && in_array($user->role, ['admin', 'superadmin']);

        if ($uploadType === 'raw' && !$isAdmin && !$shoot->canUploadPhotos()) {
            return response()->json([
                'message' => 'Cannot upload raw files at this workflow stage',
                'current_status' => $shoot->workflow_status,
            ], 400);
        }

        if ($uploadType === 'edited' && !$isAdmin && !in_array($shoot->workflow_status, [
            Shoot::STATUS_EDITING,
        ])) {
            return response()->json([
                'message' => 'Cannot upload edited files at this workflow stage',
                'current_status' => $shoot->workflow_status,
            ], 400);
        }

        $uploadedFiles = [];
        $errors = [];

        DB::beginTransaction();
        try {
            $isExtra = $request->boolean('is_extra', false);
            
            foreach ($files as $file) {
                try {
                    $serviceCategory = $request->input('service_category');

                    $shootFile = $uploadType === 'raw'
                        ? $this->dropboxService->uploadToTodo($shoot, $file, auth()->id(), $serviceCategory)
                        : $this->dropboxService->uploadToCompleted($shoot, $file, auth()->id(), $serviceCategory);

                    // Mark as extra if flagged
                    if ($isExtra && $shootFile) {
                        $shootFile->media_type = 'extra';
                        $shootFile->save();
                    }

                    $uploadedFiles[] = [
                        'id' => $shootFile->id,
                        'filename' => $shootFile->filename,
                        'workflow_stage' => $shootFile->workflow_stage,
                        'dropbox_path' => $shootFile->dropbox_path,
                        'file_size' => $shootFile->file_size,
                        'uploaded_at' => $shootFile->created_at,
                        'is_extra' => $shootFile->media_type === 'extra',
                    ];
                } catch (\Exception $e) {
                    $errors[] = [
                        'filename' => $file->getClientOriginalName(),
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $shoot = $this->refreshMediaCounters($shoot->fresh());
            DB::commit();

            return response()->json([
                'message' => 'Files processed',
                'uploaded_files' => $uploadedFiles,
                'errors' => $errors,
                'success_count' => count($uploadedFiles),
                'error_count' => count($errors),
                'shoot_status' => $shoot->workflow_status,
                'raw_photo_count' => $shoot->raw_photo_count,
                'edited_photo_count' => $shoot->edited_photo_count,
                'raw_missing_count' => $shoot->raw_missing_count,
                'edited_missing_count' => $shoot->edited_missing_count,
                'missing_raw' => $shoot->missing_raw,
                'missing_final' => $shoot->missing_final,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to upload files',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function moveFileToCompleted(Request $request, $shootId, $fileId)
    {
        $shoot = Shoot::findOrFail($shootId);
        $file = ShootFile::where('shoot_id', $shootId)->findOrFail($fileId);

        if (!$file->canMoveToCompleted()) {
            return response()->json([
                'message' => 'File cannot be moved to completed at this stage',
                'current_stage' => $file->workflow_stage
            ], 400);
        }

        try {
            $this->dropboxService->moveToCompleted($file, auth()->id());
            $shoot = $this->refreshMediaCounters($shoot->fresh());

            return response()->json([
                'message' => 'File moved to completed folder successfully',
                'file' => $file->fresh(),
                'shoot_status' => $shoot->workflow_status
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to move file to completed folder',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifyFile(Request $request, $shootId, $fileId)
    {
        $request->validate([
            'verification_notes' => 'nullable|string|max:1000'
        ]);

        $shoot = Shoot::findOrFail($shootId);
        $file = ShootFile::where('shoot_id', $shootId)->findOrFail($fileId);

        if (!$file->canVerify()) {
            return response()->json([
                'message' => 'File cannot be verified at this stage',
                'current_stage' => $file->workflow_stage
            ], 400);
        }

        // Check if user has admin permissions
        if (!in_array(auth()->user()->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $file->verify(auth()->id(), $request->verification_notes);
            
            // Move to final folder and store on server
            $this->dropboxService->moveToFinal($file, auth()->id());
            
            // Dispatch watermarking job if needed (for verified image files when payment not complete)
            if (($file->media_type === 'image' || $file->media_type === 'raw') && $file->shouldBeWatermarked()) {
                \App\Jobs\GenerateWatermarkedImageJob::dispatch($file->fresh());
            }
            
            // Check if all files are verified
            $unverifiedFiles = $shoot->files()->where('workflow_stage', '!=', ShootFile::STAGE_VERIFIED)->count();
            if ($unverifiedFiles === 0 && $shoot->workflow_status === Shoot::STATUS_EDITING) {
                $shoot->updateWorkflowStatus(Shoot::STATUS_DELIVERED, auth()->id());
                
                // Send shoot ready email to client
                $client = User::find($shoot->client_id);
                if ($client) {
                    $this->mailService->sendShootReadyEmail($client, $shoot);
                }
            }

            $shoot = $this->refreshMediaCounters($shoot->fresh());
            DB::commit();

            return response()->json([
                'message' => 'File verified and moved to final storage successfully',
                'file' => $file->fresh(),
                'shoot_status' => $shoot->workflow_status
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to verify file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle file extra status (admin/photographer only)
     */
    public function toggleFileExtra(Request $request, $shootId, $fileId)
    {
        $request->validate([
            'is_extra' => 'required|boolean'
        ]);

        $shoot = Shoot::findOrFail($shootId);
        $file = ShootFile::where('shoot_id', $shootId)->findOrFail($fileId);

        // Check if user has permission (admin or photographer assigned to shoot)
        $user = auth()->user();
        $isAdmin = in_array($user->role, ['admin', 'superadmin']);
        $isAssignedPhotographer = $shoot->photographer_id === $user->id;

        if (!$isAdmin && !$isAssignedPhotographer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $file->is_extra = $request->is_extra;
        $file->save();

        return response()->json([
            'message' => $request->is_extra ? 'File marked as extra' : 'File removed from extras',
            'file' => $file->fresh(),
        ]);
    }

    public function favoriteMedia(Shoot $shoot, ShootFile $file)
    {
        $this->authorizeFile($shoot, $file);

        $file->is_favorite = !$file->is_favorite;
        $file->save();

        return response()->json([
            'message' => 'Favorite updated',
            'file' => $file->fresh(),
        ]);
    }

    public function setCoverMedia(Shoot $shoot, ShootFile $file)
    {
        $this->authorizeFile($shoot, $file);
        $this->authorizeRole(['admin', 'superadmin', 'photographer', 'editor']);

        $shoot->files()->where('is_cover', true)->update(['is_cover' => false]);
        $file->is_cover = true;
        $file->save();

        return response()->json([
            'message' => 'Cover updated',
            'file' => $file->fresh(),
            'hero_image' => $this->resolveFileUrl($file->fresh()),
        ]);
    }

    public function flagMedia(Request $request, Shoot $shoot, ShootFile $file)
    {
        $this->authorizeFile($shoot, $file);
        $this->authorizeRole(['admin', 'superadmin', 'editor', 'photographer']);

        $request->validate([
            'reason' => 'nullable|string|max:500',
            'clear_flag' => 'nullable|boolean',
        ]);

        if ($request->boolean('clear_flag')) {
            $file->flag_reason = null;
            if ($file->workflow_stage === ShootFile::STAGE_FLAGGED) {
                $file->workflow_stage = ShootFile::STAGE_TODO;
            }
            $file->save();

            if ($shoot->files()->whereNotNull('flag_reason')->count() === 0) {
                $shoot->is_flagged = false;
                $shoot->admin_issue_notes = null;
                $shoot->save();
            }

            return response()->json([
                'message' => 'Flag cleared',
                'file' => $file->fresh(),
            ]);
        }

        $file->flag_reason = $request->input('reason', 'Flagged via dashboard');
        $file->workflow_stage = ShootFile::STAGE_FLAGGED;
        $file->save();

        $shoot->is_flagged = true;
        $shoot->admin_issue_notes = $file->flag_reason;
        $shoot->save();

        return response()->json([
            'message' => 'File flagged',
            'file' => $file->fresh(),
        ]);
    }

    public function commentMedia(Request $request, Shoot $shoot, ShootFile $file)
    {
        $this->authorizeFile($shoot, $file);
        $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $comments = $file->metadata['comments'] ?? [];
        $comments[] = [
            'author' => auth()->user()->name ?? 'User',
            'comment' => $request->input('comment'),
            'timestamp' => now()->toIso8601String(),
        ];

        $file->metadata = array_merge($file->metadata ?? [], ['comments' => $comments]);
        $file->save();

        return response()->json([
            'message' => 'Comment added',
            'file' => $file->fresh(),
        ]);
    }

    public function reorderMedia(Request $request, Shoot $shoot)
    {
        $this->authorizeRole(['admin', 'superadmin', 'photographer']);

        $request->validate([
            'files' => 'required|array',
            'files.*.id' => 'required|exists:shoot_files,id',
            'files.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($request->input('files') as $fileData) {
                $shootFile = $shoot->files()->findOrFail($fileData['id']);
                $shootFile->sort_order = $fileData['sort_order'];
                $shootFile->save();
            }

            DB::commit();

            return response()->json(['message' => 'Media order updated successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reorder media', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to update media order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteMedia(Shoot $shoot, ShootFile $file)
    {
        $this->authorizeFile($shoot, $file);
        $this->authorizeRole(['admin', 'superadmin', 'photographer', 'editor']);

        return $this->performFileDeletion($shoot, $file);
    }

    public function downloadMedia(Shoot $shoot, ShootFile $file)
    {
        $this->authorizeFile($shoot, $file);
        $url = $this->resolveFileUrl($file);

        if (!$url) {
            return response()->json(['message' => 'File not available'], 404);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Preview a file - serves as a proxy for Dropbox files or redirects to local storage
     */
    public function previewFile(Shoot $shoot, ShootFile $file)
    {
        $this->authorizeFile($shoot, $file);

        // Try local storage first
        if ($file->path && Storage::disk('public')->exists($file->path)) {
            $path = Storage::disk('public')->path($file->path);
            $mimeType = mime_content_type($path) ?: 'image/jpeg';
            return response()->file($path, ['Content-Type' => $mimeType]);
        }

        // Try URL field
        if ($file->url && Str::startsWith($file->url, 'http')) {
            return redirect($file->url);
        }

        // Try Dropbox
        if ($file->dropbox_path) {
            try {
                $url = $this->dropboxService->getTemporaryLink($file->dropbox_path);
                if ($url) {
                    return redirect($url);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get Dropbox preview link', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['message' => 'File not available'], 404);
    }

    public function bulkDownloadMedia(Request $request, Shoot $shoot)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $files = $shoot->files()->whereIn('id', $request->input('ids'))->get();
        $urls = $files->map(function (ShootFile $file) {
            return $this->resolveFileUrl($file);
        })->filter()->values();

        return response()->json([
            'count' => $urls->count(),
            'urls' => $urls,
        ]);
    }

    public function bulkDeleteMedia(Request $request, Shoot $shoot)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $this->authorizeRole(['admin', 'superadmin', 'photographer', 'editor']);

        $files = $shoot->files()->whereIn('id', $request->input('ids'))->get();
        $errors = [];
        foreach ($files as $file) {
            $response = $this->performFileDeletion($shoot, $file, suppressResponse: true);
            if ($response instanceof \Illuminate\Http\JsonResponse && $response->getStatusCode() !== 200) {
                $errors[] = $file->id;
            }
        }

        return response()->json([
            'message' => empty($errors) ? 'Files deleted' : 'Some files failed to delete',
            'failed_ids' => $errors,
        ], empty($errors) ? 200 : 207);
    }

    protected function authorizeFile(Shoot $shoot, ShootFile $file): void
    {
        if ($file->shoot_id !== $shoot->id) {
            abort(404, 'File does not belong to this shoot');
        }
    }

    protected function authorizeRole(array $roles): void
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, $roles)) {
            abort(403, 'Forbidden');
        }
    }

    protected function performFileDeletion(Shoot $shoot, ShootFile $file, bool $suppressResponse = false)
    {
        try {
            if ($file->path && Storage::disk('public')->exists($file->path)) {
                Storage::disk('public')->delete($file->path);
            }
            $file->delete();
            $shoot = $this->refreshMediaCounters($shoot->fresh());

            if ($suppressResponse) {
                return null;
            }

            return response()->json([
                'message' => 'File deleted',
                'shoot_status' => $shoot->workflow_status,
                'raw_photo_count' => $shoot->raw_photo_count,
                'edited_photo_count' => $shoot->edited_photo_count,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete file', ['error' => $e->getMessage()]);

            $response = response()->json([
                'message' => 'Failed to delete file',
                'error' => $e->getMessage(),
            ], 500);

            if ($suppressResponse) {
                return $response;
            }

            return $response;
        }
    }

    public function getWorkflowStatus($shootId)
    {
        $shoot = Shoot::with(['files', 'workflowLogs.user'])->findOrFail($shootId);
        
        $fileStats = [
            'total' => $shoot->files->count(),
            'todo' => $shoot->files->where('workflow_stage', ShootFile::STAGE_TODO)->count(),
            'completed' => $shoot->files->where('workflow_stage', ShootFile::STAGE_COMPLETED)->count(),
            'verified' => $shoot->files->where('workflow_stage', ShootFile::STAGE_VERIFIED)->count(),
            'flagged' => $shoot->files->where('workflow_stage', ShootFile::STAGE_FLAGGED)->count(),
        ];

        return response()->json([
            'shoot_id' => $shoot->id,
            'workflow_status' => $shoot->workflow_status,
            'file_stats' => $fileStats,
            'workflow_logs' => $shoot->workflowLogs->take(10),
            'can_upload_photos' => $shoot->canUploadPhotos(),
            'can_move_to_completed' => $shoot->canMoveToCompleted(),
            'can_verify' => $shoot->canVerify()
        ]);
    }

    /**
     * Finalize a shoot: take all edited (completed-stage) files, copy to server final storage,
     * mark them verified, and advance the shoot workflow. Meant for an admin toggle action.
     * Only works for shoots in editing status.
     */
    public function finalize(Request $request, $shootId)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin','superadmin','superadmin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'final_status' => 'nullable|string|in:admin_verified,completed'
        ]);

        $shoot = Shoot::with(['files'])->findOrFail($shootId);

        $completedFiles = $shoot->files()->where('workflow_stage', ShootFile::STAGE_COMPLETED)->get();
        $rawFiles = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->get();

        // Allow finalization if:
        // 1. Shoot is in editing status (normal flow)
        // 2. OR there are edited files but no raw files (direct edited upload)
        $hasEditedWithoutRaw = $completedFiles->isNotEmpty() && $rawFiles->isEmpty();
        $isInEditingStatus = $shoot->workflow_status === Shoot::STATUS_EDITING;
        
        if (!$isInEditingStatus && !$hasEditedWithoutRaw) {
            return response()->json([
                'message' => 'Shoot can only be finalized from editing status, or when edited files exist without raw files',
                'current_status' => $shoot->workflow_status
            ], 400);
        }

        if ($completedFiles->isEmpty()) {
            return response()->json([
                'message' => 'No edited files to finalize',
                'data' => $shoot->only(['id','workflow_status'])
            ], 400);
        }

        try {
            foreach ($completedFiles as $file) {
                // Move/copy to server final storage and mark verified
                $this->dropboxService->moveToFinal($file, $user->id);
            }

            // Advance workflow status directly to delivered
            $shoot->updateWorkflowStatus(Shoot::STATUS_DELIVERED, $user->id);
            $shoot->save();

            $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
            $context = $this->automationService->buildShootContext($shoot);
            if ($shoot->rep) {
                $context['rep'] = $shoot->rep;
            }
            $this->automationService->handleEvent('SHOOT_COMPLETED', $context);

            return response()->json([
                'message' => 'Shoot finalized successfully',
                'data' => $shoot->fresh(['files'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to finalize shoot',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Mark issues as resolved (photographer/editor endpoint)
     */
    public function markIssuesResolved(Request $request, $shootId)
    {
        $user = auth()->user();
        $shoot = Shoot::findOrFail($shootId);

        // Check if user is the photographer, editor, or admin
        $isPhotographer = $shoot->photographer_id === $user->id;
        $isEditor = $shoot->editor_id === $user->id || $user->role === 'editor';
        $isAdmin = in_array($user->role, ['admin', 'superadmin']);
        
        if (!$isPhotographer && !$isEditor && !$isAdmin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check if shoot has issues (on_hold, raw_issue, or editing_issue)
        $hasIssues = in_array($shoot->workflow_status, [
            Shoot::STATUS_ON_HOLD,
        ]) || $shoot->is_flagged;
        
        if (!$hasIssues) {
            return response()->json([
                'message' => 'Shoot is not on hold with issues',
                'current_status' => $shoot->workflow_status,
                'is_flagged' => $shoot->is_flagged
            ], 400);
        }

        // Mark issues as resolved
        $shoot->issues_resolved_at = now();
        $shoot->issues_resolved_by = $user->id;
        $shoot->is_flagged = false;
        // Keep admin_issue_notes for reference, but workflow status changes
        $shoot->workflow_status = Shoot::STATUS_UPLOADED;
        $shoot->status = Shoot::STATUS_UPLOADED;
        $shoot->save();

        // Log the action
        $shoot->workflowLogs()->create([
            'user_id' => $user->id,
            'action' => 'issues_resolved',
            'details' => 'Photographer marked issues as resolved',
            'metadata' => [
                'resolved_by' => $user->id,
                'timestamp' => now()->toISOString()
            ]
        ]);

        return response()->json([
            'message' => 'Issues marked as resolved. Shoot resubmitted for review.',
            'data' => $shoot->fresh(['client','photographer','service','files'])
        ]);
    }

    /**
     * Get issues/requests for a shoot
     * GET /api/shoots/{shoot}/issues
     */
    public function getIssues($shootId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();

        $paymentStatus = $shoot->payment_status;
        if (!$paymentStatus || $paymentStatus === 'pending') {
            $totalPaid = $shoot->total_paid ?? 0;
            $totalQuote = $shoot->total_quote ?? 0;
            $paymentStatus = $this->calculatePaymentStatus($totalPaid, $totalQuote);
        }

        $isClient = $user && $user->role === 'client';
        $needsWatermark = $isClient && !$shoot->bypass_paywall && $paymentStatus !== 'paid';

        $resolvePreviewPath = function (?string $path) {
            if (!$path) {
                return null;
            }
            if (preg_match('/^https?:\/\//i', $path)) {
                return $path;
            }

            $clean = ltrim($path, '/');
            if (Str::startsWith($clean, 'storage/')) {
                $clean = substr($clean, 8);
            }

            if (Storage::disk('public')->exists($clean)) {
                $encoded = implode('/', array_map('rawurlencode', explode('/', $clean)));
                $url = Storage::disk('public')->url($encoded);
                if (!preg_match('/^https?:\/\//i', $url)) {
                    $base = rtrim(config('app.url'), '/');
                    $url = $base . '/' . ltrim($url, '/');
                }
                return $url;
            }

            try {
                return $this->dropboxService->getTemporaryLink($path);
            } catch (\Exception $e) {
                Log::warning('Failed to resolve issue preview path', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        };

        $queueWatermark = function (ShootFile $file) {
            try {
                \App\Jobs\GenerateWatermarkedImageJob::dispatch($file->fresh())->onQueue('watermarks');
            } catch (\Exception $e) {
                Log::warning('Failed to queue watermark job for issue preview', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        $resolvePreviewUrl = function (ShootFile $file, string $size) use ($needsWatermark, $resolvePreviewPath, $queueWatermark) {
            if ($needsWatermark) {
                $path = match ($size) {
                    'thumbnail' => $file->watermarked_thumbnail_path ?? $file->watermarked_placeholder_path,
                    default => $file->watermarked_web_path
                        ?? $file->watermarked_thumbnail_path
                        ?? $file->watermarked_placeholder_path,
                };

                if ($path) {
                    return $resolvePreviewPath($path);
                }

                if ($file->shouldBeWatermarked()) {
                    $queueWatermark($file);
                }

                return null;
            }

            $path = match ($size) {
                'thumbnail' => $file->thumbnail_path ?? $file->placeholder_path,
                default => $file->web_path
                    ?? $file->thumbnail_path
                    ?? $file->placeholder_path,
            };

            return $resolvePreviewPath($path);
        };
        
        // Parse requests from admin_issue_notes (temporary solution until issues table is created)
        $requests = [];
        if ($shoot->admin_issue_notes) {
            // Parse notes that start with [Request from ...]
            $notes = explode("\n\n", $shoot->admin_issue_notes);
            foreach ($notes as $index => $note) {
                if (preg_match('/\[Request from ([^\]]+)\]:\s*(.+)/s', $note, $matches)) {
                    $raisedByName = $matches[1];
                    $fullNoteText = trim($matches[2]);
                    
                    // Extract media IDs if present
                    $mediaIds = [];
                    $noteText = $fullNoteText;
                    if (preg_match('/\[MediaIds: ([^\]]+)\]/', $fullNoteText, $mediaMatches)) {
                        $mediaIds = array_filter(array_map('trim', explode(',', $mediaMatches[1])));
                        // Remove the media IDs line from note text
                        $noteText = trim(str_replace($mediaMatches[0], '', $fullNoteText));
                    }
                    
                    // Extract assignment info if present
                    $assignedToRole = null;
                    if (preg_match('/\[Assigned: (editor|photographer)\]/', $noteText, $assignMatches)) {
                        $assignedToRole = $assignMatches[1];
                        // Remove assignment tag from note text
                        $noteText = trim(str_replace($assignMatches[0], '', $noteText));
                    }
                    // Also check full notes for assignment (in case it's at the end)
                    if (!$assignedToRole && preg_match('/\[Assigned: (editor|photographer)\]/', $shoot->admin_issue_notes, $assignMatches)) {
                        $assignedToRole = $assignMatches[1];
                    }
                    
                    // Fetch media file information with URLs
                    $mediaFiles = [];
                    if (!empty($mediaIds)) {
                        $files = \App\Models\ShootFile::whereIn('id', $mediaIds)->get();
                        foreach ($files as $file) {
                            $fileUrl = $resolvePreviewUrl($file, 'web');
                            $thumbnailUrl = $resolvePreviewUrl($file, 'thumbnail') ?? $fileUrl;

                            $mediaFiles[] = [
                                'id' => (string)$file->id,
                                'filename' => $file->filename ?? $file->stored_filename ?? 'unknown',
                                'url' => $fileUrl,
                                'thumbnail' => $thumbnailUrl,
                            ];
                        }
                    }
                    
                    // Try to find user by name
                    $raisedByUser = \App\Models\User::where('name', $raisedByName)->first();
                    
                    $requests[] = [
                        'id' => 'req_' . $shoot->id . '_' . $index,
                        'shootId' => (string)$shoot->id,
                        'note' => $noteText,
                        'mediaId' => !empty($mediaIds) ? (string)$mediaIds[0] : null,
                        'mediaIds' => array_map('strval', $mediaIds),
                        'mediaFiles' => $mediaFiles,
                        'raisedBy' => [
                            'id' => $raisedByUser ? (string)$raisedByUser->id : 'unknown',
                            'name' => $raisedByName,
                            'role' => $raisedByUser ? $raisedByUser->role : 'client',
                        ],
                        'assignedToRole' => $assignedToRole,
                        'status' => $shoot->is_flagged ? 'open' : 'resolved',
                        'createdAt' => $shoot->updated_at->toISOString(),
                        'updatedAt' => $shoot->updated_at->toISOString(),
                    ];
                }
            }
        }
        
        return response()->json([
            'data' => $requests
        ]);
    }

    /**
     * Create an issue/request for a shoot
     * POST /api/shoots/{shoot}/issues
     */
    public function createIssue($shootId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        
        // ImpersonationMiddleware handles user swap - $request->user() returns impersonated user if applicable
        $user = $request->user();
        
        $validated = $request->validate([
            'note' => 'required|string',
            'mediaId' => 'nullable|exists:shoot_files,id',
            'mediaIds' => 'nullable|array',
            'mediaIds.*' => 'exists:shoot_files,id',
            'assignedToRole' => 'nullable|in:editor,photographer',
            'assignedToUserId' => 'nullable|exists:users,id',
        ]);
        
        // For now, just store in admin_issue_notes as a simple implementation
        // TODO: Create proper shoot_issues table and model
        $note = $validated['note'];
        
        // Collect media IDs (from mediaId or mediaIds)
        $mediaIds = [];
        if (!empty($validated['mediaIds'])) {
            $mediaIds = $validated['mediaIds'];
        } elseif (!empty($validated['mediaId'])) {
            $mediaIds = [$validated['mediaId']];
        }
        
        // Build request entry with media IDs encoded
        $requestEntry = "[Request from " . $user->name . "]: " . $note;
        if (!empty($mediaIds)) {
            $requestEntry .= "\n[MediaIds: " . implode(',', $mediaIds) . "]";
        }
        
        if ($shoot->admin_issue_notes) {
            $shoot->admin_issue_notes = $shoot->admin_issue_notes . "\n\n" . $requestEntry;
        } else {
            $shoot->admin_issue_notes = $requestEntry;
        }
        $shoot->is_flagged = true;
        $shoot->save();
        
        // Fetch media file information for response
        $mediaFiles = [];
        if (!empty($mediaIds)) {
            $files = \App\Models\ShootFile::whereIn('id', $mediaIds)->get();
            foreach ($files as $file) {
                $mediaFiles[] = [
                    'id' => (string)$file->id,
                    'filename' => $file->filename ?? $file->stored_filename ?? 'unknown',
                ];
            }
        }
        
        return response()->json([
            'message' => 'Request created successfully',
            'data' => [
                'id' => 'temp_' . time(),
                'shootId' => $shoot->id,
                'note' => $note,
                'mediaId' => !empty($mediaIds) ? (string)$mediaIds[0] : null,
                'mediaIds' => array_map('strval', $mediaIds),
                'mediaFiles' => $mediaFiles,
                'raisedBy' => [
                    'id' => (string)$user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                ],
                'status' => 'open',
                'createdAt' => now()->toISOString(),
                'updatedAt' => now()->toISOString(),
            ]
        ], 201);
    }

    /**
     * Update an issue/request
     * PATCH /api/shoots/{shoot}/issues/{issue}
     */
    public function updateIssue($shootId, $issueId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();
        
        $validated = $request->validate([
            'status' => 'nullable|in:open,in-progress,resolved',
        ]);
        
        // TODO: Implement when shoot_issues table is created
        return response()->json([
            'message' => 'Request updated successfully',
        ]);
    }

    /**
     * Assign an issue/request to photographer or editor
     * POST /api/shoots/{shoot}/issues/{issue}/assign
     */
    public function assignIssue($shootId, $issueId, Request $request)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();
        
        // Only admin can assign requests
        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Only admins can assign requests'], 403);
        }
        
        $validated = $request->validate([
            'assignedToRole' => 'required|in:editor,photographer',
            'assignedToUserId' => 'nullable|exists:users,id',
        ]);
        
        $assignedTo = $validated['assignedToRole'];
        
        // Update admin_issue_notes to add assignment info
        // Parse the notes and add assignment tag to the specific issue
        if ($shoot->admin_issue_notes) {
            $notes = $shoot->admin_issue_notes;
            
            // Add or update assignment tag at the end of the notes
            // Format: [Assigned: photographer|editor]
            $assignmentTag = "[Assigned: {$assignedTo}]";
            
            // Remove any existing assignment tags
            $notes = preg_replace('/\[Assigned: (editor|photographer)\]/', '', $notes);
            
            // Add new assignment tag
            $shoot->admin_issue_notes = trim($notes) . "\n" . $assignmentTag;
            $shoot->save();
        }
        
        return response()->json([
            'message' => 'Request assigned successfully',
            'assignedTo' => $assignedTo,
        ]);
    }

    /**
     * Get all client requests for admin dashboard
     * GET /api/client-requests
     */
    public function getClientRequests(Request $request)
    {
        $user = $request->user();
        
        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        // Get all shoots with admin_issue_notes that contain client requests
        $shoots = Shoot::whereNotNull('admin_issue_notes')
            ->where('admin_issue_notes', 'like', '%[Request from%')
            ->with(['client:id,name'])
            ->get();
        
        $requests = [];
        foreach ($shoots as $shoot) {
            if ($shoot->admin_issue_notes) {
                $notes = explode("\n\n", $shoot->admin_issue_notes);
                foreach ($notes as $index => $note) {
                    if (preg_match('/\[Request from ([^\]]+)\]:\s*(.+)/s', $note, $matches)) {
                        $raisedByName = $matches[1];
                        $noteText = trim($matches[2]);
                        
                        // Only include if raised by a client
                        $raisedByUser = \App\Models\User::where('name', $raisedByName)->first();
                        if ($raisedByUser && $raisedByUser->role === 'client') {
                            $requests[] = [
                                'id' => 'req_' . $shoot->id . '_' . $index,
                                'shootId' => (string)$shoot->id,
                                'shoot' => [
                                    'id' => $shoot->id,
                                    'address' => $shoot->address,
                                    'client' => $shoot->client ? [
                                        'id' => $shoot->client->id,
                                        'name' => $shoot->client->name,
                                    ] : null,
                                ],
                                'note' => $noteText,
                                'raisedBy' => [
                                    'id' => (string)$raisedByUser->id,
                                    'name' => $raisedByName,
                                    'role' => 'client',
                                ],
                                'status' => $shoot->is_flagged ? 'open' : 'resolved',
                                'createdAt' => $shoot->updated_at->toISOString(),
                                'updatedAt' => $shoot->updated_at->toISOString(),
                            ];
                        }
                    }
                }
            }
        }
        
        return response()->json([
            'data' => $requests
        ]);
    }

    /**
     * Simplified notes updater: directly updates any provided note fields
     */
    public function updateNotesSimple(Request $request, $shootId)
    {
        $shoot = Shoot::findOrFail($shootId);

        $request->validate([
            'shoot_notes' => 'nullable|string',
            'company_notes' => 'nullable|string',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',
        ]);

        $data = $request->only(['shoot_notes','company_notes','photographer_notes','editor_notes']);

        // Also accept common camelCase keys
        $camel = [
            'shootNotes' => 'shoot_notes',
            'companyNotes' => 'company_notes',
            'photographerNotes' => 'photographer_notes',
            'editingNotes' => 'editor_notes',
            'editorNotes' => 'editor_notes',
        ];
        foreach ($camel as $from => $to) {
            if ($request->has($from) && !array_key_exists($to, $data)) {
                $data[$to] = $request->input($from);
            }
        }

        if (!empty($data)) {
            $shoot->fill($data);
            $shoot->save();
        }

        return response()->json([
            'message' => empty($data) ? 'No changes detected' : 'Notes updated',
            'data' => $shoot->only(['id','shoot_notes','company_notes','photographer_notes','editor_notes'])
        ]);
    }

    /**
     * Update notes on a shoot with role-based permissions
     */
    public function updateNotes(Request $request, $shootId)
    {
        $shoot = Shoot::findOrFail($shootId);

        $user = $request->user();
        $role = strtolower($user->role ?? '');
        $role = str_replace('-', '_', $role);
        if ($role === 'superadmin') { $role = 'superadmin'; }

        $request->validate([
            'shoot_notes' => 'nullable|string',
            'company_notes' => 'nullable|string',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',
        ]);

        $allowed = [];
        if (in_array($role, ['admin', 'superadmin'])) {
            $allowed = ['shoot_notes', 'company_notes', 'photographer_notes', 'editor_notes'];
        } elseif ($role === 'client') {
            $allowed = ['shoot_notes'];
        } elseif ($role === 'photographer') {
            $allowed = ['photographer_notes'];
        } elseif ($role === 'editor') {
            $allowed = ['editor_notes'];
        }

        if (empty($allowed)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Build updates allowing empty strings and camelCase keys; update only allowed fields
        $input = $request->all();
        // Map camelCase to snake_case if provided
        $synonyms = [
            'shootNotes' => 'shoot_notes',
            'companyNotes' => 'company_notes',
            'photographerNotes' => 'photographer_notes',
            'editingNotes' => 'editor_notes',
            'editorNotes' => 'editor_notes',
        ];
        foreach ($synonyms as $from => $to) {
            if (array_key_exists($from, $input) && !array_key_exists($to, $input)) {
                $input[$to] = $input[$from];
            }
        }

        // Also accept a nested `notes` object payload and flatten it
        if (array_key_exists('notes', $input)) {
            $notesPayload = $input['notes'];
            if (is_string($notesPayload)) {
                // Treat as shoot notes string
                $input['shoot_notes'] = $notesPayload;
            } elseif (is_array($notesPayload)) {
                foreach ($synonyms as $from => $to) {
                    if (array_key_exists($from, $notesPayload) && !array_key_exists($to, $input)) {
                        $input[$to] = $notesPayload[$from];
                    }
                }
                // Also allow snake_case inside notes
                foreach (['shoot_notes','company_notes','photographer_notes','editor_notes'] as $field) {
                    if (array_key_exists($field, $notesPayload) && !array_key_exists($field, $input)) {
                        $input[$field] = $notesPayload[$field];
                    }
                }
            }
        }

        $candidateFields = ['shoot_notes','company_notes','photographer_notes','editor_notes'];
        $updates = [];
        foreach ($candidateFields as $field) {
            if (array_key_exists($field, $input)) {
                // Only include if role is allowed to change this field
                if (in_array($field, $allowed)) {
                    $updates[$field] = $input[$field];
                } else {
                    // If an unallowed field is present, ignore it silently to avoid UX errors
                    continue;
                }
            }
        }

        // If nothing to update (no provided fields or none allowed), respond OK to avoid blocking UI
        if (empty($updates)) {
            return response()->json([
                'message' => 'No changes detected',
                'data' => $shoot->only(['id','shoot_notes','company_notes','photographer_notes','editor_notes'])
            ]);
        }

        foreach ($updates as $k => $v) {
            $shoot->{$k} = $v; // allow empty string to overwrite
        }
        $shoot->save();

        return response()->json([
            'message' => 'Notes updated',
            'data' => $shoot->only(['id','shoot_notes','company_notes','photographer_notes','editor_notes'])
        ]);
    }

    /**
     * Normalize payment status to valid values
     */
    private function normalizePaymentStatus($status)
    {
        $statusMap = [
            'paid' => 'paid',
            'unpaid' => 'unpaid',
            'partial' => 'partial',
            'pending' => 'unpaid',
            'complete' => 'paid',
            'completed' => 'paid',
        ];

        return $statusMap[strtolower($status)] ?? 'unpaid';
    }

    /**
     * Normalize shoot status to valid values
     */
    private function normalizeStatus($status)
    {
        $statusMap = [
            'booked' => 'booked',
            'cancelled' => 'cancelled',
            'completed' => 'completed',
            'active' => 'booked',
            'pending' => 'booked',
            'scheduled' => 'booked',
            'in_progress' => 'booked',
            'done' => 'completed',
            'finished' => 'completed',
        ];

        return $statusMap[strtolower($status)] ?? 'booked';
    }

    // ----- Public assets (read-only, no auth) -----
    private function buildPublicAssets(\App\Models\Shoot $shoot)
    {
        // Prefer verified > completed > todo files
        $files = $shoot->files;
        $verified = $files->where('workflow_stage', \App\Models\ShootFile::STAGE_VERIFIED);
        $completed = $files->where('workflow_stage', \App\Models\ShootFile::STAGE_COMPLETED);
        $todo = $files->where('workflow_stage', \App\Models\ShootFile::STAGE_TODO);
        
        if ($verified->count() > 0) {
            $chosen = $verified;
        } elseif ($completed->count() > 0) {
            $chosen = $completed;
        } else {
            $chosen = $todo; // Fallback to todo if no verified/completed
        }

        $mapUrl = function($file) {
            // Check url field first (may contain direct URL)
            $url = $file->url ?? '';
            if ($url && preg_match('/^https?:\/\//i', $url)) return $url;
            
            $path = $file->path ?? '';
            if (!$path) return null;
            
            // If path is already an absolute URL, return as-is
            if (preg_match('/^https?:\/\//i', $path)) return $path;

            $base = rtrim(config('app.url'), '/');
            
            // Try multiple path variations to find file on public disk
            $pathsToTry = [
                ltrim($path, '/'),
                str_replace('storage/', '', ltrim($path, '/')),
                'shoots/' . $file->shoot_id . '/final/' . ($file->stored_filename ?? $file->filename ?? ''),
                'shoots/' . $file->shoot_id . '/completed/' . ($file->stored_filename ?? $file->filename ?? ''),
            ];
            
            foreach ($pathsToTry as $tryPath) {
                if (!$tryPath) continue;
                if (Storage::disk('public')->exists($tryPath)) {
                    $diskUrl = Storage::disk('public')->url($tryPath);
                    if (!preg_match('/^https?:\/\//i', $diskUrl)) {
                        $diskUrl = $base . '/' . ltrim($diskUrl, '/');
                    }
                    return $diskUrl;
                }
            }
            
            return null; // skip non-local files (e.g., pure Dropbox paths)
        };

        $photos = [];
        $videos = [];
        foreach ($chosen as $f) {
            $url = $mapUrl($f);
            if (!$url) continue;
            $type = strtolower((string) $f->file_type);
            if (str_starts_with($type, 'image/')) {
                $photos[] = $url;
            } elseif (str_starts_with($type, 'video/')) {
                $videos[] = $url;
            } else {
                // check by extension as fallback
                $ext = strtolower(pathinfo($f->filename ?? $f->stored_filename ?? '', PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','bmp','tif','tiff','heic','heif'])) {
                    $photos[] = $url;
                } elseif (in_array($ext, ['mp4','mov','avi','webm','ogg'])) {
                    $videos[] = $url;
                }
            }
        }

        return [
            'shoot' => [
                'id' => $shoot->id,
                'client_name' => optional($shoot->client)->name,
                'client_company' => optional($shoot->client)->company_name,
                'address' => $shoot->address,
                'city' => $shoot->city,
                'state' => $shoot->state,
                'zip' => $shoot->zip,
                'scheduled_date' => optional($shoot->scheduled_date)->toDateString(),
            ],
            'photos' => array_values(array_unique($photos)),
            'videos' => array_values(array_unique($videos)),
            // Placeholder for 3D tour links if stored later
            'tours' => [
                'matterport' => null,
                'iGuide' => null,
                'cubicasa' => null,
            ],
        ];
    }

    private function resolvePublicShoot(Request $request, $shootId = null): ?Shoot
    {
        if ($shootId) {
            return Shoot::with(['files', 'client'])->find($shootId);
        }

        $address = trim((string) $request->query('address', ''));
        $city = trim((string) $request->query('city', ''));
        $state = trim((string) $request->query('state', ''));
        $zip = trim((string) $request->query('zip', ''));

        if ($address === '' || $city === '' || $state === '') {
            return null;
        }

        $query = Shoot::with(['files', 'client'])
            ->whereRaw('LOWER(address) = ?', [strtolower($address)])
            ->whereRaw('LOWER(city) = ?', [strtolower($city)])
            ->whereRaw('LOWER(state) = ?', [strtolower($state)]);

        if ($zip !== '') {
            $query->where('zip', $zip);
        }

        return $query->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->first();
    }

    public function publicBranded(Request $request, $shootId = null)
    {
        $shoot = $this->resolvePublicShoot($request, $shootId);
        if (!$shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }
        $assets = $this->buildPublicAssets($shoot);
        $assets['type'] = 'branded';
        // Include integration data
        $assets['property_details'] = $shoot->property_details;
        $tourLinks = $shoot->tour_links ?? [];
        $iguideUrl = $shoot->iguide_tour_url
            ?? $tourLinks['iguide_branded']
            ?? $tourLinks['iGuide']
            ?? $tourLinks['iguide_mls']
            ?? null;
        $assets['iguide_tour_url'] = $iguideUrl;
        $assets['iguide_url'] = $iguideUrl;
        $assets['iguide_floorplans'] = $shoot->iguide_floorplans;
        $assets['floorplans'] = $shoot->iguide_floorplans;
        // Include tour links from shoot model
        $assets['matterport_url'] = $tourLinks['matterport_branded'] ?? $tourLinks['matterport'] ?? null;
        $assets['embeds'] = $tourLinks['embeds'] ?? [];
        $assets['tour_links'] = $tourLinks; // Include full tour_links including tour_style
        $assets['tour_style'] = $tourLinks['tour_style'] ?? 'default';
        return response()->json($assets);
    }

    public function publicMls(Request $request, $shootId = null)
    {
        $shoot = $this->resolvePublicShoot($request, $shootId);
        if (!$shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }
        $assets = $this->buildPublicAssets($shoot);
        $assets['type'] = 'mls';
        // Include integration data
        $assets['property_details'] = $shoot->property_details;
        $tourLinks = $shoot->tour_links ?? [];
        $iguideUrl = $shoot->iguide_tour_url
            ?? $tourLinks['iguide_mls']
            ?? $tourLinks['iguide_branded']
            ?? $tourLinks['iGuide']
            ?? null;
        $assets['iguide_tour_url'] = $iguideUrl;
        $assets['iguide_url'] = $iguideUrl;
        $assets['iguide_floorplans'] = $shoot->iguide_floorplans;
        $assets['floorplans'] = $shoot->iguide_floorplans;
        // Include tour links from shoot model
        $assets['matterport_url'] = $tourLinks['matterport_mls'] ?? $tourLinks['matterport'] ?? null;
        $assets['embeds'] = $tourLinks['embeds'] ?? [];
        $assets['tour_links'] = $tourLinks; // Include full tour_links including tour_style
        $assets['tour_style'] = $tourLinks['tour_style'] ?? 'default';
        return response()->json($assets);
    }

    public function publicGenericMls(Request $request, $shootId = null)
    {
        $shoot = $this->resolvePublicShoot($request, $shootId);
        if (!$shoot) {
            return response()->json(['message' => 'Shoot not found'], 404);
        }
        $assets = $this->buildPublicAssets($shoot);
        $assets['type'] = 'generic-mls';
        // Include integration data (no branding/address for generic MLS)
        $assets['property_details'] = $shoot->property_details;
        $tourLinks = $shoot->tour_links ?? [];
        $iguideUrl = $shoot->iguide_tour_url
            ?? $tourLinks['iguide_mls']
            ?? $tourLinks['iguide_branded']
            ?? $tourLinks['iGuide']
            ?? null;
        $assets['iguide_tour_url'] = $iguideUrl;
        $assets['iguide_url'] = $iguideUrl;
        $assets['iguide_floorplans'] = $shoot->iguide_floorplans;
        $assets['floorplans'] = $shoot->iguide_floorplans;
        // Include tour links from shoot model (use MLS variants for generic)
        $assets['matterport_url'] = $tourLinks['matterport_mls'] ?? $tourLinks['matterport'] ?? null;
        $assets['embeds'] = $tourLinks['embeds'] ?? [];
        $assets['tour_links'] = $tourLinks; // Include full tour_links including tour_style
        $assets['tour_style'] = $tourLinks['tour_style'] ?? 'default';
        return response()->json($assets);
    }

    /**
     * Client profile: basic client info and their shoots with previewable assets.
     * Requires authentication and proper authorization.
     */
    public function publicClientProfile(Request $request, $clientId)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }

        $client = \App\Models\User::findOrFail($clientId);

        // Authorization checks
        $hasAccess = false;
        
        if (in_array($user->role, ['admin', 'superadmin'])) {
            // Admins can see any client profile
            $hasAccess = true;
        } elseif ($user->role === 'client' && $user->id == $client->id) {
            // Clients can only see their own profile
            $hasAccess = true;
        } elseif ($user->role === 'salesRep') {
            // Sales reps can see profiles of their clients
            // Check if client has this rep assigned
            $metadata = $client->metadata ?? [];
            $repId = $metadata['accountRepId'] 
                ?? $metadata['account_rep_id'] 
                ?? $metadata['repId'] 
                ?? $metadata['rep_id'] 
                ?? null;
            
            if ($repId && (string)$repId === (string)$user->id) {
                $hasAccess = true;
            } elseif ($client->created_by_id && (string)$client->created_by_id === (string)$user->id) {
                $hasAccess = true;
            } else {
                // Check if any shoots have this rep assigned
                $hasShootsWithRep = Shoot::where('client_id', $client->id)
                    ->where('rep_id', $user->id)
                    ->exists();
                $hasAccess = $hasShootsWithRep;
            }
        }

        if (!$hasAccess) {
            return response()->json(['message' => 'You do not have permission to view this client profile'], 403);
        }

        // Include all shoots for this client (completed, delivered, or with files)
        // Apply additional filtering based on role
        $shootsQuery = Shoot::with(['files'])
            ->where('client_id', $client->id);
        
        // If user is a sales rep, only show shoots they're assigned to
        if ($user->role === 'salesRep') {
            $shootsQuery->where(function ($q) use ($user) {
                $q->where('rep_id', $user->id)
                  ->orWhereNull('rep_id'); // Include shoots without rep assignment
            });
        }
        
        $shoots = $shootsQuery
            ->whereIn('status', [
                Shoot::STATUS_COMPLETED,
                Shoot::STATUS_DELIVERED,
                Shoot::STATUS_SCHEDULED,
                Shoot::STATUS_REQUESTED,
            ])
            ->orderByDesc('scheduled_date')
            ->get();

        // Helper to convert file path to accessible URL, checking payment status
        $resolveWatermarkedPath = function(?string $path) {
            if (!$path) return null;
            if (preg_match('/^https?:\/\//i', $path)) return $path;

            $clean = ltrim($path, '/');
            if (Storage::disk('public')->exists($clean)) {
                $encoded = implode('/', array_map('rawurlencode', explode('/', $clean)));
                $url = Storage::disk('public')->url($encoded);
                if (!preg_match('/^https?:\/\//i', $url)) {
                    $base = rtrim(config('app.url'), '/');
                    $url = $base . '/' . ltrim($url, '/');
                }
                return $url;
            }

            try {
                return $this->dropboxService->getTemporaryLink($path);
            } catch (\Exception $e) {
                Log::warning('Failed to resolve watermarked path', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        };

        $getFileUrl = function($file, string $size = 'web') use ($shoots, $resolveWatermarkedPath) {
            if (!$file) return null;
            
            // Find the shoot this file belongs to
            $shoot = $shoots->firstWhere('id', $file->shoot_id);
            if (!$shoot) return null;
            
            // Calculate payment status if not set
            $paymentStatus = $shoot->payment_status;
            if (!$paymentStatus || $paymentStatus === 'pending') {
                $totalPaid = $shoot->total_paid ?? 0;
                $totalQuote = $shoot->total_quote ?? 0;
                $paymentStatus = $this->calculatePaymentStatus($totalPaid, $totalQuote);
            }
            
            // Check if shoot needs watermarking (for clients viewing their own profile)
            $needsWatermark = !$shoot->bypass_paywall && $paymentStatus !== 'paid';
            
            if ($needsWatermark) {
                $watermarkedPath = match ($size) {
                    'thumbnail' => $file->watermarked_thumbnail_path ?? $file->watermarked_placeholder_path,
                    'placeholder' => $file->watermarked_placeholder_path ?? $file->watermarked_thumbnail_path,
                    default => $file->watermarked_web_path
                        ?? $file->watermarked_thumbnail_path
                        ?? $file->watermarked_placeholder_path,
                };

                if ($watermarkedPath) {
                    return $resolveWatermarkedPath($watermarkedPath);
                }

                // Watermark not generated yet - trigger generation immediately (synchronously for first access)
                if ($file->shouldBeWatermarked()) {
                    try {
                        $watermarkJob = new \App\Jobs\GenerateWatermarkedImageJob($file->fresh());
                        $watermarkJob->handle(app(\App\Services\DropboxWorkflowService::class));
                        $file->refresh();

                        $watermarkedPath = match ($size) {
                            'thumbnail' => $file->watermarked_thumbnail_path ?? $file->watermarked_placeholder_path,
                            'placeholder' => $file->watermarked_placeholder_path ?? $file->watermarked_thumbnail_path,
                            default => $file->watermarked_web_path
                                ?? $file->watermarked_thumbnail_path
                                ?? $file->watermarked_placeholder_path,
                        };

                        return $resolveWatermarkedPath($watermarkedPath);
                    } catch (\Exception $e) {
                        \App\Jobs\GenerateWatermarkedImageJob::dispatch($file->fresh());
                        Log::warning('Failed to generate watermark synchronously for client profile', [
                            'file_id' => $file->id,
                            'error' => $e->getMessage(),
                        ]);
                        return null;
                    }
                }

                return null;
            }
            
            // For paid shoots or non-clients: Use processed preview sizes only
            $publicPath = $file->web_path ?? $file->thumbnail_path;
            if (!$publicPath) return null;
            
            // If already a full URL, return as-is
            if (preg_match('/^https?:\/\//i', $publicPath)) {
                return $publicPath;
            }
            
            // Handle local storage paths
            $clean = ltrim($publicPath, '/');
            $publicRelative = str_starts_with($clean, 'storage/') ? substr($clean, 8) : $clean;
            if (\Storage::disk('public')->exists($publicRelative)) {
                $url = \Storage::disk('public')->url($publicRelative);
                if (!preg_match('/^https?:\/\//i', $url)) {
                    $base = rtrim(config('app.url'), '/');
                    $url = $base . '/' . ltrim($url, '/');
                }
                return $url;
            }
            
            // Handle Dropbox paths - get temporary link
            if (str_contains($publicPath, '/') && !str_starts_with($publicPath, 'http')) {
                try {
                    return $this->dropboxService->getTemporaryLink($publicPath);
                } catch (\Exception $e) {
                    Log::warning('Failed to get Dropbox link for public client profile', [
                        'file_id' => $file->id,
                        'path' => $publicPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return null;
        };

        $mapUrl = function($path) {
            if (!$path) return null;
            if (preg_match('/^https?:\/\//i', $path)) return $path;
            $clean = ltrim($path, '/');
            $publicRelative = str_starts_with($clean, 'storage/') ? substr($clean, 8) : $clean;
            if (\Storage::disk('public')->exists($publicRelative)) {
                $url = \Storage::disk('public')->url($publicRelative);
                if (!preg_match('/^https?:\/\//i', $url)) {
                    $base = rtrim(config('app.url'), '/');
                    $url = $base . '/' . ltrim($url, '/');
                }
                return $url;
            }
            return null;
        };

        $shootItems = $shoots->map(function ($s) use ($getFileUrl, $mapUrl) {
            $files = $s->files ?: collect();
            
            // Try to get hero_image first, then fall back to first verified file
            $preview = null;
            if ($s->hero_image) {
                $preview = $mapUrl($s->hero_image);
            }
            
            if (!$preview) {
                // Try verified files first - use payment-aware URLs
                $verifiedFiles = $files->where('workflow_stage', \App\Models\ShootFile::STAGE_VERIFIED);
                $imageFile = $verifiedFiles->first(function ($f) { 
                    return str_starts_with(strtolower((string)$f->file_type), 'image/'); 
                });
                if ($imageFile) {
                    $preview = $getFileUrl($imageFile, 'thumbnail');
                }
            }
            
            if (!$preview) {
                // Fall back to any image file - use payment-aware URLs
                $imageFile = $files->first(function ($f) { 
                    return str_starts_with(strtolower((string)$f->file_type), 'image/'); 
                });
                if ($imageFile) {
                    $preview = $getFileUrl($imageFile, 'thumbnail');
                }
            }

            // Build gallery of all image file URLs - use payment-aware URLs
            $gallery = $files->filter(function ($f) {
                return str_starts_with(strtolower((string)$f->file_type), 'image/');
            })->map(function ($f) use ($getFileUrl) {
                return $getFileUrl($f, 'web');
            })->filter()->values()->toArray();

            $tourLinks = $s->tour_links ?? [];
            $iguideUrl = $s->iguide_tour_url
                ?? $tourLinks['iguide_branded']
                ?? $tourLinks['iguide_mls']
                ?? $tourLinks['iGuide']
                ?? null;

            return [
                'id' => $s->id,
                'address' => $s->address,
                'city' => $s->city,
                'state' => $s->state,
                'zip' => $s->zip,
                'scheduled_date' => optional($s->scheduled_date)->toDateString(),
                'status' => $s->status,
                'files_count' => $files->count(),
                'preview_image' => $preview,
                'gallery' => $gallery,
                'iguide_tour_url' => $iguideUrl,
                'tour_links' => $tourLinks,
            ];
        });

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'company_name' => $client->company_name,
                'phonenumber' => $client->phonenumber,
                'avatar' => $client->avatar,
            ],
            'shoots' => $shootItems,
        ]);
    }

    protected function transformShoot(Shoot $shoot)
    {
        // Only load missing relationships if not already loaded
        $shoot->loadMissing(['client', 'photographer', 'service', 'services', 'rep', 'createdByUser']);
        // Only load files if not already loaded (they should be from eager loading)
        if (!$shoot->relationLoaded('files')) {
            $shoot->load(['files' => function ($query) {
                $query->select('id', 'shoot_id', 'workflow_stage', 'is_favorite', 'is_cover', 'flag_reason', 'url', 'path', 'dropbox_path');
            }]);
        }
        // Load payments if not already loaded to calculate total_paid
        if (!$shoot->relationLoaded('payments')) {
            $shoot->load('payments');
        }
        $shoot->append('total_paid', 'remaining_balance', 'total_photographer_pay');
        
        // Calculate and set payment_status if not set or invalid
        if (!$shoot->payment_status || !in_array($shoot->payment_status, ['paid', 'unpaid', 'partial'])) {
            $totalPaid = $shoot->total_paid ?? 0;
            $totalQuote = $shoot->total_quote ?? 0;
            $shoot->payment_status = $this->calculatePaymentStatus($totalPaid, $totalQuote);
        }
        
        // Set created_by_name based on who created the shoot
        $createdByName = 'Unknown';
        if ($shoot->created_by) {
            // Try to get the user who created it
            $createdByUser = $shoot->createdByUser ?? \App\Models\User::find($shoot->created_by);
            if ($createdByUser) {
                // If superadmin, show "superadmin", otherwise show name
                if ($createdByUser->role === 'superadmin') {
                    $createdByName = 'superadmin';
                } else {
                    $createdByName = $createdByUser->name ?? 'Unknown';
                }
            } else {
                // If created_by is set but user not found, check if it's rep_id
                if ($shoot->rep_id && $shoot->rep) {
                    $createdByName = $shoot->rep->name ?? 'Unknown';
                } elseif ($shoot->client_id && $shoot->client) {
                    $createdByName = $shoot->client->name ?? 'Unknown';
                } elseif ($shoot->photographer_id && $shoot->photographer) {
                    $createdByName = $shoot->photographer->name ?? 'Unknown';
                }
            }
        } else {
            // If created_by is null, check relationships
            if ($shoot->rep_id && $shoot->rep) {
                $createdByName = $shoot->rep->name ?? 'Unknown';
            } elseif ($shoot->client_id && $shoot->client) {
                $createdByName = $shoot->client->name ?? 'Unknown';
            } elseif ($shoot->photographer_id && $shoot->photographer) {
                $createdByName = $shoot->photographer->name ?? 'Unknown';
            }
        }
        $shoot->setAttribute('created_by_name', $createdByName);

        // Handle client data based on requesting user's role
        // Photographers and editors should NOT see client details
        $requestingUser = auth()->user();
        $requestingRole = $requestingUser ? strtolower($requestingUser->role ?? '') : '';
        $hideClientFromRole = in_array($requestingRole, ['photographer', 'editor']);
        
        if ($shoot->client && !$hideClientFromRole) {
            $clientData = [
                'id' => $shoot->client->id,
                'name' => $shoot->client->name,
                'email' => $shoot->client->email,
                'company_name' => $shoot->client->company_name ?? $shoot->client->company ?? null,
                'phonenumber' => $shoot->client->phonenumber ?? $shoot->client->phone ?? null,
            ];
            
            // Add client's account rep info from client metadata, or fall back to shoot's rep
            $clientMetadata = $shoot->client->metadata ?? [];
            $clientRepId = $clientMetadata['accountRepId'] 
                ?? $clientMetadata['account_rep_id'] 
                ?? $clientMetadata['repId'] 
                ?? $clientMetadata['rep_id'] 
                ?? $shoot->client->created_by_id 
                ?? null;
            
            // First try to find rep from client's metadata/created_by
            $clientRep = null;
            if ($clientRepId) {
                $clientRep = User::find($clientRepId);
            }
            
            // Fallback to shoot's assigned rep (already loaded via loadMissing)
            if (!$clientRep && $shoot->rep) {
                $clientRep = $shoot->rep;
            }
            
            if ($clientRep) {
                $clientData['rep'] = [
                    'id' => $clientRep->id,
                    'name' => $clientRep->name,
                    'email' => $clientRep->email,
                ];
            }
            
            $shoot->setAttribute('client', $clientData);
        } elseif ($hideClientFromRole) {
            // Hide client info from photographers and editors
            $shoot->setAttribute('client', null);
        }

        $shoot->package = [
            'name' => $shoot->package_name ?? optional($shoot->service)->name,
            'expectedDeliveredCount' => $shoot->expected_final_count,
            'bracketMode' => $shoot->bracket_mode,
            'servicesIncluded' => !empty($shoot->package_services_included)
                ? $shoot->package_services_included
                : $shoot->services->pluck('name')->toArray(),
        ];

        $shoot->weather = [
            'summary' => $shoot->weather_summary,
            'temperature' => $shoot->weather_temperature,
        ];

        $shoot->dropbox_paths = [
            'rawFolder' => $shoot->dropbox_raw_folder,
            'extraFolder' => $shoot->dropbox_extra_folder,
            'editedFolder' => $shoot->dropbox_edited_folder,
            'archiveFolder' => $shoot->dropbox_archive_folder,
        ];

        $shoot->media_summary = $this->buildMediaSummary($shoot);
        // Optimize hero image resolution - avoid expensive Dropbox calls during bulk operations
        // Only resolve if hero_image is not already set
        if (!$shoot->hero_image) {
            $shoot->hero_image = $this->resolveHeroImage($shoot, false); // Pass false to skip Dropbox API calls
        }
        $shoot->primary_action = $this->getPrimaryActionForRole(
            $shoot,
            auth()->user()->role ?? 'client'
        );

        // Include notes fields explicitly
        $shoot->shoot_notes = $shoot->shoot_notes;
        $shoot->company_notes = $shoot->company_notes;
        $shoot->photographer_notes = $shoot->photographer_notes;
        $shoot->editor_notes = $shoot->editor_notes;

        // Include integration fields
        $shoot->mls_id = $shoot->mls_id;
        $shoot->listing_source = $shoot->listing_source;
        $shoot->property_details = $shoot->property_details;
        $shoot->integration_flags = $shoot->integration_flags;
        $shoot->bright_mls_publish_status = $shoot->bright_mls_publish_status;
        $shoot->bright_mls_last_published_at = $shoot->bright_mls_last_published_at;
        $shoot->bright_mls_manifest_id = $shoot->bright_mls_manifest_id;
        $shoot->iguide_tour_url = $shoot->iguide_tour_url;
        $shoot->iguide_floorplans = $shoot->iguide_floorplans;
        $shoot->iguide_last_synced_at = $shoot->iguide_last_synced_at;
        $shoot->is_private_listing = $shoot->is_private_listing ?? false;
        $shoot->mmm_status = $shoot->mmm_status;
        $shoot->mmm_order_number = $shoot->mmm_order_number;
        $shoot->mmm_buyer_cookie = $shoot->mmm_buyer_cookie;
        $shoot->mmm_redirect_url = $shoot->mmm_redirect_url;
        $shoot->mmm_last_punchout_at = $shoot->mmm_last_punchout_at;
        $shoot->mmm_last_order_at = $shoot->mmm_last_order_at;
        $shoot->mmm_last_error = $shoot->mmm_last_error;
        
        // Explicitly include tour_links to ensure it's in the response
        $shoot->tour_links = $shoot->tour_links ?? [];

        // Explicitly include services as an array of names for frontend compatibility
        // Ensure services relationship is loaded and has data
        $servicesArray = $shoot->services->pluck('name')->filter()->values()->all();
        
        // Set as attribute so it's included in JSON serialization
        $shoot->setAttribute('services_list', $servicesArray);
        
        // Also ensure the services relationship is properly serialized
        // This ensures frontend gets both services (relationship) and services_list (array)
        if ($shoot->relationLoaded('services')) {
            // Services relationship is already loaded, it will be included in JSON response
        }

        return $shoot;
    }

    protected function buildMediaSummary(Shoot $shoot): array
    {
        return [
            'rawUploaded' => $shoot->raw_photo_count ?? 0,
            'editedUploaded' => $shoot->edited_photo_count ?? 0,
            'extraUploaded' => $shoot->extra_photo_count ?? 0,
            'flagged' => $shoot->files->whereNotNull('flag_reason')->count(),
            'favorites' => $shoot->files->where('is_favorite', true)->count(),
            'delivered' => $shoot->files->where('workflow_stage', ShootFile::STAGE_VERIFIED)->count(),
        ];
    }

    protected function resolveHeroImage(Shoot $shoot, bool $allowDropboxCalls = true): ?string
    {
        // First priority: explicitly set cover
        $cover = $shoot->files->firstWhere('is_cover', true);
        if ($cover) {
            return $this->resolveFileUrl($cover, $allowDropboxCalls);
        }

        // Second priority: first displayable image (JPG, PNG, WEBP, GIF)
        $displayableExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $displayable = $shoot->files->first(function ($file) use ($displayableExtensions) {
            $filename = $file->filename ?? $file->path ?? '';
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            return in_array($ext, $displayableExtensions);
        });
        if ($displayable) {
            return $this->resolveFileUrl($displayable, $allowDropboxCalls);
        }

        // Fallback: first file (even if not displayable)
        $first = $shoot->files->first();
        return $first ? $this->resolveFileUrl($first, $allowDropboxCalls) : null;
    }

    protected function resolveFileUrl(?ShootFile $file, bool $allowDropboxCalls = true): ?string
    {
        if (!$file) {
            return null;
        }

        if ($file->url) {
            return $file->url;
        }

        if ($file->path && Str::startsWith($file->path, 'http')) {
            return $file->path;
        }

        if ($file->path && Storage::disk('public')->exists($file->path)) {
            return Storage::disk('public')->url($file->path);
        }

        // Fallback: generate URL from path even if exists check fails (may be encoding issue)
        if ($file->path && !Str::startsWith($file->path, 'http') && !$file->dropbox_path) {
            return Storage::disk('public')->url($file->path);
        }

        // Skip expensive Dropbox API calls during bulk operations to save memory/time
        if ($file->dropbox_path && $allowDropboxCalls) {
            try {
                return $this->dropboxService->getTemporaryLink($file->dropbox_path);
            } catch (\Exception $e) {
                // If Dropbox call fails, return null rather than throwing
                Log::warning('Failed to get Dropbox link', ['file_id' => $file->id, 'error' => $e->getMessage()]);
                return null;
            }
        }

        // Return a proxy URL for Dropbox files when skipping API calls
        // This allows the frontend to fetch the file through our backend proxy
        if ($file->dropbox_path && !$allowDropboxCalls) {
            return url('/api/shoots/' . $file->shoot_id . '/files/' . $file->id . '/preview');
        }

        return null;
    }

    protected function refreshMediaCounters(Shoot $shoot): Shoot
    {
        $rawCount = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->count();
        $editedCount = $shoot->files()
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->count();

        $shoot->raw_photo_count = $rawCount;
        $shoot->edited_photo_count = $editedCount;

        $expectedRaw = $shoot->expected_raw_count ?? 0;
        $expectedFinal = $shoot->expected_final_count ?? 0;

        $shoot->raw_missing_count = max(0, $expectedRaw - $rawCount);
        $shoot->edited_missing_count = max(0, $expectedFinal - $editedCount);
        $shoot->missing_raw = $shoot->raw_missing_count > 0;
        $shoot->missing_final = $shoot->edited_missing_count > 0;

        $shoot->save();

        return $shoot->fresh(['files']);
    }

    protected function getPrimaryActionForRole(Shoot $shoot, string $role): array
    {
        switch ($role) {
            case 'client':
                if ($shoot->remaining_balance > 0) {
                    return ['label' => 'Pay Now', 'action' => 'pay'];
                }
                return ['label' => 'View Media', 'action' => 'view_media'];
            case 'photographer':
                if (in_array($shoot->workflow_status, [
                    Shoot::WORKFLOW_BOOKED,
                    Shoot::WORKFLOW_RAW_UPLOAD_PENDING,
                    Shoot::WORKFLOW_RAW_ISSUE,
                ])) {
                    return ['label' => 'Upload RAW', 'action' => 'upload_raw'];
                }
                return ['label' => 'Open Workflow', 'action' => 'open_workflow'];
            case 'editor':
                return ['label' => 'Upload Finals', 'action' => 'upload_final'];
            case 'admin':
            case 'superadmin':
            case 'salesRep':
            default:
                return ['label' => 'Open Workflow', 'action' => 'open_workflow'];
        }
    }

    /**
     * Get all files for a shoot
     */
    public function getFiles($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        
        $type = strtolower((string) $request->query('type', ''));
        
        // Get current user for cache key and access control
        $user = auth()->user();
        $userId = $user ? $user->id : 'guest';
        $userRole = $user ? $user->role : 'guest';
        
        // Build cache key - include user ID and role for proper per-user caching
        $cacheKey = 'shoot_files_' . $id . '_' . $type . '_' . $userId . '_' . $userRole;
        
        // Check cache first (cache for 30 seconds)
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json(['data' => $cached]);
        }
        
        Log::debug('getFiles called', [
            'shoot_id' => $id,
            'user_id' => $userId,
            'user_role' => $userRole,
            'type' => $type,
        ]);

        // Get files from database (optionally scoped by type)
        // Order by sort_order first (for manual sorting), then by created_at as fallback
        $filesQuery = $shoot->files()->orderBy('sort_order', 'asc')->orderBy('created_at', 'desc');

        if ($type === 'raw') {
            // RAW uploads live in the TODO stage (older data may have null workflow_stage)
            $filesQuery->where(function ($q) {
                $q->where('workflow_stage', 'todo')
                  ->orWhereNull('workflow_stage');
            });
        } elseif ($type === 'edited') {
            // Edited uploads live in COMPLETED/VERIFIED
            $filesQuery->whereIn('workflow_stage', ['completed', 'verified']);

            // Also ensure we only return renderable edited formats (avoid RAW files like .NEF)
            $filesQuery->where(function ($q) {
                $q->whereRaw(
                    "LOWER(COALESCE(file_type, mime_type, '')) IN ('image/jpeg','image/jpg','image/png','image/pjpeg')"
                )
                  ->orWhereRaw("LOWER(COALESCE(file_type, mime_type, '')) LIKE 'video/%'")
                  ->orWhereRaw("LOWER(COALESCE(filename, '')) LIKE '%.jpg'")
                  ->orWhereRaw("LOWER(COALESCE(filename, '')) LIKE '%.jpeg'")
                  ->orWhereRaw("LOWER(COALESCE(filename, '')) LIKE '%.png'")
                  ->orWhereRaw("LOWER(COALESCE(filename, '')) LIKE '%.mp4'")
                  ->orWhereRaw("LOWER(COALESCE(filename, '')) LIKE '%.mov'")
                  ->orWhereRaw("LOWER(COALESCE(filename, '')) LIKE '%.avi'")
                  ->orWhereRaw("LOWER(COALESCE(filename, '')) LIKE '%.mkv'")
                  ->orWhereRaw("LOWER(COALESCE(filename, '')) LIKE '%.wmv'")
                  ->orWhereRaw("LOWER(COALESCE(stored_filename, '')) LIKE '%.jpg'")
                  ->orWhereRaw("LOWER(COALESCE(stored_filename, '')) LIKE '%.jpeg'")
                  ->orWhereRaw("LOWER(COALESCE(stored_filename, '')) LIKE '%.png'")
                  ->orWhereRaw("LOWER(COALESCE(stored_filename, '')) LIKE '%.mp4'")
                  ->orWhereRaw("LOWER(COALESCE(stored_filename, '')) LIKE '%.mov'")
                  ->orWhereRaw("LOWER(COALESCE(stored_filename, '')) LIKE '%.avi'")
                  ->orWhereRaw("LOWER(COALESCE(stored_filename, '')) LIKE '%.mkv'")
                  ->orWhereRaw("LOWER(COALESCE(stored_filename, '')) LIKE '%.wmv'");
            });
        }

        $files = $filesQuery->get();
        
        Log::debug('getFiles query result', [
            'shoot_id' => $id,
            'type' => $type,
            'files_count' => $files->count(),
            'user_id' => $userId,
            'user_role' => $userRole,
            'files_workflow_stages' => $files->pluck('workflow_stage')->unique()->toArray(),
        ]);
        
        // Batch Dropbox URL generation to reduce API calls
        $dropboxFiles = $files->filter(function ($file) {
            return $file->dropbox_path && !$file->url && !$file->path;
        });
        
        // Pre-fetch Dropbox URLs for files that need them
        $dropboxUrls = [];
        foreach ($dropboxFiles as $file) {
            try {
                $urlCacheKey = 'dropbox_url_' . md5($file->dropbox_path);
                $url = Cache::remember($urlCacheKey, now()->addHours(4), function () use ($file) {
                    return $this->dropboxService->getTemporaryLink($file->dropbox_path);
                });
                if ($url) {
                    $dropboxUrls[$file->id] = $url;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get Dropbox link', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            }
        }
        
        // Format files for frontend
        // Use $request->user() to get impersonated user if applicable (not auth()->user())
        $user = $request->user();
        $isClient = $user && $user->role === 'client';
        $paymentStatus = $shoot->payment_status;
        if (!$paymentStatus || $paymentStatus === 'pending') {
            $totalPaid = $shoot->total_paid ?? 0;
            $totalQuote = $shoot->total_quote ?? 0;
            $paymentStatus = $this->calculatePaymentStatus($totalPaid, $totalQuote);
        }

        $needsWatermark = $isClient && !$shoot->bypass_paywall && $paymentStatus !== 'paid';
        
        Log::debug('getFiles watermark check', [
            'shoot_id' => $id,
            'user_role' => $user ? $user->role : 'none',
            'is_client' => $isClient,
            'bypass_paywall' => $shoot->bypass_paywall,
            'payment_status' => $paymentStatus,
            'needs_watermark' => $needsWatermark,
        ]);
        
        $baseUrl = rtrim(config('app.url'), '/');

        $resolvePreviewPath = function (?string $path) use ($baseUrl) {
            if (!$path) {
                return null;
            }
            if (preg_match('/^https?:\/\//i', $path)) {
                return $path;
            }

            $clean = ltrim($path, '/');
            if (Str::startsWith($clean, 'storage/')) {
                $clean = substr($clean, 8);
            }

            if (Storage::disk('public')->exists($clean)) {
                $encoded = implode('/', array_map('rawurlencode', explode('/', $clean)));
                $url = Storage::disk('public')->url($encoded);
                if (!preg_match('/^https?:\/\//i', $url)) {
                    $url = $baseUrl . '/' . ltrim($url, '/');
                }
                return $url;
            }

            try {
                return $this->dropboxService->getTemporaryLink($path);
            } catch (\Exception $e) {
                Log::warning('Failed to resolve preview path', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        };

        $queueWatermark = function (ShootFile $file) {
            try {
                \App\Jobs\GenerateWatermarkedImageJob::dispatch($file->fresh())->onQueue('watermarks');
            } catch (\Exception $e) {
                Log::warning('Failed to queue watermark job', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        $formattedFiles = $files->map(function ($file) use ($dropboxUrls, $shoot, $isClient, $needsWatermark, $resolvePreviewPath, $queueWatermark) {
            $url = null;
            $thumbUrl = null;
            $mediumUrl = null;
            $largeUrl = null;
            $originalUrl = null;

            if ($needsWatermark) {
                $wmWebPath = $file->watermarked_web_path;
                $wmThumbPath = $file->watermarked_thumbnail_path;
                
                $thumbUrl = $resolvePreviewPath($wmThumbPath ?? $file->watermarked_placeholder_path);
                $mediumUrl = $resolvePreviewPath(
                    $wmWebPath ?? $wmThumbPath ?? $file->watermarked_placeholder_path
                );
                $largeUrl = $mediumUrl;
                $url = $mediumUrl ?? $thumbUrl;
                $originalUrl = $url;

                Log::debug('getFiles watermark URL resolution', [
                    'file_id' => $file->id,
                    'wm_thumb_path' => $wmThumbPath,
                    'wm_web_path' => $wmWebPath,
                    'resolved_thumb_url' => $thumbUrl,
                    'resolved_medium_url' => $mediumUrl,
                ]);

                if (!$thumbUrl && !$mediumUrl && $file->shouldBeWatermarked()) {
                    $queueWatermark($file);
                }
            } else {
                if (isset($dropboxUrls[$file->id])) {
                    $originalUrl = $dropboxUrls[$file->id];
                } else {
                    $originalUrl = $this->resolveFileUrl($file, true);
                }

                $url = $originalUrl;
                $thumbUrl = $resolvePreviewPath($file->thumbnail_path ?? $file->placeholder_path);
                $mediumUrl = $resolvePreviewPath(
                    $file->web_path
                        ?? $file->thumbnail_path
                        ?? $file->placeholder_path
                );
                $largeUrl = $mediumUrl;

                // Fallback: if thumbnail is missing, use medium/original to keep previews visible
                if (!$thumbUrl) {
                    $thumbUrl = $mediumUrl ?? $originalUrl;
                }

                if (!$mediumUrl) {
                    $mediumUrl = $thumbUrl ?? $originalUrl;
                    $largeUrl = $mediumUrl;
                }
            }

            // Build response
            // IMPORTANT: When watermarks are required, do NOT expose non-watermarked paths
            // to prevent frontend from using them as fallbacks
            $fileData = [
                'id' => $file->id,
                'filename' => $file->filename ?? $file->stored_filename ?? 'unknown',
                'stored_filename' => $file->stored_filename,
                'url' => $url,
                'path' => $needsWatermark ? null : $file->path,
                'file_type' => $file->file_type ?? $file->mime_type,
                'fileType' => $file->file_type ?? $file->mime_type,
                'workflow_stage' => $file->workflow_stage,
                'workflowStage' => $file->workflow_stage,
                'is_extra' => ($file->media_type ?? 'raw') === 'extra',
                'isExtra' => ($file->media_type ?? 'raw') === 'extra',
                'is_cover' => $file->is_cover ?? false,
                'is_favorite' => $file->is_favorite ?? false,
                'file_size' => $file->file_size,
                'fileSize' => $file->file_size,
                'sort_order' => $file->sort_order ?? 0,
                'media_type' => $file->media_type,
                // Hide non-watermarked paths from unpaid clients
                'thumbnail_path' => $needsWatermark ? null : $file->thumbnail_path,
                'web_path' => $needsWatermark ? null : $file->web_path,
                'placeholder_path' => $needsWatermark ? null : $file->placeholder_path,
                'watermarked_storage_path' => $file->watermarked_storage_path,
                'watermarked_thumbnail_path' => $file->watermarked_thumbnail_path,
                'watermarked_web_path' => $file->watermarked_web_path,
                'watermarked_placeholder_path' => $file->watermarked_placeholder_path,
                'processed_at' => $file->processed_at,
            ];

            if ($thumbUrl) {
                $fileData['thumb_url'] = $thumbUrl;
                $fileData['thumb'] = $thumbUrl;
            }

            if ($mediumUrl) {
                $fileData['medium_url'] = $mediumUrl;
                $fileData['medium'] = $mediumUrl;
            }

            if ($largeUrl) {
                $fileData['large_url'] = $largeUrl;
                $fileData['large'] = $largeUrl;
            }

            if ($originalUrl) {
                $fileData['original_url'] = $originalUrl;
                $fileData['original'] = $originalUrl;
            }
            
            // Add dimensions if available in metadata
            if ($file->metadata && is_array($file->metadata)) {
                if (isset($file->metadata['width'])) {
                    $fileData['width'] = $file->metadata['width'];
                }
                if (isset($file->metadata['height'])) {
                    $fileData['height'] = $file->metadata['height'];
                }
                // Add captured_at from EXIF if available
                if (isset($file->metadata['captured_at'])) {
                    $fileData['captured_at'] = $file->metadata['captured_at'];
                }
            }
            
            // Add timestamps
            $fileData['created_at'] = $file->created_at ? $file->created_at->toIso8601String() : null;
            $fileData['uploaded_at'] = $file->uploaded_at ? $file->uploaded_at->toIso8601String() : ($file->created_at ? $file->created_at->toIso8601String() : null);
            
            return $fileData;
        })->values()->all();
        
        // Cache the formatted files for 30 seconds
        Cache::put($cacheKey, $formattedFiles, now()->addSeconds(30));
        
        return response()->json([
            'data' => $formattedFiles,
            'count' => count($formattedFiles),
        ]);
    }

    /**
     * List media files by type
     */
    public function listMedia($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        $type = $request->query('type', 'raw'); // raw, edited, extra

        $files = $this->dropboxService->listShootFiles($shoot, $type);

        return response()->json([
            'data' => $files,
            'counts' => [
                'raw_photo_count' => $shoot->raw_photo_count,
                'edited_photo_count' => $shoot->edited_photo_count,
                'extra_photo_count' => $shoot->extra_photo_count,
                'expected_raw_count' => $shoot->expected_raw_count,
                'expected_final_count' => $shoot->expected_final_count,
                'raw_missing_count' => $shoot->raw_missing_count,
                'edited_missing_count' => $shoot->edited_missing_count,
                'bracket_mode' => $shoot->bracket_mode,
            ]
        ]);
    }

    /**
     * Download media files as ZIP
     */
    public function downloadMediaZip($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        $type = $request->query('type', 'raw'); // raw or edited

        $folderPath = $shoot->getDropboxFolderForType($type);

        if (!$folderPath) {
            return response()->json(['error' => 'No folder found for type: ' . $type], 404);
        }

        // Try to get Dropbox shared link first
        $zipLink = $this->dropboxService->getDropboxZipLink($folderPath);

        if ($zipLink) {
            return response()->json([
                'type' => 'redirect',
                'url' => $zipLink
            ]);
        }

        // Fallback: generate ZIP on-the-fly
        try {
            $zipPath = $this->dropboxService->generateZipOnFly($shoot, $type);
            
            return response()->download($zipPath, "shoot-{$shoot->id}-{$type}.zip")->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Failed to generate ZIP', ['error' => $e->getMessage(), 'shoot_id' => $id]);
            return response()->json(['error' => 'Failed to generate ZIP file'], 500);
        }
    }

    /**
     * Upload extra RAW photos
     */
    public function uploadExtra($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        $user = auth()->user();

        $validated = $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|file|mimes:jpg,jpeg,png,raw,cr2,nef,arw,dng|max:51200',
        ]);

        $uploadedFiles = [];
        foreach ($request->file('files') as $file) {
            try {
                $shootFile = $this->dropboxService->uploadToExtra($shoot, $file, $user->id);
                $uploadedFiles[] = $this->transformFile($shootFile);
            } catch (\Exception $e) {
                Log::error('Failed to upload extra file', [
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Update photo counts
        $shoot->updatePhotoCounts();

        return response()->json([
            'message' => 'Files uploaded successfully',
            'data' => $uploadedFiles,
            'extra_photo_count' => $shoot->extra_photo_count
        ]);
    }

    /**
     * Create a media album for a shoot
     * POST /api/shoots/{shoot}/albums
     */
    public function createAlbum(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Only photographer assigned to shoot, admin, or super admin can create albums
        if ($user->role === 'photographer' && $shoot->photographer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($user->role, ['admin', 'superadmin', 'superadmin', 'photographer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'source' => 'required|in:dropbox,local',
            'folder_path' => 'nullable|string|max:500',
            'photographer_id' => 'nullable|exists:users,id',
        ]);

        $album = ShootMediaAlbum::create([
            'shoot_id' => $shoot->id,
            'photographer_id' => $validated['photographer_id'] ?? ($user->role === 'photographer' ? $user->id : null),
            'source' => $validated['source'],
            'folder_path' => $validated['folder_path'] ?? null,
            'is_watermarked' => false,
        ]);

        // Log activity
        $this->activityLogger->log(
            $shoot,
            'album_created',
            [
                'album_id' => $album->id,
                'source' => $album->source,
            ],
            $user
        );

        return response()->json([
            'message' => 'Album created successfully',
            'data' => $album->load('photographer'),
        ], 201);
    }

    /**
     * List albums for a shoot
     * GET /api/shoots/{shoot}/albums
     */
    public function listAlbums(Request $request, Shoot $shoot)
    {
        $albums = $shoot->mediaAlbums()->with(['photographer', 'files'])->get();

        return response()->json([
            'data' => $albums->map(function ($album) {
                return [
                    'id' => $album->id,
                    'source' => $album->source,
                    'folder_path' => $album->folder_path,
                    'cover_image_path' => $album->cover_image_path,
                    'is_watermarked' => $album->is_watermarked,
                    'photographer' => $album->photographer ? [
                        'id' => $album->photographer->id,
                        'name' => $album->photographer->name,
                    ] : null,
                    'file_count' => $album->files->count(),
                    'created_at' => $album->created_at->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Upload media files to a shoot (new album-based endpoint)
     * POST /api/shoots/{shoot}/media
     */
    public function uploadMedia(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        // Check authorization
        if ($user->role === 'photographer' && $shoot->photographer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($user->role, ['admin', 'superadmin', 'superadmin', 'photographer', 'editor'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:1048576|mimes:jpeg,jpg,png,gif,mp4,mov,avi,raw,cr2,nef,arw,tiff,bmp,heic,heif,zip',
            'album_id' => 'nullable|exists:shoot_media_albums,id',
            'type' => 'required|in:raw,edited,video,iguide,other',
            'photographer_note' => 'nullable|string|max:1000',
        ]);

        // Get or create album
        $album = $validated['album_id'] 
            ? ShootMediaAlbum::findOrFail($validated['album_id'])
            : $this->getOrCreateAlbumForType($shoot, $validated['type'], $user);

        // Store files temporarily and dispatch jobs
        $uploadedFiles = [];
        $errors = [];

        foreach ($request->file('files') as $file) {
            try {
                // Store file temporarily
                $tempPath = $file->store('temp/uploads', 'local');
                $fullTempPath = storage_path('app/' . $tempPath);

                // Dispatch upload job
                dispatch(new UploadShootMediaToDropboxJob(
                    $shoot,
                    $album,
                    $tempPath,
                    $file->getClientOriginalName(),
                    $validated['type'],
                    $user->id,
                    $validated['photographer_note'] ?? null
                ));

                $uploadedFiles[] = [
                    'filename' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'type' => $validated['type'],
                    'status' => 'queued',
                ];
            } catch (\Exception $e) {
                Log::error('Failed to queue media upload', [
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Log activity
        $this->activityLogger->log(
            $shoot,
            'media_upload_initiated',
            [
                'album_id' => $album->id,
                'file_count' => count($uploadedFiles),
                'type' => $validated['type'],
            ],
            $user
        );

        return response()->json([
            'message' => count($uploadedFiles) . ' file(s) queued for upload',
            'data' => [
                'uploaded' => $uploadedFiles,
                'errors' => $errors,
                'album_id' => $album->id,
            ],
        ], 202); // 202 Accepted for async processing
    }

    /**
     * Get or create album for a specific media type
     */
    protected function getOrCreateAlbumForType(Shoot $shoot, string $type, User $user): ShootMediaAlbum
    {
        $photographerId = $user->role === 'photographer' ? $user->id : $shoot->photographer_id;

        // Try to find existing album for this type and photographer
        $album = $shoot->mediaAlbums()
            ->where('photographer_id', $photographerId)
            ->whereHas('files', function ($query) use ($type) {
                $query->where('media_type', $type);
            })
            ->first();

        if ($album) {
            return $album;
        }

        // Create new album
        $folderPath = "/shoots/{$shoot->id}/{$type}/{$photographerId}/";

        return ShootMediaAlbum::create([
            'shoot_id' => $shoot->id,
            'photographer_id' => $photographerId,
            'source' => 'dropbox',
            'folder_path' => $folderPath,
            'is_watermarked' => false,
        ]);
    }

    /**
     * Get notes for a shoot (with role-based filtering)
     * GET /api/shoots/{shoot}/notes
     */
    public function getNotes(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $role = strtolower($user->role ?? '');

        // Get all notes and filter by visibility
        $notes = $shoot->notes()
            ->with('author:id,name,email')
            ->get()
            ->filter(function ($note) use ($role) {
                return $note->isVisibleToRole($role);
            })
            ->values();

        return response()->json([
            'data' => $notes->map(function ($note) {
                return [
                    'id' => $note->id,
                    'type' => $note->type,
                    'visibility' => $note->visibility,
                    'content' => $note->content,
                    'author' => [
                        'id' => $note->author->id,
                        'name' => $note->author->name,
                    ],
                    'created_at' => $note->created_at->toIso8601String(),
                    'updated_at' => $note->updated_at->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Get activity log for a shoot
     * GET /api/shoots/{shoot}/activity-log
     */
    public function getActivityLog(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $role = strtolower($user->role ?? '');

        // Get activity logs for this shoot
        $activityLogs = $shoot->activityLogs()
            ->with('user:id,name,role')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $activityLogs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'metadata' => $log->metadata ?? [],
                    'created_at' => $log->created_at->toIso8601String(),
                    'timestamp' => $log->created_at->toIso8601String(),
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'role' => $log->user->role,
                    ] : null,
                ];
            }),
        ]);
    }

    /**
     * Create a note for a shoot
     * POST /api/shoots/{shoot}/notes
     */
    public function storeNote(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $role = strtolower($user->role ?? '');

        $validated = $request->validate([
            'type' => 'required|in:shoot,company,photographer,editing',
            'visibility' => 'required|in:internal,photographer_only,client_visible',
            'content' => 'required|string|max:5000',
        ]);

        // Role-based restrictions
        $allowedTypes = match($role) {
            'admin', 'superadmin', 'superadmin' => ['shoot', 'company', 'photographer', 'editing'],
            'client' => ['shoot'],
            'photographer' => ['photographer', 'shoot'],
            'editor' => ['editing', 'shoot'],
            default => [],
        };

        if (!in_array($validated['type'], $allowedTypes)) {
            return response()->json([
                'message' => 'You are not authorized to create notes of this type',
            ], 403);
        }

        // Clients can only create client_visible notes
        if ($role === 'client' && $validated['visibility'] !== 'client_visible') {
            return response()->json([
                'message' => 'Clients can only create client-visible notes',
            ], 403);
        }

        // Create note
        $note = $shoot->notes()->create([
            'author_id' => $user->id,
            'type' => $validated['type'],
            'visibility' => $validated['visibility'],
            'content' => $validated['content'],
        ]);

        // Log activity
        $this->activityLogger->log(
            $shoot,
            'note_added',
            [
                'note_id' => $note->id,
                'type' => $note->type,
                'visibility' => $note->visibility,
            ],
            $user
        );

        return response()->json([
            'message' => 'Note created successfully',
            'data' => [
                'id' => $note->id,
                'type' => $note->type,
                'visibility' => $note->visibility,
                'content' => $note->content,
                'author' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
                'created_at' => $note->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Archive shoot manually (admin only)
     */
    public function archiveShoot($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        $user = auth()->user();

        // Check if user is admin
        if (!in_array($user->role, ['admin', 'superadmin', 'superadmin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $success = $this->dropboxService->archiveShoot($shoot, $user->id);

        if ($success) {
            return response()->json([
                'message' => 'Shoot archived successfully',
                'archive_folder' => $shoot->dropbox_archive_folder
            ]);
        } else {
            return response()->json(['error' => 'Failed to archive shoot'], 500);
        }
    }

    /**
     * Get or create invoice for a shoot.
     * GET /api/shoots/{shoot}/invoice
     */
    public function getOrCreateInvoice(Shoot $shoot)
    {
        try {
            // Try to find existing invoice
            $invoice = Invoice::where('shoot_id', $shoot->id)
                ->with(['shoot.client', 'shoot.photographer', 'shoot.services', 'items', 'client', 'photographer'])
                ->first();

            // If no invoice exists, generate one
            if (!$invoice) {
                $invoice = $this->invoiceService->generateForShoot($shoot);
            }

            if (!$invoice) {
                return response()->json(['message' => 'Failed to generate invoice'], 500);
            }

            return response()->json([
                'data' => $invoice->load(['shoot.client', 'shoot.photographer', 'shoot.services', 'items', 'client', 'photographer'])
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting/creating invoice for shoot', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Failed to get or create invoice'], 500);
        }
    }

    /**
     * Mark shoot as paid (Admin and Super Admin)
     * POST /api/shoots/{shoot}/mark-paid
     */
    public function markAsPaid(Request $request, Shoot $shoot)
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'payment_type' => 'nullable|string|in:manual,square,check,cash,bank_transfer',
        ]);

        try {
            $amount = $validated['amount'] ?? $shoot->total_quote ?? 0;
            $paymentType = $validated['payment_type'] ?? 'manual';

            // If amount is 0 or null, use the outstanding balance
            if ($amount <= 0) {
                $currentPaid = $shoot->payments()
                    ->where('status', Payment::STATUS_COMPLETED)
                    ->sum('amount') ?? 0;
                $amount = ($shoot->total_quote ?? 0) - $currentPaid;
            }

            // Don't create payment if amount is 0 or negative
            if ($amount <= 0) {
                return response()->json([
                    'message' => 'Shoot is already fully paid',
                    'data' => [
                        'total_paid' => $shoot->total_quote,
                        'payment_status' => 'paid',
                    ],
                ]);
            }

            // Create payment record
            $payment = Payment::create([
                'shoot_id' => $shoot->id,
                'amount' => $amount,
                'currency' => 'USD',
                'status' => Payment::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);

            // Calculate new total paid
            $totalPaid = $shoot->payments()
                ->where('status', Payment::STATUS_COMPLETED)
                ->sum('amount');

            // Update shoot payment status
            $oldPaymentStatus = $shoot->payment_status;
            $newPaymentStatus = $this->calculatePaymentStatus($totalPaid, $shoot->total_quote ?? 0);
            $shoot->payment_status = $newPaymentStatus;
            $shoot->save();

            // Log activity (wrapped in try-catch to not fail the main operation)
            try {
                if ($this->activityLogger) {
                    $this->activityLogger->log(
                        $shoot,
                        'payment_marked_paid',
                        [
                            'payment_id' => $payment->id,
                            'amount' => $amount,
                            'payment_method' => $paymentType,
                            'total_paid' => $totalPaid,
                            'total_quote' => $shoot->total_quote,
                            'old_status' => $oldPaymentStatus,
                            'new_status' => $newPaymentStatus,
                            'marked_by' => auth()->user()->name ?? 'Unknown',
                        ],
                        auth()->user()
                    );
                }
            } catch (\Exception $logError) {
                Log::warning('Failed to log activity for payment', [
                    'shoot_id' => $shoot->id,
                    'error' => $logError->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Shoot marked as paid successfully',
                'data' => [
                    'payment_id' => $payment->id,
                    'total_paid' => $totalPaid,
                    'payment_status' => $newPaymentStatus,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking shoot as paid', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Failed to mark shoot as paid',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate payment status based on total paid vs total quote
     */
    protected function calculatePaymentStatus(float $totalPaid, float $totalQuote): string
    {
        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        if ($totalPaid >= $totalQuote) {
            return 'paid';
        }

        return 'partial';
    }
    
    /**
     * Parse PHP size string (e.g., "50M") to bytes
     */
    protected function parseSize(string $size): int
    {
        $size = trim($size);
        $last = strtolower($size[strlen($size) - 1]);
        $value = (int) $size;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Download raw files for editor - logs activity and notifies admin
     * GET /api/shoots/{shoot}/editor-download-raw
     */
    public function editorDownloadRaw(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        
        // Only editors can use this endpoint
        if ($user->role !== 'editor') {
            return response()->json(['error' => 'Only editors can download raw files via this endpoint'], 403);
        }

        // Get raw files from database (optionally filtered by selected IDs)
        $fileIdsParam = $request->query('file_ids', []);
        if (is_string($fileIdsParam)) {
            $fileIdsParam = array_filter(explode(',', $fileIdsParam));
        }
        $filesQuery = $shoot->files()->where('workflow_stage', 'todo');
        if (!empty($fileIdsParam)) {
            $filesQuery->whereIn('id', $fileIdsParam);
        }
        $files = $filesQuery->get();
        $fileCount = $files->count();
        
        // Check if Dropbox is enabled and folder path exists
        $dropboxEnabled = $this->dropboxService->isEnabled();
        $folderPath = $dropboxEnabled ? $shoot->getDropboxFolderForType('raw') : null;
        
        if (!empty($fileIdsParam) && $fileCount === 0) {
            return response()->json(['error' => 'No raw files found for selected IDs'], 404);
        }

        if ($fileCount === 0 && !$folderPath) {
            return response()->json(['error' => 'No raw files found to download'], 404);
        }

        // Log activity
        $this->activityLogger->log(
            $shoot,
            'raw_downloaded_by_editor',
            [
                'editor_id' => $user->id,
                'editor_name' => $user->name,
                'file_count' => $fileCount > 0 ? $fileCount : 'all',
            ],
            $user
        );

        // Send notification to admins
        $this->notifyAdminsOfEditorDownload($shoot, $user, $fileCount > 0 ? $fileCount : 0);

        // Try Dropbox link first if folder exists
        if ($dropboxEnabled && $folderPath && empty($fileIdsParam)) {
            try {
                $zipLink = $this->dropboxService->getDropboxZipLink($folderPath);
                if ($zipLink) {
                    return response()->json([
                        'type' => 'redirect',
                        'url' => $zipLink,
                        'file_count' => $fileCount,
                        'message' => 'Download started. Switch to Edited tab to upload your edits.',
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get Dropbox ZIP link, trying fallback', ['error' => $e->getMessage()]);
            }
        }

        // Fallback: generate ZIP from local files or download from Dropbox
        try {
            if ($files->count() > 0) {
                // Try to generate ZIP with Dropbox fallback for each file (if enabled)
                $zipPath = $this->generateFilesZipWithDropboxFallback($shoot, $files);
                if ($zipPath && file_exists($zipPath)) {
                    return response()->download($zipPath, "shoot-{$shoot->id}-raw-files.zip", [
                        'X-File-Count' => $fileCount,
                    ])->deleteFileAfterSend(true);
                }
            }
            
            // Last resort: Try Dropbox on-the-fly generation if Dropbox is enabled
            if ($dropboxEnabled && $folderPath) {
                try {
                    $zipPath = $this->dropboxService->generateZipOnFly($shoot, 'raw');
                    if ($zipPath && file_exists($zipPath)) {
                        return response()->download($zipPath, "shoot-{$shoot->id}-raw-files.zip", [
                            'X-File-Count' => $fileCount,
                        ])->deleteFileAfterSend(true);
                    }
                } catch (\Exception $dropboxError) {
                    Log::warning('Dropbox generateZipOnFly failed', ['error' => $dropboxError->getMessage()]);
                }
            }
            
            return response()->json([
                'error' => 'No downloadable files available. Files may not be stored locally or Dropbox access may be unavailable.',
                'file_count' => $fileCount,
                'has_dropbox_folder' => $dropboxEnabled && !empty($folderPath),
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to generate ZIP for editor download', ['error' => $e->getMessage(), 'shoot_id' => $shoot->id]);
            return response()->json(['error' => 'Failed to generate ZIP file: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Generate ZIP from local files stored on disk
     */
    protected function generateLocalFilesZip(Shoot $shoot, $files)
    {
        $zipPath = storage_path("app/temp/shoot-{$shoot->id}-raw-" . time() . ".zip");
        
        // Ensure temp directory exists
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Failed to create ZIP file");
        }

        $addedFiles = 0;
        foreach ($files as $file) {
            $localPath = $this->findLocalFilePath($file);
            
            if ($localPath && file_exists($localPath)) {
                $filename = $file->original_name ?? $file->filename ?? basename($localPath);
                $zip->addFile($localPath, $filename);
                $addedFiles++;
            }
        }

        $zip->close();
        
        if ($addedFiles === 0) {
            @unlink($zipPath);
            return null;
        }
        
        return $zipPath;
    }

    /**
     * Find the local file path for a ShootFile
     */
    protected function findLocalFilePath($file): ?string
    {
        $pathsToTry = [];
        
        // Priority order for finding file paths
        foreach ([
            'storage_path' => $file->storage_path ?? null,
            'path' => $file->path ?? null,
        ] as $label => $candidate) {
            if (!$candidate) {
                continue;
            }

            if (file_exists($candidate)) {
                return $candidate;
            }

            $normalizedCandidate = ltrim($candidate, '/');
            if (Str::startsWith($normalizedCandidate, 'storage/')) {
                $normalizedCandidate = Str::after($normalizedCandidate, 'storage/');
            }

            if (Storage::disk('public')->exists($normalizedCandidate)) {
                return Storage::disk('public')->path($normalizedCandidate);
            }

            if (Storage::disk('local')->exists($normalizedCandidate)) {
                return Storage::disk('local')->path($normalizedCandidate);
            }

            $pathsToTry[] = storage_path('app/' . ltrim($candidate, '/'));
            $pathsToTry[] = storage_path('app/public/' . ltrim($candidate, '/'));
            $pathsToTry[] = public_path('storage/' . ltrim($candidate, '/'));
        }
        
        // Try shoots/{shoot_id}/todo/{filename} pattern
        if (!empty($file->filename) && !empty($file->shoot_id)) {
            $pathsToTry[] = storage_path("app/public/shoots/{$file->shoot_id}/todo/{$file->filename}");
            $pathsToTry[] = storage_path("app/shoots/{$file->shoot_id}/todo/{$file->filename}");
        }
        
        if (!empty($file->stored_filename) && !empty($file->shoot_id)) {
            $pathsToTry[] = storage_path("app/public/shoots/{$file->shoot_id}/todo/{$file->stored_filename}");
            $pathsToTry[] = storage_path("app/shoots/{$file->shoot_id}/todo/{$file->stored_filename}");
        }
        
        foreach ($pathsToTry as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        return null;
    }

    /**
     * Download file from Dropbox path and return temp file path
     */
    protected function downloadFromDropbox($file): ?string
    {
        if (!$this->dropboxService->isEnabled()) {
            return null;
        }
        if (empty($file->dropbox_path)) {
            return null;
        }
        
        try {
            $tempPath = storage_path("app/temp/dropbox-download-" . uniqid() . "-" . ($file->filename ?? 'file'));
            
            // Ensure temp directory exists
            if (!file_exists(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }
            
            // Download from Dropbox
            $contents = $this->dropboxService->downloadFile($file->dropbox_path);
            if ($contents) {
                file_put_contents($tempPath, $contents);
                return $tempPath;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to download file from Dropbox', [
                'dropbox_path' => $file->dropbox_path,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Generate ZIP from files, downloading from Dropbox if needed
     */
    protected function generateFilesZipWithDropboxFallback(Shoot $shoot, $files)
    {
        $zipPath = storage_path("app/temp/shoot-{$shoot->id}-raw-" . time() . ".zip");
        $tempFiles = [];
        $dropboxEnabled = $this->dropboxService->isEnabled();
        
        // Ensure temp directory exists
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Failed to create ZIP file");
        }

        $addedFiles = 0;
        foreach ($files as $file) {
            $localPath = $this->findLocalFilePath($file);
            $isTempFile = false;
            
            // If no local file, try downloading from Dropbox (only if enabled)
            if ($dropboxEnabled && !$localPath && !empty($file->dropbox_path)) {
                $localPath = $this->downloadFromDropbox($file);
                $isTempFile = true;
                if ($localPath) {
                    $tempFiles[] = $localPath;
                }
            }
            
            if ($localPath && file_exists($localPath)) {
                $filename = $file->original_name ?? $file->filename ?? basename($localPath);
                $zip->addFile($localPath, $filename);
                $addedFiles++;
            }
        }

        $zip->close();
        
        // Clean up temp files after ZIP is created
        foreach ($tempFiles as $tempFile) {
            @unlink($tempFile);
        }
        
        if ($addedFiles === 0) {
            @unlink($zipPath);
            return null;
        }
        
        return $zipPath;
    }

    /**
     * Generate shareable ZIP link for editor
     * POST /api/shoots/{shoot}/generate-share-link
     */
    public function generateShareLink(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        
        // Only editors can generate share links
        if ($user->role !== 'editor') {
            return response()->json(['error' => 'Only editors can generate share links'], 403);
        }

        $expiresInHours = $request->input('expires_in_hours', 72); // Default 72 hours
        
        $fileIdsParam = $request->input('file_ids', []);
        if (is_string($fileIdsParam)) {
            $fileIdsParam = array_filter(explode(',', $fileIdsParam));
        }

        $filesQuery = $shoot->files()->where('workflow_stage', 'todo');
        if (!empty($fileIdsParam)) {
            $filesQuery->whereIn('id', $fileIdsParam);
        }
        $files = $filesQuery->get();
        $fileCount = $files->count();

        if (!empty($fileIdsParam) && $fileCount === 0) {
            return response()->json(['error' => 'No raw files found for selected IDs'], 404);
        }

        $dropboxEnabled = $this->dropboxService->isEnabled();
        $folderPath = $dropboxEnabled ? $shoot->getDropboxFolderForType('raw') : null;
        $shareLink = null;
        $shareLinkSourcePath = null;

        try {
            if ($dropboxEnabled && empty($fileIdsParam) && $folderPath) {
                try {
                    // Use Dropbox folder link for sharing all files
                    $shareLink = $this->dropboxService->createSharedLink($folderPath, $expiresInHours);
                    $shareLinkSourcePath = $folderPath;
                } catch (\Exception $dropboxError) {
                    Log::warning('Failed to create Dropbox share link, falling back to local ZIP', [
                        'error' => $dropboxError->getMessage(),
                        'shoot_id' => $shoot->id,
                    ]);
                }
            }

            if (!$shareLink) {
                // Generate ZIP and host locally for selected files (or when Dropbox is unavailable)
                if ($files->isEmpty()) {
                    return response()->json(['error' => 'No raw files found to share'], 404);
                }
                $zipPath = $this->generateFilesZipWithDropboxFallback($shoot, $files);
                if (!$zipPath || !file_exists($zipPath)) {
                    return response()->json(['error' => 'Failed to generate shareable ZIP file'], 500);
                }
                $publicDir = "share-links/{$shoot->id}";
                Storage::disk('public')->makeDirectory($publicDir);
                $zipFilename = 'share-link-' . Str::uuid()->toString() . '.zip';
                $publicPath = $publicDir . '/' . $zipFilename;

                $stream = fopen($zipPath, 'r');
                if ($stream === false) {
                    return response()->json(['error' => 'Failed to read shareable ZIP file'], 500);
                }

                $stored = Storage::disk('public')->put($publicPath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                @unlink($zipPath);

                if (!$stored) {
                    return response()->json(['error' => 'Failed to store shareable ZIP file'], 500);
                }

                $shareLink = Storage::disk('public')->url($publicPath);
                $shareLinkSourcePath = $publicPath;
            }

            if (!$shareLink) {
                return response()->json([
                    'error' => 'Could not create share link. Dropbox may be unavailable or the ZIP could not be generated.',
                ], 500);
            }

            // Store share link in database for tracking (skip if table doesn't exist yet)
            try {
                $shareLinkRecord = \App\Models\ShootShareLink::create([
                    'shoot_id' => $shoot->id,
                    'created_by' => $user->id,
                    'share_url' => $shareLink,
                    'dropbox_path' => $shareLinkSourcePath,
                    'download_count' => 0,
                    'expires_at' => now()->addHours($expiresInHours),
                ]);
                $shareLinkId = $shareLinkRecord->id;
                $expiresAt = $shareLinkRecord->expires_at->toIso8601String();
            } catch (\Exception $dbError) {
                Log::warning('Could not save share link to database', ['error' => $dbError->getMessage()]);
                $shareLinkId = null;
                $expiresAt = now()->addHours($expiresInHours)->toIso8601String();
            }

            // Log activity
            $this->activityLogger->log(
                $shoot,
                'share_link_generated',
                [
                    'editor_id' => $user->id,
                    'editor_name' => $user->name,
                    'file_count' => $fileCount,
                    'expires_in_hours' => $expiresInHours,
                ],
                $user
            );

            return response()->json([
                'share_link' => $shareLink,
                'share_link_id' => $shareLinkId,
                'file_count' => $fileCount,
                'expires_in_hours' => $expiresInHours,
                'expires_at' => $expiresAt,
                'message' => 'Share link generated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate share link', ['error' => $e->getMessage(), 'shoot_id' => $shoot->id]);
            return response()->json(['error' => 'Failed to generate share link: ' . $e->getMessage()], 500);
        }
    }

    /**
     * List share links for a shoot
     * GET /api/shoots/{shoot}/share-links
     */
    public function listShareLinks(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        
        // Editors can only see their own links, admins can see all
        $query = $shoot->shareLinks()->with('creator:id,name');
        
        if ($user->role === 'editor') {
            $query->where('created_by', $user->id);
        }
        
        $links = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json([
            'data' => $links->map(function ($link) {
                return [
                    'id' => $link->id,
                    'share_url' => $link->share_url,
                    'download_count' => $link->download_count,
                    'created_at' => $link->created_at->toIso8601String(),
                    'expires_at' => $link->expires_at?->toIso8601String(),
                    'is_expired' => $link->isExpired(),
                    'is_revoked' => $link->is_revoked,
                    'is_active' => $link->isActive(),
                    'created_by' => $link->creator ? [
                        'id' => $link->creator->id,
                        'name' => $link->creator->name,
                    ] : null,
                ];
            }),
        ]);
    }

    /**
     * Revoke a share link
     * POST /api/shoots/{shoot}/share-links/{linkId}/revoke
     */
    public function revokeShareLink(Request $request, Shoot $shoot, $linkId)
    {
        $user = $request->user();
        
        $link = $shoot->shareLinks()->findOrFail($linkId);
        
        // Editors can only revoke their own links, admins can revoke any
        if ($user->role === 'editor' && $link->created_by !== $user->id) {
            return response()->json(['error' => 'You can only revoke your own share links'], 403);
        }
        
        if ($link->is_revoked) {
            return response()->json(['error' => 'Link is already revoked'], 400);
        }
        
        $link->revoke($user->id);
        
        // Log activity
        $this->activityLogger->log(
            $shoot,
            'share_link_revoked',
            [
                'editor_id' => $user->id,
                'editor_name' => $user->name,
                'share_link_id' => $link->id,
            ],
            $user
        );
        
        return response()->json([
            'message' => 'Share link revoked successfully',
            'data' => [
                'id' => $link->id,
                'is_revoked' => true,
                'revoked_at' => $link->revoked_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Notify admins when editor downloads raw files
     */
    protected function notifyAdminsOfEditorDownload(Shoot $shoot, $editor, int $fileCount): void
    {
        try {
            if (!class_exists('App\\Models\\Notification') || !Schema::hasTable('notifications')) {
                return;
            }

            // Get all admin users
            $admins = \App\Models\User::whereIn('role', ['admin', 'superadmin'])->get();
            
            foreach ($admins as $admin) {
                // Create notification
                \App\Models\Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'editor_download',
                    'title' => 'Editor Downloaded Raw Files',
                    'message' => "{$editor->name} downloaded {$fileCount} raw files from shoot #{$shoot->id} ({$shoot->address})",
                    'data' => [
                        'shoot_id' => $shoot->id,
                        'editor_id' => $editor->id,
                        'editor_name' => $editor->name,
                        'file_count' => $fileCount,
                    ],
                    'read' => false,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to notify admins of editor download: ' . $e->getMessage());
        }
    }

    /**
     * Generate ZIP for selected files
     */
    protected function generateSelectedFilesZip(Shoot $shoot, $files): string
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $zipPath = $tempDir . '/shoot-' . $shoot->id . '-selected-' . time() . '.zip';
        $zip = new \ZipArchive();
        
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception('Cannot create ZIP file');
        }

        foreach ($files as $file) {
            $filePath = $file->dropbox_path ?? $file->local_path;
            
            if ($filePath) {
                // Try to get file content from Dropbox or local storage
                try {
                    if ($file->dropbox_path) {
                        $content = $this->dropboxService->downloadFileContent($file->dropbox_path);
                    } else {
                        $content = \Storage::disk('public')->get($file->local_path);
                    }
                    
                    if ($content) {
                        $zip->addFromString($file->filename ?? basename($filePath), $content);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to add file to ZIP: ' . $e->getMessage(), ['file_id' => $file->id]);
                }
            }
        }

        $zip->close();
        
        return $zipPath;
    }
}
