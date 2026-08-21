<?php

namespace App\Services\Shoots;

use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShootHistoryService
{
    protected const HISTORY_ALLOWED_ROLES = [
        'admin',
        'superadmin',
        'editing_manager',
        'finance',
        'accounting',
        'editor',
        'client',
        'salesRep',
    ];

    public function history(Request $request, ?User $user): JsonResponse
    {
        try {
            $isImpersonating = $request->attributes->get('is_impersonating', false);

            Log::debug('History endpoint called', [
                'user_id' => $user?->id,
                'user_role' => $user?->role,
                'user_name' => $user?->name,
                'is_impersonating' => $isImpersonating,
                'impersonate_header' => $request->header('X-Impersonate-User-Id'),
            ]);

            if (! $this->userCanViewHistory($user)) {
                Log::warning('User cannot view history', ['user_id' => $user?->id, 'role' => $user?->role]);

                return response()->json(['message' => 'Forbidden'], 403);
            }

            $groupBy = strtolower($request->query('group_by', 'shoot'));
            $perPage = (int) min(200, max(9, (int) $request->query('per_page', 25)));

            $query = Shoot::with(['client', 'photographer', 'services', 'payments']);
            $this->applyHistoryFilters($query, $request, $user);

            if ($groupBy === 'services') {
                $aggregateQuery = Shoot::query();
                $this->applyHistoryFilters($aggregateQuery, $request, $user);
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

    public function exportHistory(Request $request, ?User $user): StreamedResponse
    {
        if (! $this->userCanViewHistory($user)) {
            abort(403, 'Forbidden');
        }

        $query = Shoot::with(['client', 'photographer', 'services', 'payments']);
        $this->applyHistoryFilters($query, $request, $user);

        $shoots = $query
            ->orderByRaw('COALESCE(admin_verified_at, editing_completed_at, scheduled_date, created_at) DESC')
            ->get();

        $clientCounts = $this->loadClientShootCounts($shoots);

        $rows = $shoots->map(function (Shoot $shoot) use ($clientCounts) {
            return $this->transformHistoryShoot($shoot, $clientCounts);
        });

        $filename = 'shoot-history-'.now()->format('Ymd-His').'.csv';
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
        if (! $user) {
            return false;
        }

        return in_array($user->role, self::HISTORY_ALLOWED_ROLES, true);
    }

    protected function applyHistoryFilters(Builder $query, Request $request, ?User $user): void
    {
        Log::debug('applyHistoryFilters', [
            'user_id' => $user?->id,
            'user_role' => $user?->role,
        ]);

        if ($user) {
            if ($user->role === 'client') {
                Log::debug('Filtering shoots for client', ['client_id' => $user->id]);
                $query->where('client_id', $user->id);
            } elseif ($user->role === 'salesRep') {
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
                app(ShootEditingAssignmentService::class)->scopeAssignedToEditor($query, $user->id);
            }
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $this->applySearchFilter($query, $search);
        }

        $clientIds = array_merge(
            $this->normalizeArrayQuery($request, 'client_id'),
            $this->normalizeArrayQuery($request, 'client_ids')
        );
        if (! empty($clientIds)) {
            $query->whereIn('client_id', $clientIds);
        }

        $photographerIds = array_merge(
            $this->normalizeArrayQuery($request, 'photographer_id'),
            $this->normalizeArrayQuery($request, 'photographer_ids')
        );
        if (! empty($photographerIds)) {
            $query->whereIn('photographer_id', $photographerIds);
        }

        $services = $this->normalizeArrayQuery($request, 'services');
        if (! empty($services)) {
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
        if ($user && $user->role === 'client') {
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
        $requestingRole = strtolower((string) (auth()->user()?->role ?? ''));
        $isEditor = $requestingRole === 'editor';
        $isClient = $requestingRole === 'client';
        $visibleServices = $isEditor
            ? app(ShootEditingAssignmentService::class)->filterServicesForEditor($shoot, auth()->user())
            : collect($shoot->services);
        $services = $visibleServices->pluck('name')->filter()->values()->all();
        $orderItems = $isEditor
            ? []
            : app(ShootServiceItemSupport::class)->summaries($shoot);

        $miscItems = collect($orderItems)
            ->filter(fn ($item) => (bool) ($item['is_invoice_adjustment'] ?? false))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
        $invoiceAdjustmentTotal = collect($orderItems)
            ->filter(fn ($item) => (bool) ($item['is_invoice_adjustment'] ?? false))
            ->sum(fn ($item) => (float) ($item['total_amount'] ?? $item['subtotal'] ?? 0));
        if (! empty($miscItems)) {
            $services = array_merge($services, $miscItems);
        }

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
                'id' => $isEditor ? null : ($shoot->photographer->id ?? null),
                'name' => $isEditor ? null : ($shoot->photographer->name ?? null),
            ],
            'services' => $services,
            'serviceItems' => $orderItems,
            'service_items' => $orderItems,
            'orderItems' => $orderItems,
            'order_items' => $orderItems,
            'financials' => [
                'baseQuote' => $isEditor ? 0.0 : (float) $shoot->base_quote,
                'taxPercent' => $isEditor ? 0.0 : $taxPercent,
                'taxAmount' => $isEditor ? 0.0 : (float) $shoot->tax_amount,
                'totalQuote' => $isEditor ? 0.0 : (float) $shoot->total_quote,
                'totalPaid' => $isEditor ? 0.0 : $payments['totalPaid'],
                'lastPaymentDate' => $isEditor ? null : $payments['lastPaymentDate'],
                'lastPaymentType' => $isEditor ? null : $shoot->payment_type,
                'invoiceAdjustmentsTotal' => $isEditor ? 0.0 : round($invoiceAdjustmentTotal, 2),
                'orderTotal' => $isEditor ? 0.0 : (float) $shoot->total_quote,
            ],
            'tourPurchased' => $this->determineTourPurchased($shoot),
            'notes' => [
                'shoot' => $isEditor ? null : ($shoot->shoot_notes ?? $shoot->notes),
                'photographer' => ($isEditor || $isClient) ? null : $shoot->photographer_notes,
                'company' => ($isEditor || $isClient) ? null : $shoot->company_notes,
                'editing' => $isClient ? null : $shoot->editor_notes,
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
            ->map(fn ($name) => strtolower($name))
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
