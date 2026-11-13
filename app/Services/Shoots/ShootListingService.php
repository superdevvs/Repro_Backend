<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Schedule\ScheduleDateScopeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ShootListingService
{
    protected const CACHE_REGISTRY_KEY = 'shoots_index_cache_keys';

    protected const TAB_STATUS_MAP = [
        'scheduled' => [
            Shoot::STATUS_SCHEDULED,
            Shoot::STATUS_REQUESTED,
        ],
        'completed' => [
            Shoot::STATUS_ACTIVE,
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

    public function index(Request $request, ?User $user, callable $transformShoot): JsonResponse
    {
        $authorization = app(ShootAuthorizationSupport::class);
        if (! $user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }
        if (! $authorization->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'editor', 'photographer', 'client', 'salesRep'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $deliveredActivityWindow = ShootDeliveredActivityWindow::fromRequest($request);

        // Discovery is a separate property-only projection, never permission to
        // request another client's operational shoot, media, or payment data.
        if ($authorization->isClientUser($user)
            && filter_var($request->query('private_listing'), FILTER_VALIDATE_BOOLEAN)
            && strtolower((string) $request->query('listing_scope')) === 'all') {
            if ($deliveredActivityWindow !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'delivered_activity_from' => 'Delivery activity windows are only available for operational shoot listings.',
                ]);
            }
            return $this->privateListingDiscovery($request);
        }

        $booleanFilter = static function ($attribute, $value, $fail) {
            if (! in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                $fail("The {$attribute} field must be true or false.");
            }
        };
        $statusValues = app(ShootStatusFilter::class)->values();
        $request->validate([
            'scheduled_local_date' => ['sometimes', 'required',
                ...($request->query('scheduled_local_date') === 'today' ? ['in:today'] : ['date_format:Y-m-d']),
            ],
            'statuses' => ['sometimes', 'array', 'max:'.count($statusValues)],
            'statuses.*' => ['string', Rule::in($statusValues)],
            'unassigned_only' => ['sometimes', $booleanFilter],
            'flagged_only' => ['sometimes', $booleanFilter],
        ]);
        $filterCapabilities = $this->filterCapabilities($user);
        abort_if($request->boolean('unassigned_only') && ! $filterCapabilities['assignments'], 403, 'Assignment filters are not available for this role.');
        abort_if($request->boolean('flagged_only') && ! $filterCapabilities['priority'], 403, 'Priority filters are not available for this role.');
        $calendarZone = \App\Support\Timezone::canonical((string) config('schedule.calendar_timezone', 'America/New_York'));
        if (!in_array($calendarZone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            $calendarZone = 'America/New_York';
        }
        $calendar = ['timezone' => $calendarZone, 'today' => now($calendarZone)->toDateString()];
        $localDate = $request->query('scheduled_local_date') === 'today'
            ? $calendar['today'] : $request->query('scheduled_local_date');

        try {
            $tab = strtolower($request->query('tab', 'scheduled'));
            $isPrivateListingRequest = $request->query('private_listing') !== null;
            $searchTerm = trim((string) $request->query('search', ''));

            if (!$request->has('tab') && $request->query('private_listing') !== null) {
                $tab = 'delivered';
            } elseif (! $request->has('tab') && $authorization->hasRole($user, ['editor'])) {
                $tab = 'completed';
            } elseif (! $request->has('tab') && $searchTerm !== '') {
                // GlobalCommandBar / cross-status search: omitting tab with a
                // non-empty search must not default to scheduled-only.
                $tab = 'all';
            }

            $page = (int) $request->query('page', 1);
            $maxPerPage = $isPrivateListingRequest ? 200 : 50;
            $perPage = min($maxPerPage, max(9, (int) $request->query('per_page', 25)));
            $userId = $user ? $user->id : 'guest';
            $userRole = $user ? $user->role : 'guest';

            $isImpersonating = $request->attributes->get('is_impersonating', false);
            $impersonationSuffix = $isImpersonating ? '_imp' : '';

            $cacheKey = 'shoots_index_access_v3_' . $userId . '_' . $userRole . $impersonationSuffix . '_' . $tab . '_' . $page . '_' . $perPage
                . '_calendar_' . $calendarZone . '_' . $calendar['today'];

            // Every param that changes the result set has to be in the cache key.
            // `bracket`, `missing` and `limit` were applied to the query but absent
            // here, so two different filters shared one cache entry for its 30
            // second life: asking for bracket=3 straight after bracket=5 returned
            // the 5x list.
            $filterParams = $request->only([
                'client_id', 'photographer_id', 'sales_rep_id', 'payment_status', 'services', 'search', 'address', 'shoot_id',
                'date_range', 'scheduled_start', 'scheduled_end', 'scheduled_local_date',
                'completed_start', 'completed_end', 'custom_start', 'custom_end',
                'delivered_activity_from', 'delivered_activity_to',
                'date_from', 'date_to', 'private_listing', 'listing_scope', 'include_hidden',
                'bracket', 'missing', 'limit', 'scheduled_status', 'hold_status', 'sort', 'dashboard_open', 'view', 'include_filters',
                'statuses', 'unassigned_only', 'flagged_only',
            ]);
            $filterParams = array_filter($filterParams, function ($value) {
                return $value !== null && $value !== '';
            });
            if ($localDate) { $filterParams['scheduled_local_date'] = $localDate; }
            if ($deliveredActivityWindow) {
                $window = $deliveredActivityWindow->metadata();
                $filterParams['delivered_activity_from'] = $window['from'];
                $filterParams['delivered_activity_to'] = $window['to'];
            }
            ksort($filterParams);
            if (!empty($filterParams)) {
                $cacheKey .= '_' . md5(json_encode($filterParams));
            }

            $needsFiles = $request->query('include_files', 'false') === 'true';
            $skipCache = ! $authorization->canManageShootOperations($user) || $isImpersonating
                || $needsFiles || $request->boolean('include_payments')
                || filter_var($request->query('no_cache', false), FILTER_VALIDATE_BOOLEAN);
            if (!$skipCache) {
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return response()->json($cached);
                }
            }

            $eagerLoads = [
                'client:id,name,email,company_name,phonenumber',
                'photographer:id,name,email,phone,phonenumber,avatar,timezone',
                'editor:id,name,avatar',
                'rep:id,name,email,avatar',
                'service:id,name',
                'services.category',
                'ghostUsers:id,name,email,company_name',
                'createdByUser', 'featuredHomepageImages.file', 'services.sqftRanges',
            ];

            if ($needsFiles) {
                $eagerLoads['files'] = function ($query) {
                    $query->select(
                        'id',
                        'shoot_id',
                        'shoot_service_id',
                        'content_version',
                        'filename',
                        'stored_filename',
                        'workflow_stage',
                        'is_favorite',
                        'is_cover',
                        'is_hidden',
                        'flag_reason',
                        'url',
                        'path',
                        'dropbox_path',
                        'file_type',
                        'mime_type',
                        'media_type',
                        'scan_status',
                        'is_extra',
                        'metadata',
                        'created_at',
                        'verified_at',
                        'thumbnail_path',
                        // The 600px grid rendition every card and grid tile
                        // displays. Omitting it here silently downgraded those
                        // surfaces: the presenter's grid_url fell back to the
                        // 1500px web file, so a 256px-tall history card pulled
                        // ~180KB per thumbnail.
                        'grid_path',
                        'web_path',
                        'placeholder_path',
                        'watermarked_storage_path',
                        'watermarked_thumbnail_path',
                        'watermarked_web_path',
                        'watermarked_placeholder_path'
                    );
                };
            }

            $needsPayments = $request->query('include_payments', 'false') === 'true';
            if ($needsPayments) {
                $eagerLoads['payments'] = 'id,shoot_id,amount,paid_at,status';
            }

            $query = app(ShootManagementAccess::class)->can($user)
                ? Shoot::with($eagerLoads)
                : $authorization->scopeAccessibleShootMedia(Shoot::with($eagerLoads), $user);

            $dashboardOpen = $tab === 'completed' && $request->boolean('dashboard_open')
                && $authorization->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'editor', 'photographer', 'salesRep']);
            $this->applyTabScope($query, $tab, $user, $dashboardOpen);
            if ($tab === 'scheduled' && in_array($request->query('scheduled_status'), ['requested', 'scheduled'], true)) {
                // Apply before pagination so a requested tab cannot be an empty
                // slice of a page occupied by scheduled shoots.
                $query->whereRaw("LOWER(COALESCE(NULLIF(workflow_status, ''), status)) = ?", [
                    $request->query('scheduled_status'),
                ]);
            }
            if ($tab === 'hold' && in_array($request->query('hold_status'), ['on_hold', 'cancelled'], true)) {
                $query->where('status', $request->query('hold_status'));
            }
            $this->applyOperationalFilters($query, $request, $tab, $user);
            if ($request->filled('scheduled_local_date')) {
                $this->applyScheduledLocalDate($query, $localDate);
            }
            $deliveredActivityWindow?->apply($query);

            $maxLimit = 1000;
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

            $ordering = app(ShootListOrdering::class);
            $sort = $ordering->mode($request->query('sort'));
            $shoots = $sort
                ? $ordering->paginate($query, $sort, $perPage, $page)
                : $query->paginate($perPage, ['*'], 'page', $page);
            $isClientUser = $user && $user->role === 'client';

            // Dashboard cards need hero/preview thumbnails even when file rows are
            // omitted (include_files=false). Batch-load a tiny preview subset so
            // salesRep/photographer/editor delivered cards are not placeholders.
            if (! $needsFiles && $shoots->getCollection()->isNotEmpty()) {
                $this->eagerLoadListCardPreviewFiles($shoots->getCollection());
            }

            $transformedShoots = ShootListReadContext::run($shoots->getCollection(), fn () => $shoots->getCollection()->map(function (Shoot $shoot) use ($transformShoot, $isClientUser, $needsFiles, $request) {
                $result = $transformShoot($shoot, $isClientUser, $needsFiles);
                return $request->query('view') === 'card' ? ShootCardProjection::from($result) : $result;
            }));

            $shoots->setCollection($transformedShoots);

            $response = [
                'data' => $shoots->items(),
                'meta' => [
                    'tab' => $tab,
                    'count' => $shoots->total(),
                    'current_page' => $shoots->currentPage(),
                    'per_page' => $shoots->perPage(),
                    'last_page' => $shoots->lastPage(),
                    'calendar_context' => $calendar,
                    ...($deliveredActivityWindow ? ['delivered_activity_window' => $deliveredActivityWindow->metadata()] : []),
                    ...($localDate ? ['scheduled_local_date' => $localDate] : []),
                    ...($request->boolean('include_filters', true) ? ['filters' => $this->buildOperationalFilterMeta($request, $user)] : []),
                ],
            ];

            if (!$skipCache) {
                Cache::put($cacheKey, $response, now()->addSeconds(30));
                self::rememberCacheKey($cacheKey);
            }

            $serializeStart = hrtime(true);
            $json = response()->json($response);
            $request->attributes->set('serialization_ms', (hrtime(true) - $serializeStart) / 1e6);
            return $json;
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
     * Attach up to six lightweight preview files per shoot for dashboard cards.
     * Full file rows stay omitted from include_files=false responses.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Shoot>  $shoots
     */
    public function eagerLoadListCardPreviewFiles($shoots): void
    {
        $ids = $shoots->pluck('id')->filter()->values()->all();
        if ($ids === []) {
            return;
        }

        $columns = [
            'id',
            'shoot_id',
            'shoot_service_id',
            'filename',
            'stored_filename',
            'workflow_stage',
            'content_version',
            'is_cover',
            'is_hidden',
            'is_extra',
            'required_for_editing',
            'metadata',
            'url',
            'path',
            'dropbox_path',
            'file_type',
            'mime_type',
            'media_type',
            'thumbnail_path',
            'grid_path',
            'web_path',
            'placeholder_path',
            'watermarked_web_path',
            'watermarked_thumbnail_path',
            'watermarked_placeholder_path',
            'scan_status',
            'created_at',
            'verified_at',
            'sort_order',
        ];

        $buildQuery = function (array $shootIds) use ($columns) {
            return ShootFile::query()
                ->whereIn('shoot_id', $shootIds)
                ->where(function ($query) {
                    $query->where('is_hidden', false)->orWhereNull('is_hidden');
                })
                ->where(function ($query) {
                    $query->whereNull('media_type')
                        ->orWhereNotIn('media_type', ['floorplan', 'video']);
                })
                ->where(function ($query) {
                    $query->whereNotNull('grid_path')
                        ->orWhereNotNull('web_path')
                        ->orWhereNotNull('thumbnail_path')
                        ->orWhereNotNull('url')
                        ->orWhereNotNull('path');
                })
                ->select($columns)
                ->orderByDesc('is_cover')
                ->orderByRaw('CASE WHEN sort_order IS NULL OR sort_order <= 0 THEN 1 ELSE 0 END asc')
                ->orderBy('sort_order', 'asc')
                ->orderByDesc('id');
        };

        $preferred = $buildQuery($ids)
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->get()
            ->groupBy('shoot_id');

        $missingIds = collect($ids)
            ->reject(fn ($id) => ($preferred->get($id)?->isNotEmpty() ?? false))
            ->values()
            ->all();

        $fallback = collect();
        if ($missingIds !== []) {
            $fallback = $buildQuery($missingIds)
                ->get()
                ->groupBy('shoot_id');
        }

        $viewer = auth()->user();
        $dispatchOnly = [];
        $dispatchFiles = [];
        if ($viewer && $viewer->role === 'editor') {
            $permanent = DB::table('shoots')->whereIn('id', $ids)->where('editor_id', $viewer->id)->pluck('id')
                ->merge(DB::table('shoot_service')->whereIn('shoot_id', $ids)->where(function ($query) use ($viewer) {
                    $query->where('editor_id', $viewer->id)->orWhere('video_editor_id', $viewer->id);
                })->pluck('shoot_id'))
                ->map(fn ($id) => (int) $id)
                ->all();
            $dispatchOnly = array_fill_keys(array_map('intval', $ids), true);
            foreach ($permanent as $id) {
                unset($dispatchOnly[(int) $id]);
            }
            if ($dispatchOnly !== []) {
                $dispatchFiles = app(ShootEditingAssignmentService::class)->openDispatchFileIdsByShoot(array_keys($dispatchOnly), $viewer->id);
            }
        }

        $cardMedia = app(DeliveredCardMedia::class);
        $authorization = app(ShootAuthorizationSupport::class);
        // Batch assignment checks without attaching unsanitized serviceItems
        // to the original page models consumed by ShootPresenter.
        $policyShoots = new \Illuminate\Database\Eloquent\Collection($shoots->map(fn (Shoot $shoot) => clone $shoot)->all());
        if ($viewer && ($authorization->hasRole($viewer, ['editor', 'photographer'])
            || ($authorization->isClientUser($viewer) && $shoots->contains(fn (Shoot $shoot) => app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot))))) {
            $policyShoots->load('serviceItems.service.category');
        }
        $policyShoots = $policyShoots->keyBy('id');
        app(ShootPhotographerAssignmentService::class)->withPreparedRead($policyShoots, function () use (
            $shoots, $policyShoots, $preferred, $fallback, $dispatchOnly, $dispatchFiles, $viewer, $authorization, $cardMedia
        ) {
            foreach ($shoots as $shoot) {
                $policyShoot = $policyShoots->get($shoot->id);
                if ($policyShoot->relationLoaded('serviceItems')) app(DeliveryPlanService::class)->primeRequirements($policyShoot, $policyShoot->serviceItems);
                $group = $preferred->get($shoot->id) ?? $fallback->get($shoot->id) ?? collect();
                if (isset($dispatchOnly[(int) $shoot->id])) {
                    $allowed = array_flip($dispatchFiles[(int) $shoot->id] ?? []);
                    $group = $group->filter(fn (ShootFile $file) => isset($allowed[(int) $file->id]))->values();
                }
                $group = $viewer ? $group->filter(fn (ShootFile $file) => $authorization->canViewShootMediaFile($policyShoot, $file, $viewer))->values() : collect();
                $shoot->setRelation('list_preview_files', $group->take(6)->values());
                $shoot->setAttribute('delivery_card_media', $cardMedia->forShoot($policyShoot, $group, $viewer));
            }
        });
    }

    private function privateListingDiscovery(Request $request): JsonResponse
    {
        $query = Shoot::query()->select([
            'id', 'address', 'city', 'state', 'zip', 'latitude', 'longitude',
            'listing_type', 'property_details',
            // Used only to enforce the existing public-image release gate.
            'status', 'workflow_status', 'payment_status', 'total_quote', 'total_paid', 'bypass_paywall',
            'active_work_started_at', 'active_work_completed_at', 'active_work_is_reopened', 'admin_verified_at', 'completed_at',
        ])->withExists('units')->where('is_private_listing', true)
            ->where(fn (Builder $scope) => $scope->where('is_listing_hidden', false)->orWhereNull('is_listing_hidden'))
            ->where(function (Builder $visible) {
                $visible->where(function (Builder $completed) {
                    $completed->whereIn(DB::raw("LOWER(COALESCE(NULLIF(workflow_status, ''), status, ''))"), [
                        Shoot::STATUS_DELIVERED, 'ready_for_client', 'admin_verified', 'ready', 'workflow_completed', 'client_delivered',
                    ])->where(fn (Builder $closed) => $closed->whereRaw(ActiveWorkQuery::closedSql()));
                })->orWhere(function (Builder $active) {
                    $active->whereRaw(ActiveWorkQuery::openSql())
                        ->whereHas('serviceItems', fn (Builder $items) => $items->where('is_deliverable', true)
                            ->whereNull('cancelled_at')->where('workflow_status', 'delivered')->where('delivery_status', 'delivered'));
                });
            });

        // Operational filters can become an oracle for private contacts and
        // assignments. Discovery searches only fields actually returned below.
        foreach (['search', 'address'] as $filter) {
            $value = $request->query($filter, '');
            $term = is_string($value) ? trim($value) : '';
            if ($term !== '') {
                $query->where(fn (Builder $scope) => $scope->where('address', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%")->orWhere('state', 'like', "%{$term}%")
                    ->orWhere('zip', 'like', "%{$term}%"));
            }
        }

        $perPage = min(200, max(9, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $shoots = $query->orderBy('id', 'desc')->paginate($perPage, ['*'], 'page', $page);
        $previews = app(ExclusiveListingPreview::class)->forShoots($shoots->getCollection());
        $data = $shoots->getCollection()->map(function (Shoot $shoot) use ($previews): array {
            // Never serialize the model or feed it to the operational presenter:
            // its relations, accessors, and property JSON contain private data.
            $property = is_array($shoot->property_details) ? $shoot->property_details : [];
            $numeric = static fn ($value) => is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
            return [
                'id' => $shoot->id,
                'address' => $shoot->address,
                'city' => $shoot->city,
                'state' => $shoot->state,
                'zip' => $shoot->zip,
                'latitude' => $numeric($shoot->latitude ?? $property['latitude'] ?? $property['lat'] ?? null),
                'longitude' => $numeric($shoot->longitude ?? $property['longitude'] ?? $property['lng'] ?? null),
                'listing_type' => in_array($shoot->listing_type, ['for_sale', 'for_rent'], true) ? $shoot->listing_type : null,
                'bedrooms' => $numeric($property['bedrooms'] ?? null),
                'bathrooms' => $numeric($property['bathrooms'] ?? null),
                'sqft' => $numeric($property['sqft'] ?? null),
                // Asking price is public property information, never the shoot invoice.
                'price' => $numeric($property['price'] ?? null),
                'is_private_listing' => true,
                'is_listing_hidden' => false,
                'discovery_only' => true,
                'can_view_details' => false,
                'can_download_media' => false,
                'preview_image_url' => $previews[$shoot->id] ?? null,
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'tab' => 'delivered', 'count' => $shoots->total(),
                'current_page' => $shoots->currentPage(), 'per_page' => $shoots->perPage(),
                'last_page' => $shoots->lastPage(),
                'filters' => ['clients' => [], 'photographers' => [], 'services' => []],
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function filters(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && app(ShootAuthorizationSupport::class)->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'editor', 'photographer', 'client', 'salesRep']), 403);
        return response()->json(['data' => $this->buildOperationalFilterMeta($request, $user)])->header('Cache-Control', 'private, no-store');
    }

    public static function flushCachedListings(): void
    {
        $keys = collect((array) Cache::get(self::CACHE_REGISTRY_KEY, []))
            ->filter(fn ($key) => is_string($key) && $key !== '')
            ->unique()
            ->values();

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Cache::forget(self::CACHE_REGISTRY_KEY);
    }

    protected static function rememberCacheKey(string $cacheKey): void
    {
        $keys = collect((array) Cache::get(self::CACHE_REGISTRY_KEY, []))
            ->push($cacheKey)
            ->filter()
            ->unique()
            ->values()
            ->all();

        Cache::put(self::CACHE_REGISTRY_KEY, $keys, now()->addDay());
    }

    /** Filter before pagination using property-local dates, including scheduled_at-only rows. */
    protected function applyScheduledLocalDate(Builder $query, string $date): void
    {
        $activeIds = (clone $query)->whereRaw(ActiveWorkQuery::openSql())
            ->where('active_work_date', $date)->pluck('shoots.id')->all();
        $accessible = (clone $query)->reorder()->select('shoots.id')
            ->where(fn (Builder $closed) => $closed->whereRaw(ActiveWorkQuery::closedSql()));
        $ids = app(ScheduleDateScopeService::class)->shootsForLocalDate($date, [],
            fn (Builder $candidates) => $candidates
                ->select(['shoots.id', 'scheduled_at', 'scheduled_date', 'time', 'timezone'])
                ->whereIn('shoots.id', $accessible)
        )->modelKeys();

        $query->whereIn('shoots.id', array_unique([...$ids, ...$activeIds]));
    }

    protected function applyTabScope(Builder $query, string $tab, ?User $user = null, bool $dashboardOpen = false): void
    {
        if ($tab === 'all') {
            // Cross-status search / command bar: auth scopes still apply; no status gate.
            return;
        }

        if ($tab === 'featured') {
            $query->where(function (Builder $scope) {
                $scope->where('is_featured', true)
                    ->orWhereNotNull('featured_requested_at');
            });
            return;
        }

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

            $query->where(function (Builder $scope) use ($statuses, $workflowStatuses, $user) {
                $scope->whereIn('status', $statuses)
                    ->orWhereIn('workflow_status', $workflowStatuses);
                if ($user && $user->role === 'client') {
                    $scope->orWhere(fn (Builder $partial) => $partial->where('delivery_status', 'partially_delivered')
                        ->whereNotIn('status', ['cancelled', 'canceled', 'declined'])
                        ->whereNotIn('workflow_status', ['cancelled', 'canceled', 'declined']));
                    $scope->orWhere(fn (Builder $active) => $active->whereRaw(ActiveWorkQuery::openSql())->whereHas('serviceItems', fn (Builder $item) => $item->where('delivery_status', 'delivered')));
                }
            });
            if (! $user || $user->role !== 'client') {
                $query->where(fn (Builder $closed) => $closed->whereRaw(ActiveWorkQuery::closedSql()));
            }

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

            if ($dashboardOpen) {
                // Ready is edited work awaiting finalization, although ordinary
                // history groups it with delivered. Keep that lane paginatable
                // without pulling the entire delivered history into a dashboard.
                $workflowStatuses[] = Shoot::STATUS_READY;
            }
            $query->where(function (Builder $scope) use ($statuses, $workflowStatuses, $user, $dashboardOpen) {
                $scope->where(fn (Builder $cycle) => $cycle->whereRaw(ActiveWorkQuery::openSql()));
                $scope->orWhere(function (Builder $active) use ($statuses, $workflowStatuses, $dashboardOpen) {
                    if ($dashboardOpen) {
                        $active->whereIn(DB::raw("LOWER(COALESCE(NULLIF(workflow_status, ''), status, ''))"), $workflowStatuses);
                    } else {
                        $active->whereIn('status', $statuses)
                            ->orWhereIn('workflow_status', $workflowStatuses);
                    }
                });

                // Keep incomplete editing-lane work on the editor queue after photos
                // are delivered so video editors can still upload / set embeds.
                if ($user && $user->role === 'editor') {
                    $editorId = (int) $user->id;
                    $deliveredStatuses = [
                        Shoot::STATUS_DELIVERED,
                        Shoot::STATUS_READY,
                        'ready',
                        'ready_for_client',
                        'admin_verified',
                        'client_delivered',
                        'workflow_completed',
                        Shoot::STATUS_REVIEW,
                        'review',
                    ];
                    $scope->orWhere(function (Builder $incomplete) use ($editorId, $deliveredStatuses) {
                        $incomplete
                            ->where(function (Builder $delivered) use ($deliveredStatuses) {
                                $delivered->whereIn('status', $deliveredStatuses)
                                    ->orWhereIn('workflow_status', $deliveredStatuses);
                            })
                            ->where(function (Builder $work) use ($editorId) {
                                $work->whereHas('services', function (Builder $serviceQuery) use ($editorId) {
                                    $serviceQuery->where('shoot_service.is_deliverable', true)->whereNull('shoot_service.cancelled_at')
                                        ->whereNotIn('shoot_service.workflow_status', ['cancelled', 'canceled'])
                                        ->whereNotIn('shoot_service.delivery_status', ['cancelled', 'canceled'])
                                        ->where(function (Builder $lane) use ($editorId) {
                                        $lane->where(function (Builder $photo) use ($editorId) {
                                            $photo->where('shoot_service.editor_id', $editorId)
                                                ->whereNull('shoot_service.editing_completed_at');
                                        })->orWhere(function (Builder $video) use ($editorId) {
                                            $video->where('shoot_service.video_editor_id', $editorId)
                                                ->whereNull('shoot_service.video_editing_completed_at');
                                        });
                                    });
                                })->orWhereExists(function ($exists) use ($editorId) {
                                    app(ShootEditingAssignmentService::class)->constrainOpenDispatchItems($exists, $editorId);
                                });
                            });
                    });
                }
            });

            $query->where(function (Builder $open) {
                $open->whereRaw(ActiveWorkQuery::closedSql())
                    ->orWhereNotIn(DB::raw("LOWER(COALESCE(NULLIF(workflow_status, ''), status, ''))"), ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined']);
            });
            if ($user && in_array($user->role, ['photographer', 'editor'], true)) {
                $query->where(function (Builder $visible) use ($user) {
                    $visible->whereRaw(ActiveWorkQuery::closedSql())
                        ->orWhere(function (Builder $active) use ($user) {
                            $active->whereHas('serviceItems', function (Builder $items) use ($user) {
                                $items->where('is_deliverable', true)->whereNull('cancelled_at')
                                    ->where(fn (Builder $state) => $state->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'cancelled'))
                                    ->where(fn (Builder $pending) => $pending->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'delivered')
                                        ->orWhereNull('workflow_status')->orWhere('workflow_status', '!=', 'delivered'))
                                    ->where(fn (Builder $state) => $state->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled'))
                                    ->where(fn (Builder $billing) => $billing->whereNull('payout_snapshot->billing_correction_only')
                                        ->orWhere('payout_snapshot->billing_correction_only', false))
                                    ->where(function (Builder $assignment) use ($user) {
                                        if ($user->role === 'photographer') $assignment->where('photographer_id', $user->id);
                                        else $assignment->where('editor_id', $user->id)->orWhere('video_editor_id', $user->id);
                                    });
                            });
                            if ($user->role === 'editor') {
                                $active->orWhereExists(function ($exists) use ($user) {
                                    app(ShootEditingAssignmentService::class)->constrainOpenDispatchItems($exists, $user->id);
                                    $exists->whereColumn('shoot_editing_dispatch_items.created_at', '>=', 'shoots.active_work_started_at');
                                });
                            }
                        });
                });
            }
            return;
        }

        $query->whereIn('status', $statuses);
        if ($tabKey === 'scheduled') {
            $query->where(fn (Builder $closed) => $closed->whereRaw(ActiveWorkQuery::closedSql()));
        }
    }

    protected function applyOperationalFilters(Builder $query, Request $request, string $tab, ?User $user = null): void
    {
        app(ShootStatusFilter::class)->apply($query, $request->query('statuses', []));
        if ($request->boolean('unassigned_only')) {
            // Match the web's main-photographer filter. This does not assert
            // that every individual service has an assignment.
            $query->whereNull('photographer_id');
        }
        if ($request->boolean('flagged_only')) {
            $query->where('is_flagged', true);
        }
        if ($request->filled('shoot_id')) $query->whereKey($request->integer('shoot_id'));
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $this->applySearchFilter($query, $search);
        }

        $clientIds = $this->normalizeArrayQuery($request, 'client_id');
        if (!empty($clientIds)) {
            $query->whereIn('client_id', $clientIds);
        }

        app(ShootSalesRepFilter::class)->apply($query, $this->normalizeArrayQuery($request, 'sales_rep_id'));
        app(ShootPaymentFilter::class)->apply($query, $request->query('payment_status'));

        $photographerIds = $this->normalizeArrayQuery($request, 'photographer_id');
        if (!empty($photographerIds)) {
            app(ShootPhotographerAssignmentService::class)->scopeAssignedTo($query, $photographerIds);
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

        $this->applyBracketFilter($query, $request->query('bracket'));

        $missing = $request->query('missing');
        if ($missing === 'raw') {
            $query->where('missing_raw', true);
        } elseif ($missing === 'final') {
            $query->where('missing_final', true);
        }

        // Calendar navigation always refers to the booked day, including in
        // Delivery. Keep date_from/date_to's existing delivery-date meaning.
        $scheduledStart = $request->query('scheduled_start');
        $scheduledEnd = $request->query('scheduled_end');
        if ($scheduledStart || $scheduledEnd) {
            $this->applyDateRangeFilter($query, 'work_date', $scheduledStart, $scheduledEnd);
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        if ($dateFrom || $dateTo) {
            $column = $tab === 'delivered' ? 'admin_verified_at' : 'work_date';
            $this->applyDateRangeFilter($query, $column, $dateFrom, $dateTo);
        }

        $privateListing = $request->query('private_listing');
        if ($privateListing !== null) {
            $isPrivateListing = filter_var($privateListing, FILTER_VALIDATE_BOOLEAN);
            $query->where('is_private_listing', $isPrivateListing);

            if ($isPrivateListing) {
                $isAdminUser = $user && in_array($user->role, ['admin', 'superadmin'], true);
                $includeHidden = filter_var($request->query('include_hidden', false), FILTER_VALIDATE_BOOLEAN);
                if (!$isAdminUser || !$includeHidden) {
                    $query->where(function (Builder $scope) {
                        $scope->where('is_listing_hidden', false)
                            ->orWhereNull('is_listing_hidden');
                    });
                }
            }
        }
    }

    protected function determineTabOrdering(string $tab): array
    {
        switch ($tab) {
            case 'featured':
                return [
                    ['raw' => 'CASE WHEN is_featured = 0 AND featured_requested_at IS NOT NULL THEN 0 ELSE 1 END ASC'],
                    ['raw' => 'COALESCE(featured_requested_at, featured_approved_at, admin_verified_at, editing_completed_at, scheduled_date, created_at) DESC'],
                ];
            case 'delivered':
                return [
                    ['raw' => ShootListOrdering::DELIVERED_NEWEST],
                    ['column' => 'scheduled_date', 'direction' => 'desc'],
                    ['column' => 'id', 'direction' => 'desc'],
                ];
            case 'completed':
                return [
                    ['raw' => 'COALESCE(active_work_at, created_at) DESC'],
                    ['column' => 'id', 'direction' => 'desc'],
                ];
            case 'hold':
            case 'scheduled':
            default:
                return [
                    ['column' => 'created_at', 'direction' => 'desc'],
                ];
        }
    }

    protected function buildOperationalFilterMeta(Request $request, ?User $user): array
    {
        $authorization = app(ShootAuthorizationSupport::class);
        $build = function () use ($authorization, $user) {
            $accessible = $authorization->scopeAccessibleShootMedia(Shoot::query(), $user);
            $shootIds = fn () => (clone $accessible)->select('shoots.id');
            $clients = User::where('role', 'client')
                ->whereIn('id', (clone $accessible)->select('client_id'))
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(function (User $client) {
                    return [
                        'id' => $client->id,
                        'name' => $client->name ?? 'Unknown',
                    ];
                })
                ->values();

            $photographers = User::where('role', 'photographer')
                ->where(fn (Builder $scope) => $scope->whereIn('id', (clone $accessible)->select('photographer_id'))
                    ->orWhereIn('id', DB::table('shoot_service')->select('photographer_id')->whereIn('shoot_id', $shootIds())))
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(function (User $photographer) {
                    return [
                        'id' => $photographer->id,
                        'name' => $photographer->name ?? 'Unknown',
                    ];
                })
                ->values();

            $services = Service::select('id', 'name')
                ->where(fn (Builder $scope) => $scope->whereIn('id', (clone $accessible)->select('service_id'))
                    ->orWhereIn('id', DB::table('shoot_service')->select('service_id')->whereIn('shoot_id', $shootIds())))
                ->orderBy('name')
                ->get()
                ->pluck('name')
                ->filter()
                ->values();

            return [
                'salesReps' => app(ShootSalesRepFilter::class)->options($accessible),
                'clients' => $clients,
                'photographers' => $photographers,
                'services' => $services,
                'statuses' => app(ShootStatusFilter::class)->options(),
                'filter_capabilities' => $this->filterCapabilities($user),
            ];
        };

        // Assignment and account-link removal must take effect on the next
        // request; restricted actors never read or write cached filter metadata.
        if (! $authorization->canManageShootOperations($user) || $request->attributes->get('is_impersonating', false)) {
            return $build();
        }

        $cacheKey = 'shoots_filter_meta_access_v4_' . $user->id . '_' . $user->role;
        return Cache::remember($cacheKey, now()->addMinutes(5), $build);
    }

    protected function filterCapabilities(?User $user): array
    {
        $authorization = app(ShootAuthorizationSupport::class);

        return [
            'assignments' => $authorization->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'salesRep']),
            'priority' => $authorization->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'editor', 'photographer']),
        ];
    }

    protected function applySearchFilter(Builder $query, string $term): void
    {
        app(ShootSearchFilter::class)->apply($query, $term);
    }

    protected function normalizeArrayQuery(Request $request, string $key): array
    {
        $value = $request->query($key);

        if (is_null($value)) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($entry) => $entry !== '' && $entry !== null));
        }

        $parts = array_map('trim', explode(',', (string) $value));

        return array_values(array_filter($parts, static fn ($entry) => $entry !== ''));
    }

    /**
     * Filter shoots by the bracket sizes their services were shot at.
     *
     * Bracket size lives on each shoot-service, so a shoot can legitimately hold
     * more than one at once: Exterior at 5x by one photographer and Interior at 3x
     * by another. The filter therefore asks whether the shoot has *any*
     * bracket-enabled service resolving to the requested size, which means a mixed
     * shoot correctly matches both `bracket=5` and `bracket=3`.
     *
     * Resolution is inlined as SQL rather than delegated to BracketModeResolver
     * because this runs as part of a paginated list query. It mirrors the resolver's
     * chain: the item's own size, then the photographer's preference, then the
     * legacy shoot value, then 5.
     *
     * `none` means no bracket-enabled services at all, which is the honest reading
     * of "this shoot has no bracketed work" now that a null shoot column no longer
     * implies it.
     */
    protected function applyBracketFilter(Builder $query, mixed $bracket): void
    {
        if ($bracket !== 'none' && ! in_array($bracket, ['3', '5'], true)) {
            return;
        }

        $hasPerServiceBrackets = Schema::hasColumn('shoot_service', 'bracket_mode')
            && Schema::hasColumn('services', 'uses_hdr_brackets');

        if (! $hasPerServiceBrackets) {
            // Pre-migration fallback: the legacy shoot-wide column is all there is.
            if ($bracket === 'none') {
                $query->whereNull('bracket_mode');
            } else {
                $query->where('bracket_mode', (int) $bracket);
            }

            return;
        }

        // These closures receive a query builder from whereExists, not an Eloquent
        // builder, so they stay untyped.
        $bracketedServices = function ($scope) {
            $scope->select(DB::raw(1))
                ->from('shoot_service')
                ->join('services', 'services.id', '=', 'shoot_service.service_id')
                ->whereColumn('shoot_service.shoot_id', 'shoots.id')
                ->where('services.uses_hdr_brackets', true);
        };

        if ($bracket === 'none') {
            $query->whereNotExists($bracketedServices);

            return;
        }

        $wanted = (int) $bracket;
        $hasDefaultBracketMode = Schema::hasColumn('users', 'default_bracket_mode');

        $query->whereExists(function ($scope) use ($bracketedServices, $wanted, $hasDefaultBracketMode) {
            $bracketedServices($scope);

            $scope->where(function ($modeScope) use ($wanted, $hasDefaultBracketMode) {
                // Explicitly recorded size.
                $modeScope->where('shoot_service.bracket_mode', $wanted);

                // Not recorded: fall through the same chain the resolver uses.
                $modeScope->orWhere(function ($fallback) use ($wanted, $hasDefaultBracketMode) {
                    $fallback->whereNull('shoot_service.bracket_mode');

                    if ($hasDefaultBracketMode) {
                        $fallback->where(function ($preference) use ($wanted) {
                            $preference
                                // The photographer states this size.
                                ->whereExists(fn ($user) => $user->select(DB::raw(1))
                                    ->from('users')
                                    ->whereColumn('users.id', 'shoot_service.photographer_id')
                                    ->where('users.default_bracket_mode', $wanted))
                                // Or states nothing, so the legacy value or the
                                // default of 5 decides.
                                ->orWhere(function ($noPreference) use ($wanted) {
                                    $noPreference->where(fn ($absent) => $absent
                                        ->whereNull('shoot_service.photographer_id')
                                        ->orWhereExists(fn ($user) => $user->select(DB::raw(1))
                                            ->from('users')
                                            ->whereColumn('users.id', 'shoot_service.photographer_id')
                                            ->whereNull('users.default_bracket_mode')));

                                    $this->whereLegacyOrDefaultBracket($noPreference, $wanted);
                                });
                        });

                        return;
                    }

                    $this->whereLegacyOrDefaultBracket($fallback, $wanted);
                });
            });
        });
    }

    /**
     * The tail of the resolution chain: the legacy shoot value if it states a size,
     * otherwise the default of 5.
     */
    protected function whereLegacyOrDefaultBracket($query, int $wanted): void
    {
        $query->where(function ($scope) use ($wanted) {
            $scope->where('shoots.bracket_mode', $wanted);

            if ($wanted === BracketModeResolver::DEFAULT_BRACKET_MODE) {
                $scope->orWhereNull('shoots.bracket_mode')
                    ->orWhereNotIn('shoots.bracket_mode', BracketModeResolver::ALLOWED_BRACKET_MODES);
            }
        });
    }

    protected function applyDateRangeFilter(Builder $query, string $column, ?string $start, ?string $end): void
    {
        if ($column === 'work_date') {
            $column = DB::raw('CASE WHEN '.ActiveWorkQuery::openSql().' THEN active_work_date ELSE scheduled_date END');
        }
        if ($start) {
            try {
                $query->whereDate($column, '>=', Carbon::parse($start)->toDateString());
            } catch (\Throwable $e) {
                // Ignore invalid date input.
            }
        }

        if ($end) {
            try {
                $query->whereDate($column, '<=', Carbon::parse($end)->toDateString());
            } catch (\Throwable $e) {
                // Ignore invalid date input.
            }
        }
    }
}
