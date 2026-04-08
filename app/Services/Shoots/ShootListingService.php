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

class ShootListingService
{
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
        try {
            $tab = strtolower($request->query('tab', 'scheduled'));
            $isPrivateListingRequest = $request->query('private_listing') !== null;
            $privateListingScope = strtolower((string) $request->query('listing_scope', 'mine'));

            if (!$request->has('tab') && $request->query('private_listing') !== null) {
                $tab = 'delivered';
            }

            $page = (int) $request->query('page', 1);
            $maxPerPage = $isPrivateListingRequest ? 200 : 50;
            $perPage = min($maxPerPage, max(9, (int) $request->query('per_page', 25)));
            $userId = $user ? $user->id : 'guest';
            $userRole = $user ? $user->role : 'guest';

            $isImpersonating = $request->attributes->get('is_impersonating', false);
            $impersonationSuffix = $isImpersonating ? '_imp' : '';

            $cacheKey = 'shoots_index_' . $userId . '_' . $userRole . $impersonationSuffix . '_' . $tab . '_' . $page . '_' . $perPage;

            $filterParams = $request->only([
                'client_id', 'photographer_id', 'services', 'search', 'address',
                'date_range', 'scheduled_start', 'scheduled_end',
                'completed_start', 'completed_end', 'custom_start', 'custom_end',
                'date_from', 'date_to', 'private_listing', 'listing_scope',
            ]);
            $filterParams = array_filter($filterParams, function ($value) {
                return $value !== null && $value !== '';
            });
            ksort($filterParams);
            if (!empty($filterParams)) {
                $cacheKey .= '_' . md5(json_encode($filterParams));
            }

            $needsFiles = $request->query('include_files', 'false') === 'true';
            $skipCache = $needsFiles || filter_var($request->query('no_cache', false), FILTER_VALIDATE_BOOLEAN);
            if (!$skipCache) {
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return response()->json($cached);
                }
            }

            $eagerLoads = [
                'client:id,name,email,company_name,phonenumber',
                'photographer:id,name,avatar',
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
                        'workflow_stage',
                        'is_favorite',
                        'is_cover',
                        'flag_reason',
                        'url',
                        'path',
                        'dropbox_path',
                        'thumbnail_path',
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

            $query = Shoot::with($eagerLoads);

            if ($user && $user->role === 'photographer') {
                $query->where(function (Builder $scope) use ($user) {
                    $scope->where('photographer_id', $user->id)
                        ->orWhereHas('services', function (Builder $serviceQuery) use ($user) {
                            $serviceQuery->where('shoot_service.photographer_id', $user->id);
                        });
                });
            } elseif ($user && $user->role === 'client') {
                $canViewAllPrivateListings = $isPrivateListingRequest && $privateListingScope === 'all';
                if (!$canViewAllPrivateListings) {
                    $query->where(function (Builder $scope) use ($user) {
                        $scope->where('client_id', $user->id)
                            ->orWhere(function (Builder $ghostScope) use ($user) {
                                $ghostScope->whereHas('ghostUsers', function (Builder $ghostQuery) use ($user) {
                                    $ghostQuery->where('users.id', $user->id);
                                })->where(function (Builder $deliveredScope) {
                                    $deliveredScope->whereIn('status', [Shoot::STATUS_DELIVERED])
                                        ->orWhereIn('workflow_status', [
                                            Shoot::STATUS_DELIVERED,
                                            'ready_for_client',
                                            'admin_verified',
                                            'ready',
                                            'workflow_completed',
                                            'client_delivered',
                                        ]);
                                });
                            });
                    });
                }
            } elseif ($user && $user->role === 'editor') {
                app(ShootEditingAssignmentService::class)->scopeAssignedToEditor($query, $user->id);

                if (!$request->has('tab')) {
                    $tab = 'completed';
                }
            }

            $this->applyTabScope($query, $tab);
            $this->applyOperationalFilters($query, $request, $tab);

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
        $cacheKey = 'shoots_filter_meta_' . ($user?->id ?? auth()->id()) . '_' . $request->query('tab', 'scheduled');

        return Cache::remember($cacheKey, now()->addHour(), function () {
            $clients = User::where('role', 'client')
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
            return array_values(array_filter($value, static fn ($entry) => $entry !== '' && $entry !== null));
        }

        $parts = array_map('trim', explode(',', (string) $value));

        return array_values(array_filter($parts, static fn ($entry) => $entry !== ''));
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
