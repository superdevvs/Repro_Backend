<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ShootListingService
{
    protected const CACHE_REGISTRY_KEY = 'shoots_index_cache_keys';

    protected const TAB_STATUS_MAP = [
        'scheduled' => [
            Shoot::STATUS_SCHEDULED,
            Shoot::STATUS_REQUESTED,
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

    public function index(Request $request, ?User $user, callable $transformShoot): JsonResponse
    {
        $authorization = app(ShootAuthorizationSupport::class);
        if (! $user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }
        if (! $authorization->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'editor', 'photographer', 'client', 'salesRep'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Discovery is a separate property-only projection, never permission to
        // request another client's operational shoot, media, or payment data.
        if ($authorization->isClientUser($user)
            && filter_var($request->query('private_listing'), FILTER_VALIDATE_BOOLEAN)
            && strtolower((string) $request->query('listing_scope')) === 'all') {
            return $this->privateListingDiscovery($request);
        }

        try {
            $tab = strtolower($request->query('tab', 'scheduled'));
            $isPrivateListingRequest = $request->query('private_listing') !== null;

            if (!$request->has('tab') && $request->query('private_listing') !== null) {
                $tab = 'delivered';
            } elseif (! $request->has('tab') && $authorization->hasRole($user, ['editor'])) {
                $tab = 'completed';
            }

            $page = (int) $request->query('page', 1);
            $maxPerPage = $isPrivateListingRequest ? 200 : 50;
            $perPage = min($maxPerPage, max(9, (int) $request->query('per_page', 25)));
            $userId = $user ? $user->id : 'guest';
            $userRole = $user ? $user->role : 'guest';

            $isImpersonating = $request->attributes->get('is_impersonating', false);
            $impersonationSuffix = $isImpersonating ? '_imp' : '';

            $cacheKey = 'shoots_index_access_v2_' . $userId . '_' . $userRole . $impersonationSuffix . '_' . $tab . '_' . $page . '_' . $perPage;

            // Every param that changes the result set has to be in the cache key.
            // `bracket`, `missing` and `limit` were applied to the query but absent
            // here, so two different filters shared one cache entry for its 30
            // second life: asking for bracket=3 straight after bracket=5 returned
            // the 5x list.
            $filterParams = $request->only([
                'client_id', 'photographer_id', 'services', 'search', 'address',
                'date_range', 'scheduled_start', 'scheduled_end',
                'completed_start', 'completed_end', 'custom_start', 'custom_end',
                'date_from', 'date_to', 'private_listing', 'listing_scope', 'include_hidden',
                'bracket', 'missing', 'limit',
            ]);
            $filterParams = array_filter($filterParams, function ($value) {
                return $value !== null && $value !== '';
            });
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
                'photographer:id,name,email,phone,phonenumber,avatar',
                'editor:id,name,avatar',
                'rep:id,name,email,avatar',
                'service:id,name',
                'services.category',
                'ghostUsers:id,name,email,company_name',
            ];

            if ($needsFiles) {
                $eagerLoads['files'] = function ($query) {
                    $query->select(
                        'id',
                        'shoot_id',
                        'shoot_service_id',
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

            $query = $authorization->scopeAccessibleShootMedia(Shoot::with($eagerLoads), $user);

            $this->applyTabScope($query, $tab);
            $this->applyOperationalFilters($query, $request, $tab, $user);

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

            $shoots = $query->paginate($perPage, ['*'], 'page', $page);
            $isClientUser = $user && $user->role === 'client';

            $transformedShoots = $shoots->getCollection()->map(function (Shoot $shoot) use ($transformShoot, $isClientUser) {
                return $transformShoot($shoot, $isClientUser);
            });

            $shoots->setCollection($transformedShoots);

            $response = [
                'data' => $shoots->items(),
                'meta' => [
                    'tab' => $tab,
                    'count' => $shoots->total(),
                    'current_page' => $shoots->currentPage(),
                    'per_page' => $shoots->perPage(),
                    'last_page' => $shoots->lastPage(),
                    'filters' => $this->buildOperationalFilterMeta($request, $user),
                ],
            ];

            if (!$skipCache) {
                Cache::put($cacheKey, $response, now()->addSeconds(30));
                self::rememberCacheKey($cacheKey);
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

    private function privateListingDiscovery(Request $request): JsonResponse
    {
        $query = Shoot::query()->select([
            'id', 'address', 'city', 'state', 'zip', 'latitude', 'longitude',
            'listing_type', 'property_details',
        ])->where('is_private_listing', true)
            ->where(fn (Builder $scope) => $scope->where('is_listing_hidden', false)->orWhereNull('is_listing_hidden'))
            ->whereIn(DB::raw("LOWER(COALESCE(NULLIF(workflow_status, ''), status, ''))"), [
                Shoot::STATUS_DELIVERED, 'ready_for_client', 'admin_verified', 'ready', 'workflow_completed', 'client_delivered',
            ]);

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
        $data = $shoots->getCollection()->map(function (Shoot $shoot): array {
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

    protected function applyTabScope(Builder $query, string $tab): void
    {
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

        $query->whereIn('status', $statuses);
    }

    protected function applyOperationalFilters(Builder $query, Request $request, string $tab, ?User $user = null): void
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
            $query->where(function (Builder $scope) use ($photographerIds) {
                $scope->whereIn('photographer_id', $photographerIds)
                    ->orWhereHas('services', function (Builder $serviceQuery) use ($photographerIds) {
                        $serviceQuery->whereIn('shoot_service.photographer_id', $photographerIds);
                    });
            });
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

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        if ($dateFrom || $dateTo) {
            $column = $tab === 'delivered' ? 'admin_verified_at' : 'scheduled_date';
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
                    ['raw' => 'COALESCE(admin_verified_at, editing_completed_at, scheduled_date) DESC'],
                ];
            case 'completed':
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
                'clients' => $clients,
                'photographers' => $photographers,
                'services' => $services,
            ];
        };

        // Assignment and account-link removal must take effect on the next
        // request; restricted actors never read or write cached filter metadata.
        if (! $authorization->canManageShootOperations($user) || $request->attributes->get('is_impersonating', false)) {
            return $build();
        }

        $cacheKey = 'shoots_filter_meta_access_v2_' . $user->id . '_' . $user->role;
        return Cache::remember($cacheKey, now()->addMinutes(5), $build);
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
