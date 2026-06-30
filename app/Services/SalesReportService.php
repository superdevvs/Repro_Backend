<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SalesReportService
{
    private const SALES_REP_ROLES = ['salesRep', 'sales_rep', 'salesrep'];

    /**
     * Generate summary insights for a sales rep over a custom window.
     */
    public function generateSummaryForSalesRep(User $salesRep, Carbon $startDate, Carbon $endDate): array
    {
        $startDate = $startDate->copy()->startOfDay();
        $endDate = $endDate->copy()->endOfDay();
        $daysWindow = $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1;

        $repShoots = Shoot::query()
            ->with(['client:id,name,created_at,created_by_id,metadata'])
            ->where('rep_id', $salesRep->id)
            ->get();

        $clientScope = $this->buildSalesRepClientScope($salesRep, $repShoots);
        $clientIds = $clientScope->keys()->all();
        $repShootIds = $repShoots->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        $repInvoices = Invoice::query()
            ->with([
                'shoot:id,client_id,rep_id,scheduled_date,created_at',
                'shoots:id,client_id,rep_id,scheduled_date,created_at',
            ])
            ->where(function ($query) use ($salesRep, $clientIds, $repShootIds) {
                $query->where('sales_rep_id', $salesRep->id);

                if (!empty($clientIds)) {
                    $query->orWhereIn('client_id', $clientIds);
                }

                if (!empty($repShootIds)) {
                    $query->orWhereIn('shoot_id', $repShootIds)
                        ->orWhereHas('shoots', fn ($shootQuery) => $shootQuery->whereIn('shoots.id', $repShootIds));
                }
            })
            ->get()
            ->unique('id')
            ->values();

        $windowedPaidInvoices = $repInvoices
            ->filter(function (Invoice $invoice) use ($startDate, $endDate) {
                $paidAt = $invoice->paid_at instanceof Carbon
                    ? $invoice->paid_at
                    : ($invoice->paid_at ? Carbon::parse((string) $invoice->paid_at) : null);

                return $this->isDateWithinWindow($paidAt, $startDate, $endDate);
            })
            ->filter(fn (Invoice $invoice) => $this->resolveInvoiceClientId($invoice) !== null)
            ->values();

        $paidRevenueByClient = $windowedPaidInvoices
            ->groupBy(fn (Invoice $invoice) => (string) $this->resolveInvoiceClientId($invoice))
            ->map(fn (Collection $clientInvoices) => round(
                $clientInvoices->sum(fn (Invoice $invoice) => $this->resolveInvoicePaidAmount($invoice)),
                2,
            ));

        $paidRevenue = round($paidRevenueByClient->sum(), 2);
        $activeClientCount = $paidRevenueByClient->filter(fn (float $value) => $value > 0)->count();
        $commissionRate = $this->extractCommissionRate($salesRep);
        $commissionEarned = $commissionRate !== null
            ? round(($paidRevenue * $commissionRate) / 100, 2)
            : null;
        $averageClientValue = $activeClientCount > 0
            ? round($paidRevenue / $activeClientCount, 2)
            : 0.0;

        $currentOutstandingByClient = $repInvoices
            ->groupBy(fn (Invoice $invoice) => (string) ($this->resolveInvoiceClientId($invoice) ?? '__missing__'))
            ->map(function (Collection $clientInvoices, string $clientId) {
                if ($clientId === '__missing__') {
                    return 0.0;
                }

                return round(
                    $clientInvoices->sum(fn (Invoice $invoice) => $this->resolveOutstandingBalance($invoice)),
                    2,
                );
            });

        $newClientsInWindow = $clientScope
            ->filter(fn (array $client) => $this->isDateWithinWindow($client['first_known_relationship_at'] ?? null, $startDate, $endDate))
            ->values();

        $topClients = $paidRevenueByClient
            ->filter(fn (float $value) => $value > 0)
            ->sortDesc()
            ->take(5)
            ->map(function (float $clientRevenue, string $clientId) use ($clientScope, $currentOutstandingByClient) {
                $client = $clientScope->get($clientId);
                if (!$client) {
                    return null;
                }

                return [
                    'client_id' => $client['client_id'],
                    'client_name' => $client['client_name'],
                    'paid_revenue' => round($clientRevenue, 2),
                    'outstanding_balance' => round((float) ($currentOutstandingByClient->get($clientId, 0.0) ?? 0.0), 2),
                    'last_shoot_date' => $this->formatOptionalDate($client['last_shoot_date'] ?? null),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $newClients = $newClientsInWindow
            ->sortByDesc(fn (array $client) => $client['first_known_relationship_at']?->getTimestamp() ?? 0)
            ->take(5)
            ->map(function (array $client) use ($paidRevenueByClient, $currentOutstandingByClient) {
                $clientId = (string) $client['client_id'];

                return [
                    'client_id' => $client['client_id'],
                    'client_name' => $client['client_name'],
                    'created_at' => $this->formatOptionalDate($client['first_known_relationship_at'] ?? null),
                    'last_shoot_date' => $this->formatOptionalDate($client['last_shoot_date'] ?? null),
                    'paid_revenue' => round((float) ($paidRevenueByClient->get($clientId, 0.0) ?? 0.0), 2),
                    'outstanding_balance' => round((float) ($currentOutstandingByClient->get($clientId, 0.0) ?? 0.0), 2),
                ];
            })
            ->values()
            ->all();

        return [
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'days_window' => $daysWindow,
            ],
            'summary' => [
                'new_clients' => $newClientsInWindow->count(),
                'paid_revenue' => $paidRevenue,
                'commission_rate' => $commissionRate,
                'commission_earned' => $commissionEarned,
                'average_client_value' => $averageClientValue,
            ],
            'trend' => $this->buildSalesSummaryTrend(
                $startDate,
                $endDate,
                $daysWindow,
                $newClientsInWindow,
                $windowedPaidInvoices,
            ),
            'top_clients' => $topClients,
            'new_clients' => $newClients,
        ];
    }

    /**
     * Build the rep hit list for clients with no recent orders.
     */
    public function generateInactiveClientsForSalesRep(User $salesRep, int $days = 90): array
    {
        $days = max(1, min($days, 730));
        $cutoff = now()->subDays($days)->startOfDay();

        $repShoots = Shoot::query()
            ->with(['client:id,name,email,created_at,created_by_id,metadata'])
            ->where('rep_id', $salesRep->id)
            ->get();

        $clientScope = $this->buildSalesRepClientScope($salesRep, $repShoots);

        $clients = $clientScope
            ->filter(function (array $client) use ($cutoff) {
                $lastShootDate = $client['last_shoot_date'] ?? null;

                return !$lastShootDate || $lastShootDate->lte($cutoff);
            })
            ->sortBy(function (array $client) {
                $lastShootDate = $client['last_shoot_date'] ?? null;

                return $lastShootDate?->getTimestamp() ?? 0;
            })
            ->map(function (array $client) use ($cutoff) {
                $lastShootDate = $client['last_shoot_date'] ?? null;

                return [
                    'client_id' => $client['client_id'],
                    'client_name' => $client['client_name'],
                    'first_known_relationship_at' => $this->formatOptionalDate($client['first_known_relationship_at'] ?? null),
                    'last_shoot_date' => $this->formatOptionalDate($lastShootDate),
                    'days_since_last_shoot' => $lastShootDate ? $lastShootDate->diffInDays(now()->startOfDay()) : null,
                    'reason' => $lastShootDate
                        ? 'No shoot since ' . $cutoff->toDateString()
                        : 'No completed shoot found',
                ];
            })
            ->values()
            ->all();

        return [
            'cutoff_days' => $days,
            'cutoff_date' => $cutoff->toDateString(),
            'total' => count($clients),
            'clients' => $clients,
        ];
    }

    /**
     * Generate weekly sales report for a sales rep
     */
    public function generateWeeklyReportForSalesRep(User $salesRep, Carbon $startDate, Carbon $endDate): array
    {
        $startDate = $startDate->copy()->startOfDay();
        $endDate = $endDate->copy()->endOfDay();

        // Get all shoots assigned to this sales rep in the period
        $shoots = Shoot::with(['client', 'photographer', 'payments'])
            ->where('rep_id', $salesRep->id)
            ->whereBetween('scheduled_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get();

        // Calculate statistics
        $totalShoots = $shoots->count();
        $completedShoots = $shoots->where('workflow_status', Shoot::WORKFLOW_COMPLETED)->count();
        $totalRevenue = $shoots->sum('total_quote');
        $totalPaid = $shoots->flatMap(fn($shoot) => $shoot->payments)
            ->where('status', Payment::STATUS_COMPLETED)
            ->sum('amount');
        $outstandingBalance = $totalRevenue - $totalPaid;

        // Group by client
        $clients = $shoots->groupBy('client_id')->map(function ($clientShoots, $clientId) {
            $client = $clientShoots->first()->client;
            return [
                'client_id' => $clientId,
                'client_name' => $client ? $client->name : 'Unknown',
                'client_email' => $client ? $client->email : null,
                'shoot_count' => $clientShoots->count(),
                'total_revenue' => $clientShoots->sum('total_quote'),
                'total_paid' => $clientShoots->flatMap(fn($s) => $s->payments)
                    ->where('status', Payment::STATUS_COMPLETED)
                    ->sum('amount'),
            ];
        })->values();

        // Group by photographer
        $photographers = $shoots->whereNotNull('photographer_id')
            ->groupBy('photographer_id')
            ->map(function ($photographerShoots, $photographerId) {
                $photographer = $photographerShoots->first()->photographer;
                return [
                    'photographer_id' => $photographerId,
                    'photographer_name' => $photographer ? $photographer->name : 'Unknown',
                    'shoot_count' => $photographerShoots->count(),
                ];
            })->values();

        // Top performing shoots by revenue
        $topShoots = $shoots->sortByDesc('total_quote')
            ->take(10)
            ->map(function ($shoot) {
                return [
                    'shoot_id' => $shoot->id,
                    'client_name' => $shoot->client ? $shoot->client->name : 'Unknown',
                    'scheduled_date' => $shoot->scheduled_date ? $shoot->scheduled_date->format('Y-m-d') : null,
                    'total_quote' => $shoot->total_quote,
                    'workflow_status' => $shoot->workflow_status,
                ];
            })
            ->values();

        return [
            'sales_rep' => [
                'id' => $salesRep->id,
                'name' => $salesRep->name,
                'email' => $salesRep->email,
            ],
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
                'week_number' => $startDate->week,
                'year' => $startDate->year,
            ],
            'summary' => [
                'total_shoots' => $totalShoots,
                'completed_shoots' => $completedShoots,
                'completion_rate' => $totalShoots > 0 ? round(($completedShoots / $totalShoots) * 100, 2) : 0,
                'total_revenue' => round($totalRevenue, 2),
                'total_paid' => round($totalPaid, 2),
                'outstanding_balance' => round($outstandingBalance, 2),
                'average_shoot_value' => $totalShoots > 0 ? round($totalRevenue / $totalShoots, 2) : 0,
            ],
            'clients' => $clients,
            'photographers' => $photographers,
            'top_shoots' => $topShoots,
        ];
    }

    /**
     * Generate weekly sales report for all sales reps
     */
    public function generateWeeklyReportsForAllSalesReps(Carbon $startDate, Carbon $endDate): Collection
    {
        $salesReps = User::query()
            ->where(function ($query) {
                $query->whereIn('role', self::SALES_REP_ROLES);

                foreach (self::SALES_REP_ROLES as $role) {
                    $query->orWhereJsonContains('secondary_roles', $role);
                }
            })
            ->whereNotNull('email')
            ->get()
            ->unique('id')
            ->values();

        return $salesReps->map(function ($salesRep) use ($startDate, $endDate) {
            return $this->generateWeeklyReportForSalesRep($salesRep, $startDate, $endDate);
        });
    }

    public function isSalesRep(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) $user->role);
        if (in_array($role, array_map('strtolower', self::SALES_REP_ROLES), true)) {
            return true;
        }

        $secondaryRoles = is_array($user->secondary_roles) ? $user->secondary_roles : [];
        return collect($secondaryRoles)
            ->map(fn ($item) => strtolower((string) $item))
            ->intersect(array_map('strtolower', self::SALES_REP_ROLES))
            ->isNotEmpty();
    }

    /**
     * Get last completed week dates
     */
    public function getLastCompletedWeek(): array
    {
        $end = now()->startOfWeek()->subDay()->endOfDay();
        $start = $end->copy()->startOfWeek();

        return [$start, $end];
    }

    protected function buildSalesRepClientScope(User $salesRep, Collection $repShoots): Collection
    {
        $clientScope = collect();
        $repId = (string) $salesRep->id;
        $shootsByClient = $repShoots
            ->filter(fn (Shoot $shoot) => $shoot->client_id !== null && $shoot->client)
            ->groupBy(fn (Shoot $shoot) => (string) $shoot->client_id);

        foreach ($shootsByClient as $clientId => $clientShoots) {
            /** @var Shoot|null $firstShoot */
            $firstShoot = $clientShoots->first();
            $client = $firstShoot?->client;

            if (!$client) {
                continue;
            }

            $this->mergeScopedClient(
                $clientScope,
                $client,
                $this->resolveEarliestShootDate($clientShoots),
                $this->resolveLatestShootDate($clientShoots),
            );
        }

        $createdClients = User::query()
            ->where('role', 'client')
            ->where('created_by_id', $salesRep->id)
            ->get();

        foreach ($createdClients as $client) {
            $shootDates = $shootsByClient->get((string) $client->id, collect());
            $this->mergeScopedClient(
                $clientScope,
                $client,
                $this->toCarbonDate($client->created_at),
                $this->resolveLatestShootDate($shootDates),
            );
        }

        $metadataClients = User::query()
            ->where('role', 'client')
            ->whereNotNull('metadata')
            ->get()
            ->filter(fn (User $client) => $this->extractMetadataSalesRepId($client) === $repId);

        foreach ($metadataClients as $client) {
            $shootDates = $shootsByClient->get((string) $client->id, collect());
            $this->mergeScopedClient(
                $clientScope,
                $client,
                $this->toCarbonDate($client->created_at),
                $this->resolveLatestShootDate($shootDates),
            );
        }

        return $clientScope;
    }

    protected function mergeScopedClient(Collection $clientScope, User $client, ?Carbon $relationshipDate, ?Carbon $lastShootDate): void
    {
        if (!$relationshipDate && !$lastShootDate) {
            return;
        }

        $clientId = (string) $client->id;
        $existing = $clientScope->get($clientId);
        $effectiveRelationshipDate = $relationshipDate?->copy();

        if ($existing) {
            $existingRelationshipDate = $existing['first_known_relationship_at'] ?? null;
            if ($existingRelationshipDate && $effectiveRelationshipDate) {
                $effectiveRelationshipDate = $existingRelationshipDate->lte($effectiveRelationshipDate)
                    ? $existingRelationshipDate
                    : $effectiveRelationshipDate;
            } elseif ($existingRelationshipDate) {
                $effectiveRelationshipDate = $existingRelationshipDate;
            }

            $existingLastShoot = $existing['last_shoot_date'] ?? null;
            if ($existingLastShoot && $lastShootDate) {
                $lastShootDate = $existingLastShoot->gte($lastShootDate)
                    ? $existingLastShoot
                    : $lastShootDate;
            } elseif ($existingLastShoot) {
                $lastShootDate = $existingLastShoot;
            }
        }

        $clientScope->put($clientId, [
            'client_id' => $client->id,
            'client_name' => $client->name ?? 'Unknown Client',
            'first_known_relationship_at' => $effectiveRelationshipDate,
            'last_shoot_date' => $lastShootDate,
        ]);
    }

    protected function extractMetadataSalesRepId(User $client): ?string
    {
        $metadata = is_array($client->metadata) ? $client->metadata : [];
        $rawRepId = $metadata['accountRepId']
            ?? $metadata['account_rep_id']
            ?? $metadata['repId']
            ?? $metadata['rep_id']
            ?? null;

        if ($rawRepId === null || $rawRepId === '') {
            return null;
        }

        return (string) $rawRepId;
    }

    protected function resolveEarliestShootDate(Collection $shoots): ?Carbon
    {
        return $shoots
            ->map(fn (Shoot $shoot) => $this->resolveShootActivityDate($shoot))
            ->filter()
            ->sortBy(fn (Carbon $date) => $date->getTimestamp())
            ->first();
    }

    protected function resolveLatestShootDate(Collection $shoots): ?Carbon
    {
        return $shoots
            ->map(fn (Shoot $shoot) => $this->resolveShootActivityDate($shoot))
            ->filter()
            ->sortByDesc(fn (Carbon $date) => $date->getTimestamp())
            ->first();
    }

    protected function resolveShootActivityDate(Shoot $shoot): ?Carbon
    {
        if ($shoot->scheduled_date instanceof Carbon) {
            return $shoot->scheduled_date->copy()->startOfDay();
        }

        if ($shoot->scheduled_date) {
            return Carbon::parse((string) $shoot->scheduled_date)->startOfDay();
        }

        return $this->toCarbonDate($shoot->created_at);
    }

    protected function resolveInvoiceClientId(Invoice $invoice): ?string
    {
        if ($invoice->client_id !== null) {
            return (string) $invoice->client_id;
        }

        if ($invoice->shoot?->client_id !== null) {
            return (string) $invoice->shoot->client_id;
        }

        $shootClientIds = $invoice->shoots
            ->pluck('client_id')
            ->filter(fn ($clientId) => $clientId !== null)
            ->unique()
            ->values();

        if ($shootClientIds->count() === 1) {
            return (string) $shootClientIds->first();
        }

        return null;
    }

    protected function resolveInvoicePaidAmount(Invoice $invoice): float
    {
        return round((float) ($invoice->amount_paid ?? 0), 2);
    }

    protected function resolveOutstandingBalance(Invoice $invoice): float
    {
        $status = strtolower((string) ($invoice->status ?? ''));
        $isPaid = (bool) ($invoice->is_paid ?? false) || $status === Invoice::STATUS_PAID;

        if ($isPaid) {
            return 0.0;
        }

        if (method_exists($invoice, 'balanceDue')) {
            return round(max((float) $invoice->balanceDue(), 0), 2);
        }

        $explicitBalance = $invoice->getAttribute('balance_due');
        if ($explicitBalance !== null) {
            return round(max((float) $explicitBalance, 0), 2);
        }

        $total = (float) ($invoice->total ?? $invoice->total_amount ?? 0);
        $amountPaid = (float) ($invoice->amount_paid ?? 0);

        return round(max($total - $amountPaid, 0), 2);
    }

    protected function extractCommissionRate(User $salesRep): ?float
    {
        $metadata = is_array($salesRep->metadata) ? $salesRep->metadata : [];
        $repDetails = isset($metadata['repDetails']) && is_array($metadata['repDetails'])
            ? $metadata['repDetails']
            : [];

        $rawRate = $repDetails['commissionPercentage'] ?? null;
        if ($rawRate === null || $rawRate === '') {
            return null;
        }

        return round((float) $rawRate, 2);
    }

    protected function buildSalesSummaryTrend(
        Carbon $startDate,
        Carbon $endDate,
        int $daysWindow,
        Collection $newClientsInWindow,
        Collection $windowedPaidInvoices
    ): array {
        if ($newClientsInWindow->isEmpty() && $windowedPaidInvoices->isEmpty()) {
            return [];
        }

        $useMonthlyBuckets = $daysWindow >= 365;
        $bucketedData = collect();

        $cursor = $useMonthlyBuckets
            ? $startDate->copy()->startOfMonth()
            : $startDate->copy()->startOfWeek(Carbon::MONDAY);
        $lastBucket = $useMonthlyBuckets
            ? $endDate->copy()->startOfMonth()
            : $endDate->copy()->startOfWeek(Carbon::MONDAY);

        while ($cursor->lte($lastBucket)) {
            $bucketKey = $cursor->toDateString();
            $bucketedData->put($bucketKey, [
                'bucket' => $bucketKey,
                'paid_revenue' => 0.0,
                'new_clients' => 0,
            ]);

            $cursor = $useMonthlyBuckets
                ? $cursor->copy()->addMonth()
                : $cursor->copy()->addWeek();
        }

        foreach ($windowedPaidInvoices as $invoice) {
            if (!$invoice instanceof Invoice) {
                continue;
            }

            $paidAt = $invoice->paid_at instanceof Carbon
                ? $invoice->paid_at
                : ($invoice->paid_at ? Carbon::parse((string) $invoice->paid_at) : null);

            if (!$paidAt) {
                continue;
            }

            $bucketKey = $useMonthlyBuckets
                ? $paidAt->copy()->startOfMonth()->toDateString()
                : $paidAt->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

            if (!$bucketedData->has($bucketKey)) {
                continue;
            }

            $bucket = $bucketedData->get($bucketKey);
            $bucket['paid_revenue'] = round(
                (float) $bucket['paid_revenue'] + $this->resolveInvoicePaidAmount($invoice),
                2,
            );
            $bucketedData->put($bucketKey, $bucket);
        }

        foreach ($newClientsInWindow as $client) {
            $relationshipDate = $client['first_known_relationship_at'] ?? null;
            if (!$relationshipDate instanceof Carbon) {
                continue;
            }

            $bucketKey = $useMonthlyBuckets
                ? $relationshipDate->copy()->startOfMonth()->toDateString()
                : $relationshipDate->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

            if (!$bucketedData->has($bucketKey)) {
                continue;
            }

            $bucket = $bucketedData->get($bucketKey);
            $bucket['new_clients'] = (int) $bucket['new_clients'] + 1;
            $bucketedData->put($bucketKey, $bucket);
        }

        return $bucketedData->values()->all();
    }

    protected function isDateWithinWindow(?Carbon $date, Carbon $startDate, Carbon $endDate): bool
    {
        return $date instanceof Carbon && $date->betweenIncluded($startDate, $endDate);
    }

    protected function toCarbonDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if (!$value) {
            return null;
        }

        return Carbon::parse((string) $value)->startOfDay();
    }

    protected function formatOptionalDate(?Carbon $date): ?string
    {
        return $date?->copy()->toDateString();
    }
}


