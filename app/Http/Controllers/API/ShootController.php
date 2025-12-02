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
use App\Services\DropboxWorkflowService;
use App\Services\MailService;
use App\Services\ShootWorkflowService;
use App\Services\ShootActivityLogger;
use App\Services\ShootTaxService;
use App\Services\PhotographerAvailabilityService;
use App\Http\Requests\StoreShootRequest;
use App\Http\Requests\UpdateShootStatusRequest;
use App\Http\Resources\ShootResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\UploadShootMediaToDropboxJob;
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

    protected const TAB_STATUS_MAP = [
        'scheduled' => [
            Shoot::WORKFLOW_BOOKED,
            Shoot::WORKFLOW_RAW_UPLOAD_PENDING,
            Shoot::WORKFLOW_RAW_UPLOADED,
            Shoot::WORKFLOW_RAW_ISSUE,
            Shoot::WORKFLOW_EDITING,
            Shoot::WORKFLOW_EDITING_ISSUE,
            Shoot::WORKFLOW_PENDING_REVIEW,
        ],
        'completed' => [
            Shoot::WORKFLOW_READY_FOR_CLIENT,
            Shoot::WORKFLOW_EDITING_UPLOADED,
            Shoot::WORKFLOW_ADMIN_VERIFIED,
            Shoot::WORKFLOW_COMPLETED,
        ],
        'hold' => [
            Shoot::WORKFLOW_ON_HOLD,
        ],
    ];

    protected const HISTORY_ALLOWED_ROLES = [
        'admin',
        'superadmin',
        'super_admin',
        'finance',
        'accounting',
    ];

    public function __construct(
        DropboxWorkflowService $dropboxService,
        MailService $mailService,
        ShootWorkflowService $workflowService,
        ShootActivityLogger $activityLogger,
        ShootTaxService $taxService,
        PhotographerAvailabilityService $availabilityService
    ) {
        $this->dropboxService = $dropboxService;
        $this->mailService = $mailService;
        $this->workflowService = $workflowService;
        $this->activityLogger = $activityLogger;
        $this->taxService = $taxService;
        $this->availabilityService = $availabilityService;
    }

    public function index(Request $request)
    {
        try {
            // Increase memory limit for this operation
            ini_set('memory_limit', '256M');
            
            $user = auth()->user();
            $tab = strtolower($request->query('tab', 'scheduled'));

            // Optimize eager loading - only load necessary file columns to reduce memory usage
            $query = Shoot::with([
                'client:id,name,email,company_name,phonenumber',
                'photographer:id,name,avatar',
                'service:id,name',
                'services:id,name',
                'files' => function ($query) {
                    // Only load essential file columns for media summary
                    $query->select('id', 'shoot_id', 'workflow_stage', 'is_favorite', 'is_cover', 'flag_reason', 'url', 'path', 'dropbox_path');
                },
                'payments:id,shoot_id,amount,paid_at,status'
            ]);

            // Filter based on user role
            if ($user && $user->role === 'photographer') {
                $query->where('photographer_id', $user->id);
            } elseif ($user && $user->role === 'client') {
                $query->where('client_id', $user->id);
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

            // Process shoots in chunks to reduce memory usage
            // Transform and collect results without keeping all in memory at once
            $shoots = collect();
            $totalCount = 0;
            
            $query->chunk(50, function ($chunk) use (&$shoots, &$totalCount) {
                $transformed = $chunk->map(function ($shoot) {
                    return $this->transformShoot($shoot);
                });
                $shoots = $shoots->merge($transformed);
                $totalCount += $chunk->count();
                // Clear chunk from memory
                unset($chunk, $transformed);
            });

            return response()->json([
                'data' => $shoots->values()->all(),
                'meta' => [
                    'tab' => $tab,
                    'count' => $shoots->count(),
                    'filters' => $this->buildOperationalFilterMeta($shoots),
                ],
            ]);
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

        // Use new status field if available, fallback to workflow_status
        if ($tabKey === 'hold') {
            $query->where(function (Builder $scope) {
                $scope->where('status', ShootWorkflowService::STATUS_HOLD_ON)
                    ->orWhereNull('scheduled_at')
                    ->orWhereNull('scheduled_date')
                    ->orWhereIn(DB::raw('LOWER(workflow_status)'), array_map('strtolower', self::TAB_STATUS_MAP['hold']));
            });
            return;
        }

        // Map tab to new status values
        $statusMap = [
            'scheduled' => [
                ShootWorkflowService::STATUS_SCHEDULED,
                ShootWorkflowService::STATUS_IN_PROGRESS,
            ],
            'completed' => [
                ShootWorkflowService::STATUS_COMPLETED,
            ],
        ];

        if (isset($statusMap[$tabKey])) {
            $query->whereIn('status', $statusMap[$tabKey]);
        } else {
            // Fallback to workflow_status for backward compatibility
        $statuses = array_map('strtolower', self::TAB_STATUS_MAP[$tabKey]);
        $query->where(function (Builder $scope) use ($statuses) {
            $scope->whereIn(DB::raw('LOWER(workflow_status)'), $statuses);
        });
        }
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
            $column = $tab === 'completed' ? 'admin_verified_at' : 'scheduled_date';
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
            case 'completed':
                return [
                    ['raw' => 'COALESCE(admin_verified_at, editing_completed_at, scheduled_date) DESC'],
                ];
            case 'hold':
                return [
                    ['column' => 'created_at', 'direction' => 'desc'],
                ];
            default:
                return [
                    ['column' => 'scheduled_date', 'direction' => 'asc'],
                    ['column' => 'time', 'direction' => 'asc'],
                ];
        }
    }

    protected function buildOperationalFilterMeta(Collection $shoots): array
    {
        $clients = $shoots->pluck('client')->filter()->unique(function ($client) {
            return is_array($client) ? ($client['id'] ?? null) : ($client->id ?? null);
        })->map(function ($client) {
            if (is_array($client)) {
                return [
                    'id' => $client['id'] ?? null,
                    'name' => $client['name'] ?? 'Unknown',
                ];
            }

            return [
                'id' => $client->id ?? null,
                'name' => $client->name ?? 'Unknown',
            ];
        })->values();

        $photographers = $shoots->pluck('photographer')->filter()->unique(function ($photographer) {
            return is_array($photographer) ? ($photographer['id'] ?? null) : ($photographer->id ?? null);
        })->map(function ($photographer) {
            if (is_array($photographer)) {
                return [
                    'id' => $photographer['id'] ?? null,
                    'name' => $photographer['name'] ?? 'Unknown',
                ];
            }

            return [
                'id' => $photographer->id ?? null,
                'name' => $photographer->name ?? 'Unknown',
            ];
        })->values();

        $services = $shoots->flatMap(function ($shoot) {
            $serviceCollection = collect($shoot->services ?? []);
            return $serviceCollection->pluck('name');
        })->filter()->unique()->values();

        return [
            'clients' => $clients,
            'photographers' => $photographers,
            'services' => $services,
        ];
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
            if (!$this->userCanViewHistory($user)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $groupBy = strtolower($request->query('group_by', 'shoot'));
            $perPage = (int) min(200, max(10, (int) $request->query('per_page', 25)));

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

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $this->historyCsvHeaders());

            foreach ($rows as $row) {
                fputcsv($handle, $this->buildHistoryCsvRow($row));
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

    protected function historyCsvHeaders(): array
    {
        return [
            'Scheduled Date',
            'Completed Date',
            'Client Name',
            'Client Email Address',
            'Client Phone Number',
            'Company Name',
            'Total Number of Shoots',
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
        ];
    }

    protected function buildHistoryCsvRow(array $record): array
    {
        $client = $record['client'] ?? [];
        $address = $record['address']['full'] ?? '';
        $photographer = $record['photographer']['name'] ?? '';
        $services = implode(' | ', $record['services'] ?? []);
        $financials = $record['financials'] ?? [];
        $notes = $record['notes'] ?? [];

        return [
            $record['scheduledDate'] ?? '',
            $record['completedDate'] ?? '',
            $client['name'] ?? '',
            $client['email'] ?? '',
            $client['phone'] ?? '',
            $client['company'] ?? '',
            $client['totalShoots'] ?? 0,
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
        ];
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

        return DB::transaction(function () use ($validated, $user) {
            // 1. Calculate base quote from services
            $baseQuote = $this->calculateBaseQuote($validated['services']);

            // 2. Determine tax region and calculate tax
            $taxRegion = $validated['tax_region'] ?? $this->taxService->determineTaxRegion($validated['state']);
            $taxCalculation = $this->taxService->calculateTotal($baseQuote, $taxRegion);

            // 3. Get client's rep if not provided
            $repId = $validated['rep_id'] ?? $this->getClientRep($validated['client_id']);

            // 4. Determine initial status
            $scheduledAt = $validated['scheduled_at'] ? new \DateTime($validated['scheduled_at']) : null;
            $initialStatus = $scheduledAt 
                ? ShootWorkflowService::STATUS_SCHEDULED 
                : ShootWorkflowService::STATUS_HOLD_ON;

            // 5. Check photographer availability if scheduled
            if ($validated['photographer_id'] && $scheduledAt) {
                $this->checkPhotographerAvailability($validated['photographer_id'], $scheduledAt);
            }

            // 6. Create shoot
            $shoot = Shoot::create([
                'client_id' => $validated['client_id'],
                'rep_id' => $repId,
                'photographer_id' => $validated['photographer_id'] ?? null,
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
                'workflow_status' => Shoot::WORKFLOW_BOOKED,
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
        ]);

            // 7. Attach services
            $this->attachServices($shoot, $validated['services']);

            // 8. Create notes if provided
            $this->createNotes($shoot, $validated, $user);

            // 9. Initialize workflow state
            if ($scheduledAt) {
                $this->workflowService->schedule($shoot, $scheduledAt, $user);
            }

            // 10. Log activity
            $this->activityLogger->log(
                $shoot,
                'shoot_created',
                [
                    'by' => $user->name,
                    'status' => $initialStatus,
                    'scheduled_at' => $scheduledAt?->toIso8601String(),
                ],
                $user
            );

            // 11. Create Dropbox folders if scheduled
            if ($scheduledAt) {
                $this->dropboxService->createShootFolders($shoot);
            }

            // 12. Dispatch notification job (async)
            // TODO: Create SendShootBookedNotifications job
            // dispatch(new SendShootBookedNotifications($shoot));

            return response()->json([
                'message' => 'Shoot created successfully',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services', 'notes']))
            ], 201);
        });
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
     * @throws ValidationException
     */
    protected function checkPhotographerAvailability(int $photographerId, \DateTime $scheduledAt): void
    {
        $carbonDate = \Carbon\Carbon::parse($scheduledAt);
        
        // Use availability service to check
        if (!$this->availabilityService->isAvailable($photographerId, $carbonDate)) {
            $validator = \Illuminate\Support\Facades\Validator::make([], []);
            $validator->errors()->add('photographer_id', 'Photographer is not available at the selected time.');
            throw new ValidationException($validator);
        }
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

            // Check photographer availability if photographer_id is provided
            $photographerId = $validated['photographer_id'] ?? $shoot->photographer_id;
            if ($photographerId) {
                $this->checkPhotographerAvailability($photographerId, $scheduledAt);
                
                // Update photographer if different
                if ($photographerId !== $shoot->photographer_id) {
                    $shoot->photographer_id = $photographerId;
                    $shoot->save();
                }
            }

            $this->workflowService->schedule($shoot, $scheduledAt, $user);

            // Create Dropbox folders if not already created
            if (!$shoot->dropbox_raw_folder) {
                $this->dropboxService->createShootFolders($shoot);
            }
            
            return response()->json([
                'message' => 'Shoot scheduled successfully',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
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
        if (!in_array($user->role, ['admin', 'superadmin', 'super_admin', 'photographer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
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
        if (!in_array($user->role, ['admin', 'superadmin', 'super_admin', 'editor'])) {
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
        if (!in_array($user->role, ['admin', 'superadmin', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->workflowService->markCompleted($shoot, $user);

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

        // Admin, super admin, or assigned photographer can put on hold
        if ($user->role === 'photographer' && $shoot->photographer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($user->role, ['admin', 'superadmin', 'super_admin', 'photographer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $reason = $request->input('reason');

        try {
            $this->workflowService->putOnHold($shoot, $user, $reason);

            return response()->json([
                'message' => 'Shoot put on hold',
                'data' => new ShootResource($shoot->load(['client', 'rep', 'photographer', 'services']))
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Minimal update endpoint: allow admins to update status and dates.
     */
    public function update(Request $request, $shootId)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin','superadmin','super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => 'nullable|string|in:booked,scheduled,completed,on_hold,cancelled',
            'workflow_status' => 'nullable|string|in:booked,photos_uploaded,editing_complete,pending_review,on_hold,admin_verified,completed',
            'scheduled_date' => 'nullable|date',
            'time' => 'nullable|string',
            'services' => 'nullable|array',
            'services.*.id' => 'required_with:services|integer|exists:services,id',
            'services.*.price' => 'nullable|numeric|min:0',
            'services.*.quantity' => 'nullable|integer|min:1',
        ]);

        $shoot = Shoot::findOrFail($shootId);

        if (array_key_exists('status', $validated)) {
            $shoot->status = $validated['status'];
        }
        // If marking completed via either status or workflow_status, stamp admin_verified_at
        $markCompleted = false;
        if (array_key_exists('scheduled_date', $validated)) {
            $shoot->scheduled_date = $validated['scheduled_date'];
        }
        if (array_key_exists('time', $validated)) {
            $shoot->time = $validated['time'];
        }

        if (array_key_exists('workflow_status', $validated)) {
            $shoot->workflow_status = $validated['workflow_status'];
            if ($validated['workflow_status'] === 'completed' || $validated['workflow_status'] === 'admin_verified') {
                $markCompleted = true;
            }
        }

        if (array_key_exists('status', $validated) && $validated['status'] === 'completed') {
            $markCompleted = true;
        }

        // Update services if provided
        if (array_key_exists('services', $validated) && is_array($validated['services'])) {
            $this->attachServices($shoot, $validated['services']);
        }

        $shoot->save();

        if ($markCompleted) {
            // Set admin_verified_at if not already set
            if (empty($shoot->admin_verified_at)) {
                $shoot->admin_verified_at = now();
                $shoot->save();
            }
            // Ensure workflow_status reflects completion
            if ($shoot->workflow_status !== 'completed') {
                $shoot->workflow_status = 'completed';
                $shoot->save();
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
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $shoot = Shoot::findOrFail($shootId);
        $shoot->delete();

        return response()->json([
            'message' => 'Shoot deleted successfully',
        ]);
    }

    public function uploadFiles(Request $request, $shootId)
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|file|max:1048576|mimes:jpeg,jpg,png,gif,mp4,mov,avi,raw,cr2,nef,arw,tiff,bmp,heic,heif,zip',
            'service_category' => 'nullable|string|in:P,iGuide,Video',
            'upload_type' => 'nullable|string|in:raw,edited',
        ]);

        $shoot = Shoot::findOrFail($shootId);
        $uploadType = $request->input('upload_type', 'raw');

        if ($uploadType === 'raw' && !$shoot->canUploadPhotos()) {
            return response()->json([
                'message' => 'Cannot upload raw files at this workflow stage',
                'current_status' => $shoot->workflow_status,
            ], 400);
        }

        if ($uploadType === 'edited' && !in_array($shoot->workflow_status, [
            Shoot::WORKFLOW_RAW_UPLOADED,
            Shoot::WORKFLOW_EDITING,
            Shoot::WORKFLOW_EDITING_ISSUE,
            Shoot::WORKFLOW_EDITING_UPLOADED,
            Shoot::WORKFLOW_PENDING_REVIEW,
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
            foreach ($request->file('files') as $file) {
                try {
                    $serviceCategory = $request->input('service_category');

                    $shootFile = $uploadType === 'raw'
                        ? $this->dropboxService->uploadToTodo($shoot, $file, auth()->id(), $serviceCategory)
                        : $this->dropboxService->uploadToCompleted($shoot, $file, auth()->id(), $serviceCategory);

                    $uploadedFiles[] = [
                        'id' => $shootFile->id,
                        'filename' => $shootFile->filename,
                        'workflow_stage' => $shootFile->workflow_stage,
                        'dropbox_path' => $shootFile->dropbox_path,
                        'file_size' => $shootFile->file_size,
                        'uploaded_at' => $shootFile->created_at,
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
        if (!in_array(auth()->user()->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $file->verify(auth()->id(), $request->verification_notes);
            
            // Move to final folder and store on server
            $this->dropboxService->moveToFinal($file, auth()->id());
            
            // Check if all files are verified
            $unverifiedFiles = $shoot->files()->where('workflow_stage', '!=', ShootFile::STAGE_VERIFIED)->count();
            if ($unverifiedFiles === 0 && $shoot->workflow_status === Shoot::WORKFLOW_EDITING_UPLOADED) {
                $shoot->updateWorkflowStatus(Shoot::WORKFLOW_ADMIN_VERIFIED, auth()->id());
                
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
        $this->authorizeRole(['admin', 'super_admin', 'photographer', 'editor']);

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
        $this->authorizeRole(['admin', 'super_admin', 'editor', 'photographer']);

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

    public function deleteMedia(Shoot $shoot, ShootFile $file)
    {
        $this->authorizeFile($shoot, $file);
        $this->authorizeRole(['admin', 'super_admin', 'photographer', 'editor']);

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

        $this->authorizeRole(['admin', 'super_admin', 'photographer', 'editor']);

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
     */
    public function finalize(Request $request, $shootId)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin','superadmin','super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'final_status' => 'nullable|string|in:admin_verified,completed'
        ]);

        $shoot = Shoot::with(['files'])->findOrFail($shootId);
        $finalStatus = $request->input('final_status', Shoot::WORKFLOW_ADMIN_VERIFIED);

        $completedFiles = $shoot->files()->where('workflow_stage', ShootFile::STAGE_COMPLETED)->get();

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

            // Advance workflow status
            if ($finalStatus === Shoot::WORKFLOW_COMPLETED) {
                // Ensure admin_verified_at is set first
                if (empty($shoot->admin_verified_at)) {
                    $shoot->admin_verified_at = now();
                }
                $shoot->workflow_status = Shoot::WORKFLOW_COMPLETED;
            } else {
                $shoot->updateWorkflowStatus(Shoot::WORKFLOW_ADMIN_VERIFIED, $user->id);
            }
            $shoot->save();

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
     * Submit shoot for admin review (photographer endpoint)
     */
    public function submitForReview(Request $request, $shootId)
    {
        $user = auth()->user();
        $shoot = Shoot::findOrFail($shootId);

        // Check if user is the photographer assigned to this shoot
        if ($shoot->photographer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check if shoot is in a valid state to submit for review
        if (!in_array($shoot->workflow_status, [
            Shoot::WORKFLOW_RAW_UPLOADED,
            Shoot::WORKFLOW_EDITING_UPLOADED,
            Shoot::WORKFLOW_ON_HOLD
        ])) {
            return response()->json([
                'message' => 'Shoot cannot be submitted for review in current workflow status',
                'current_status' => $shoot->workflow_status
            ], 400);
        }

        // Update workflow status to pending_review
        $shoot->workflow_status = Shoot::WORKFLOW_PENDING_REVIEW;
        $shoot->submitted_for_review_at = now();
        // Clear any previous flags and issue notes if resubmitting after issues resolved
        if ($shoot->is_flagged) {
            $shoot->is_flagged = false;
            $shoot->admin_issue_notes = null;
        }
        $shoot->save();

        // Log the action
        $shoot->workflowLogs()->create([
            'user_id' => $user->id,
            'action' => 'submitted_for_review',
            'details' => 'Shoot submitted for admin review',
            'metadata' => [
                'timestamp' => now()->toISOString()
            ]
        ]);

        return response()->json([
            'message' => 'Shoot submitted for review',
            'data' => $shoot->fresh(['client','photographer','service','files'])
        ]);
    }

    /**
     * Approve shoot (admin endpoint)
     */
    public function approveShoot(Request $request, $shootId)
    {
        $user = auth()->user();
        if (!in_array($user->role, ['admin','superadmin','super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $shoot = Shoot::findOrFail($shootId);

        // Check if shoot is pending review
        if ($shoot->workflow_status !== Shoot::WORKFLOW_PENDING_REVIEW) {
            return response()->json([
                'message' => 'Shoot is not pending review',
                'current_status' => $shoot->workflow_status
            ], 400);
        }

        // Approve the shoot
        $shoot->workflow_status = Shoot::WORKFLOW_ADMIN_VERIFIED;
        $shoot->admin_verified_at = now();
        $shoot->verified_by = $user->id;
        $shoot->is_flagged = false;
        $shoot->admin_issue_notes = null;
        $shoot->save();

        // Log the action
        $shoot->workflowLogs()->create([
            'user_id' => $user->id,
            'action' => 'shoot_approved',
            'details' => 'Shoot approved by admin',
            'metadata' => [
                'approved_by' => $user->id,
                'timestamp' => now()->toISOString()
            ]
        ]);

        return response()->json([
            'message' => 'Shoot approved successfully',
            'data' => $shoot->fresh(['client','photographer','service','files'])
        ]);
    }

    /**
     * Reject shoot / Put on hold with issue notes (admin endpoint)
     */
    public function rejectShoot(Request $request, $shootId)
    {
        $user = auth()->user();
        if (!in_array($user->role, ['admin','superadmin','super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'admin_issue_notes' => 'required|string',
        ]);

        $shoot = Shoot::findOrFail($shootId);

        // Check if shoot is pending review
        if ($shoot->workflow_status !== Shoot::WORKFLOW_PENDING_REVIEW) {
            return response()->json([
                'message' => 'Shoot is not pending review',
                'current_status' => $shoot->workflow_status
            ], 400);
        }

        // Put shoot on hold with issue notes
        $shoot->workflow_status = Shoot::WORKFLOW_ON_HOLD;
        $shoot->is_flagged = true;
        $shoot->admin_issue_notes = $request->input('admin_issue_notes');
        $shoot->save();

        // Log the action
        $shoot->workflowLogs()->create([
            'user_id' => $user->id,
            'action' => 'shoot_rejected',
            'details' => 'Shoot put on hold with issues',
            'metadata' => [
                'rejected_by' => $user->id,
                'issue_notes' => $request->input('admin_issue_notes'),
                'timestamp' => now()->toISOString()
            ]
        ]);

        return response()->json([
            'message' => 'Shoot put on hold. Photographer has been notified.',
            'data' => $shoot->fresh(['client','photographer','service','files'])
        ]);
    }

    /**
     * Mark issues as resolved (photographer endpoint)
     */
    public function markIssuesResolved(Request $request, $shootId)
    {
        $user = auth()->user();
        $shoot = Shoot::findOrFail($shootId);

        // Check if user is the photographer assigned to this shoot
        if ($shoot->photographer_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check if shoot is on hold
        if ($shoot->workflow_status !== Shoot::WORKFLOW_ON_HOLD || !$shoot->is_flagged) {
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
        $shoot->workflow_status = Shoot::WORKFLOW_PENDING_REVIEW;
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
        if ($role === 'superadmin') { $role = 'super_admin'; }

        $request->validate([
            'shoot_notes' => 'nullable|string',
            'company_notes' => 'nullable|string',
            'photographer_notes' => 'nullable|string',
            'editor_notes' => 'nullable|string',
        ]);

        $allowed = [];
        if (in_array($role, ['admin', 'super_admin'])) {
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
        // Prefer verified files; if none, fall back to completed
        $files = $shoot->files;
        $verified = $files->where('workflow_stage', \App\Models\ShootFile::STAGE_VERIFIED);
        $completed = $files->where('workflow_stage', \App\Models\ShootFile::STAGE_COMPLETED);
        $chosen = $verified->count() > 0 ? $verified : $completed;

        $mapUrl = function($file) {
            $path = $file->path ?? '';
            if (!$path) return null;
            // If already an absolute URL, return as-is
            if (preg_match('/^https?:\/\//i', $path)) return $path;

            // Only expose files that exist on public disk to avoid returning Dropbox API paths
            $clean = ltrim($path, '/');
            // Normalize potential variants
            $publicRelative = str_starts_with($clean, 'storage/') ? substr($clean, 8) : $clean; // remove leading 'storage/'
            if (Storage::disk('public')->exists($publicRelative)) {
                $url = Storage::disk('public')->url($publicRelative);
                // Ensure absolute URL
                if (!preg_match('/^https?:\/\//i', $url)) {
                    $base = rtrim(config('app.url'), '/');
                    $url = $base . '/' . ltrim($url, '/');
                }
                return $url;
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

    public function publicBranded($shootId)
    {
        $shoot = \App\Models\Shoot::with(['files','client'])->findOrFail($shootId);
        $assets = $this->buildPublicAssets($shoot);
        $assets['type'] = 'branded';
        // Include integration data
        $assets['property_details'] = $shoot->property_details;
        $assets['iguide_tour_url'] = $shoot->iguide_tour_url;
        $assets['iguide_floorplans'] = $shoot->iguide_floorplans;
        return response()->json($assets);
    }

    public function publicMls($shootId)
    {
        $shoot = \App\Models\Shoot::with(['files','client'])->findOrFail($shootId);
        $assets = $this->buildPublicAssets($shoot);
        $assets['type'] = 'mls';
        // Include integration data
        $assets['property_details'] = $shoot->property_details;
        $assets['iguide_tour_url'] = $shoot->iguide_tour_url;
        $assets['iguide_floorplans'] = $shoot->iguide_floorplans;
        return response()->json($assets);
    }

    public function publicGenericMls($shootId)
    {
        $shoot = \App\Models\Shoot::with(['files','client'])->findOrFail($shootId);
        $assets = $this->buildPublicAssets($shoot);
        $assets['type'] = 'generic-mls';
        // Include integration data (no branding/address for generic MLS)
        $assets['property_details'] = $shoot->property_details;
        $assets['iguide_tour_url'] = $shoot->iguide_tour_url;
        $assets['iguide_floorplans'] = $shoot->iguide_floorplans;
        return response()->json($assets);
    }

    /**
     * Public client profile: basic client info and their shoots with previewable assets.
     * No auth required so links can be shared.
     */
    public function publicClientProfile($clientId)
    {
        $client = \App\Models\User::findOrFail($clientId);

        // Only include shoots that have at least one verified (finalized) file
        $shoots = Shoot::with(['files' => function($q) {
                $q->where('workflow_stage', \App\Models\ShootFile::STAGE_VERIFIED);
            }])
            ->where('client_id', $client->id)
            ->whereHas('files', function($q) {
                $q->where('workflow_stage', \App\Models\ShootFile::STAGE_VERIFIED);
            })
            ->orderByDesc('scheduled_date')
            ->get();

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

        $shootItems = $shoots->map(function ($s) use ($mapUrl) {
            $files = $s->files ?: collect();
            // Files are already filtered to verified in eager load, but keep guards
            $imageFile = $files->first(function ($f) { return str_starts_with(strtolower((string)$f->file_type), 'image/'); });
            $preview = $imageFile ? ($mapUrl($imageFile->path) ?: $mapUrl($imageFile->dropbox_path)) : null;

            return [
                'id' => $s->id,
                'address' => $s->address,
                'city' => $s->city,
                'state' => $s->state,
                'zip' => $s->zip,
                'scheduled_date' => optional($s->scheduled_date)->toDateString(),
                'files_count' => $files->count(),
                'preview_image' => $preview,
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
        $shoot->loadMissing(['client', 'photographer', 'service', 'services']);
        // Only load files if not already loaded (they should be from eager loading)
        if (!$shoot->relationLoaded('files')) {
            $shoot->load(['files' => function ($query) {
                $query->select('id', 'shoot_id', 'workflow_stage', 'is_favorite', 'is_cover', 'flag_reason', 'url', 'path', 'dropbox_path');
            }]);
        }
        $shoot->append('total_paid', 'remaining_balance');

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
        $cover = $shoot->files->firstWhere('is_cover', true);
        if ($cover) {
            return $this->resolveFileUrl($cover, $allowDropboxCalls);
        }

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

        // Return dropbox_path as-is if we're skipping API calls
        if ($file->dropbox_path && !$allowDropboxCalls) {
            return $file->dropbox_path;
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

        if (!in_array($user->role, ['admin', 'superadmin', 'super_admin', 'photographer'])) {
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

        if (!in_array($user->role, ['admin', 'superadmin', 'super_admin', 'photographer', 'editor'])) {
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
            'admin', 'superadmin', 'super_admin' => ['shoot', 'company', 'photographer', 'editing'],
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
        if (!in_array($user->role, ['admin', 'super_admin', 'superadmin'])) {
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
     * Mark shoot as paid (Super Admin only)
     * POST /api/shoots/{shoot}/mark-paid
     */
    public function markAsPaid(Request $request, Shoot $shoot)
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'payment_type' => 'nullable|string|in:manual,square,check,cash',
        ]);

        try {
            $amount = $validated['amount'] ?? $shoot->total_quote;
            $paymentType = $validated['payment_type'] ?? 'manual';

            // Create payment record
            $payment = Payment::create([
                'shoot_id' => $shoot->id,
                'amount' => $amount,
                'currency' => 'USD',
                'status' => Payment::STATUS_COMPLETED,
                'payment_method' => $paymentType,
                'processed_at' => now(),
            ]);

            // Calculate new total paid
            $totalPaid = $shoot->payments()
                ->where('status', Payment::STATUS_COMPLETED)
                ->sum('amount');

            // Update shoot payment status
            $oldPaymentStatus = $shoot->payment_status;
            $newPaymentStatus = $this->calculatePaymentStatus($totalPaid, $shoot->total_quote);
            $shoot->payment_status = $newPaymentStatus;
            $shoot->save();

            // Log activity
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
                    'marked_by' => auth()->user()->name,
                ],
                auth()->user()
            );

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
            ]);
            return response()->json(['error' => 'Failed to mark shoot as paid'], 500);
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
}
