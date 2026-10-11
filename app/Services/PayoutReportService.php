<?php

namespace App\Services;

use App\Models\EditorPayout;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\User;
use App\Services\Shoots\ShootMutationSupportService;
use App\Support\ReportingWeek;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PayoutReportService
{
    private const SALES_REP_ROLES = ['salesRep', 'sales_rep', 'salesrep'];

    public function __construct(
        private readonly EditorPayoutService $editorPayoutService,
    ) {
    }

    public function lastCompletedWeekRange(): array
    {
        return ReportingWeek::lastCompleted();
    }

    /**
     * Build photographer summaries using SERVICE-LEVEL grouping
     * 
     * NEW LOGIC: Groups by resolved photographer per service, not per shoot
     * Fallback: shoot_service.photographer_id ?? shoot.photographer_id
     */
    public function buildPhotographerSummaries(Carbon $start, Carbon $end): Collection
    {
        $rows = app(\App\Services\Invoices\PhotographerPayoutReconciliation::class)->rows($start, $end);
        $compensations = ShootCompensation::where('recipient_type', ShootCompensation::RECIPIENT_PHOTOGRAPHER)
            ->whereDoesntHave('shoot', fn ($q) => $q->where('shoot_type', Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->whereNull('voided_at')->where('mode', '!=', ShootCompensation::MODE_NONE)
            ->whereBetween('earned_at', [$start, $end])->where('amount', '!=', 0)->get();
        $invoices = \App\Models\Invoice::with('items')
            ->where('role', \App\Models\Invoice::ROLE_PHOTOGRAPHER)
            ->whereDate('billing_period_start', '>=', $start->toDateString())
            ->whereDate('billing_period_end', '<=', $end->toDateString())
            ->whereNotIn('status', ['cancelled', 'void'])->get();
        $recipientIds = $rows->pluck('resolved_photographer_id')->merge($invoices->pluck('photographer_id'))->merge($compensations->pluck('recipient_user_id'))->unique();
        return $recipientIds->map(function ($id) use ($rows, $invoices, $compensations) {
            $user = User::find($id);
            if (!$user) return null;
            $recipientRows = $rows->where('resolved_photographer_id', $id);
            $recipientInvoices = $invoices->where('photographer_id', $id);
            $eligible = $recipientRows->where('eligible', true);
            $pending = $recipientRows->where('eligible', false)->whereNull('owner_invoice_id');
            $unallocated = $eligible->whereNull('owner_invoice_id');
            $items = $recipientInvoices->flatMap->items;
            $recipientCompensations = $compensations->where('recipient_user_id', $id);
            // Reviewed invoice lines (including corrections/reimbursements) win.
            // An unsaved dashboard preview is labelled as a calculation only.
            $gross = $recipientInvoices->isNotEmpty() ? $recipientInvoices->sum('total_amount') : $eligible->sum('photographer_pay') + $recipientCompensations->sum('amount');
            $count = $recipientInvoices->isNotEmpty() ? $items->where('type', 'charge')->pluck('shoot_id')->filter()->unique()->count() : $eligible->pluck('shoot_id')->merge($recipientCompensations->pluck('shoot_id'))->unique()->count();
            $public = fn ($r) => ['shoot_id'=>$r['shoot_id'], 'address'=>$r['address'], 'service_name'=>$r['service_name'], 'work_date'=>$r['scheduled_date']->toDateString(), 'amount'=>$r['photographer_pay'], 'reason'=>$r['pending_reason']];
            return [
                'id'=>$user->id, 'name'=>$user->name, 'email'=>$user->email, 'role'=>'photographer',
                'shoot_count'=>$count, 'service_count'=>$recipientInvoices->isNotEmpty() ? $items->where('type','charge')->count() : $eligible->count(),
                'gross_total'=>round($gross,2), 'average_value'=>round($gross/max($count,1),2),
                'commission_rate'=>null, 'commission_total'=>null,
                'compensation_total'=>round($recipientInvoices->isNotEmpty() ? $items->sum(fn($i)=>(float)data_get($i->meta,'compensation_amount',0)) : $recipientCompensations->sum('amount'),2),
                'payout_total'=>round($gross,2), 'invoice_ids'=>$recipientInvoices->pluck('id')->all(),
                'invoice_basis'=>$recipientInvoices->isNotEmpty() ? 'reconciled_invoices' : 'calculated_preview',
                'pending_count'=>$pending->pluck('shoot_id')->unique()->count(), 'pending_total'=>round($pending->sum('photographer_pay'),2),
                'pending_lines'=>$pending->map($public)->values()->all(),
                'unallocated_count'=>$recipientInvoices->isNotEmpty() ? $unallocated->count() : 0,
                'unallocated_total'=>$recipientInvoices->isNotEmpty() ? round($unallocated->sum('photographer_pay'),2) : 0,
                'late_count'=>$items->filter(fn($i)=>data_get($i->meta,'late_work',false))->count(),
                'invoice_lines'=>$items->filter(fn($i)=>$i->type!=='payment')->map(fn($i)=>['description'=>$i->description,'amount'=>(float)$i->total_amount,'work_date'=>$i->recorded_at?->toDateString()])->values()->all(),
            ];
        })->filter(fn($row)=>$row && ($row['shoot_count'] || $row['pending_count'] || abs($row['gross_total'])>=0.005))->values();
    }

    public function buildSalesRepSummaries(Carbon $start, Carbon $end): Collection
    {
        $shoots = Shoot::with([
                'service.sqftRanges',
                'services' => fn ($query) => $query->withPivot(['price', 'quantity']),
            ])
            ->excludeHistoricalImportsFromNewBilling()
            ->where(function ($query) {
                $query->whereNull('shoot_type')
                    ->orWhereNotIn('shoot_type', [Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT, Shoot::SHOOT_TYPE_INTERNAL_TEST]);
            })
            ->whereBetween('scheduled_date', [
                $start->copy()->startOfDay()->toDateTimeString(),
                $end->copy()->endOfDay()->toDateTimeString(),
            ])
            ->where('sales_rep_pay_enabled', true)
            ->whereNotIn('workflow_status', [
                Shoot::STATUS_ON_HOLD,
                Shoot::STATUS_CANCELLED,
                Shoot::STATUS_DECLINED,
            ])
            ->get();
        $shoots = $this->resolveMissingSalesReps($shoots);

        $compensations = ShootCompensation::query()
            ->whereDoesntHave('shoot', fn ($query) => $query->where('shoot_type', Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->where('recipient_type', ShootCompensation::RECIPIENT_SALES_REP)
            ->where('mode', '!=', ShootCompensation::MODE_NONE)
            ->whereNull('voided_at')
            ->whereNotNull('recipient_user_id')
            ->where('amount', '!=', 0)
            ->whereBetween('earned_at', [$start, $end])
            ->get();

        $repIds = $shoots->pluck('rep_id')
            ->merge($compensations->pluck('recipient_user_id'))
            ->filter()
            ->unique()
            ->values();

        return $repIds
            ->map(function ($repId) use ($shoots, $compensations) {
                $group = $shoots->where('rep_id', $repId)->values();
                $repCompensations = $compensations->where('recipient_user_id', $repId)->values();
                $rep = User::find($repId);
                if (! $this->isActiveSalesRep($rep)) {
                    return null;
                }

                $gross = (float) $group->sum(fn (Shoot $shoot) => $this->commissionableShootTotal($shoot));
                $commissionRate = (float) data_get($rep->metadata, 'repDetails.commissionPercentage', 15);
                $commissionTotal = $commissionRate > 0 ? round($gross * ($commissionRate / 100), 2) : null;
                $compensationTotal = round((float) $repCompensations->sum('amount'), 2);
                $shootCount = $group->pluck('id')
                    ->merge($repCompensations->pluck('shoot_id'))
                    ->unique()
                    ->count();

                return [
                    'id' => $rep->id,
                    'name' => $rep->name,
                    'email' => $rep->email,
                    'role' => 'salesRep',
                    'shoot_count' => $shootCount,
                    'gross_total' => round($gross, 2),
                    'average_value' => round($group->avg('total_quote') ?? 0, 2),
                    'commission_rate' => $commissionRate ?: null,
                    'commission_total' => $commissionTotal,
                    'compensation_total' => $compensationTotal,
                    'payout_total' => round((float) ($commissionTotal ?? 0) + $compensationTotal, 2),
                    'categories' => data_get($rep->metadata, 'repDetails.salesCategories', []),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Finance-facing complimentary-reshoot rollup. Nominal value is an audit
     * measure, never revenue or receivables; staff/editor rows are company
     * costs and remain outside the standard revenue/commission aggregates.
     */
    public function buildComplimentaryReshootSummary(Carbon $start, Carbon $end): array
    {
        $shoots = Shoot::query()
            ->with([
                'serviceItems.compReshootItem',
            ])
            ->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('scheduled_at', [$start, $end])
                    ->orWhere(function ($scheduledDateQuery) use ($start, $end) {
                        $scheduledDateQuery->whereNull('scheduled_at')
                            ->whereNotNull('scheduled_date')
                            ->whereBetween('scheduled_date', [
                                $start->toDateString(),
                                $end->toDateString(),
                            ]);
                    })
                    ->orWhere(function ($createdQuery) use ($start, $end) {
                        $createdQuery->whereNull('scheduled_at')
                            ->whereNull('scheduled_date')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->get();

        $nominalValue = round((float) $shoots->sum(function (Shoot $shoot) {
            return $shoot->serviceItems->sum(function ($serviceItem) {
                return (float) ($serviceItem->nominal_value_snapshot
                    ?? $serviceItem->compReshootItem?->nominal_total_snapshot
                    ?? 0);
            });
        }), 2);

        // Payout costs use the same earning-date basis as the recipient payout
        // sections. This keeps late corrections for old shoots in the current
        // payout report instead of silently rewriting the old scheduled period.
        $activeCompensations = ShootCompensation::query()
            ->whereHas('shoot', fn ($query) => $query
                ->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT))
            ->where('mode', '!=', ShootCompensation::MODE_NONE)
            ->whereNull('voided_at')
            ->where('amount', '!=', 0)
            ->whereBetween('earned_at', [$start, $end])
            ->get();
        $photographerCompensation = round((float) $activeCompensations
            ->where('recipient_type', ShootCompensation::RECIPIENT_PHOTOGRAPHER)
            ->sum('amount'), 2);
        $salesRepCompensation = round((float) $activeCompensations
            ->where('recipient_type', ShootCompensation::RECIPIENT_SALES_REP)
            ->sum('amount'), 2);
        $actualEditorCost = round((float) EditorPayout::query()
            ->whereHas('shoot', fn ($query) => $query
                ->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT))
            ->whereBetween('completed_at', [$start, $end])
            ->sum('payout_amount'), 2);
        $totalCompanyCost = round(
            $photographerCompensation + $salesRepCompensation + $actualEditorCost,
            2
        );

        return [
            'shoot_count' => $shoots->count(),
            'nominal_value_comped' => $nominalValue,
            'photographer_compensation' => $photographerCompensation,
            'sales_rep_compensation' => $salesRepCompensation,
            'actual_editor_cost' => $actualEditorCost,
            'total_company_comp_cost' => $totalCompanyCost,
            'revenue' => 0.0,
            'cash_collected' => 0.0,
            'accounts_receivable' => 0.0,
            'margin' => null,
            'margin_status' => 'not_applicable',
            'margin_display' => 'N/A',
            'nominal_period_basis' => 'shoot_schedule',
            'cost_period_basis' => 'earned_or_completed',
        ];
    }

    private function resolveMissingSalesReps(Collection $shoots): Collection
    {
        $support = app(ShootMutationSupportService::class);

        return $shoots->map(function (Shoot $shoot) use ($support) {
            if ($shoot->rep_id) {
                return $shoot;
            }

            $clientId = (int) ($shoot->client_id ?? 0);
            if ($clientId <= 0) {
                return $shoot;
            }

            $resolvedRepId = $support->getClientRep($clientId);
            if ($resolvedRepId) {
                $shoot->setAttribute('rep_id', $resolvedRepId);
            }

            return $shoot;
        });
    }

    private function isActiveSalesRep(?User $user): bool
    {
        if (! $user || ($user->account_status ?? 'active') !== 'active') {
            return false;
        }

        $normalizedRoles = array_map('strtolower', self::SALES_REP_ROLES);
        if (in_array(strtolower((string) $user->role), $normalizedRoles, true)) {
            return true;
        }

        $secondaryRoles = is_array($user->secondary_roles) ? $user->secondary_roles : [];

        return collect($secondaryRoles)
            ->map(fn ($role) => strtolower((string) $role))
            ->intersect($normalizedRoles)
            ->isNotEmpty();
    }

    private function commissionableShootTotal(Shoot $shoot): float
    {
        $services = $shoot->services;
        if ($services->isEmpty() && $shoot->service) {
            $services = collect([$shoot->service]);
        }

        return round((float) $services->sum(function ($service) use ($shoot) {
            if ($service->exclude_from_sales_commission ?? false) {
                return 0;
            }

            $quantity = max((int) data_get($service, 'pivot.quantity', 1), 1);
            $pivotPrice = data_get($service, 'pivot.price');
            if ($pivotPrice !== null && $pivotPrice !== '') {
                return (float) $pivotPrice * $quantity;
            }

            $price = method_exists($service, 'getPriceForSqft')
                ? $service->getPriceForSqft($this->extractShootSqft($shoot))
                : $service->price;

            return (float) ($price ?? 0) * $quantity;
        }), 2);
    }

    private function extractShootSqft(Shoot $shoot): ?int
    {
        $details = $shoot->property_details;
        if (is_string($details)) {
            $details = json_decode($details, true);
        }

        if (!is_array($details)) {
            return null;
        }

        $sqft = $details['sqft'] ?? $details['squareFeet'] ?? $details['livingArea'] ?? null;

        return is_numeric($sqft) ? (int) $sqft : null;
    }

    public function buildEditorSummaries(Carbon $start, Carbon $end): Collection
    {
        return $this->editorPayoutService->buildEmailSummaries($start, $end);
    }

    protected function resolveUserFromIdentifier($identifier): ?User
    {
        if (!$identifier) {
            return null;
        }

        if (is_numeric($identifier)) {
            return User::find((int) $identifier);
        }

        $value = (string) $identifier;

        if (Str::isUuid($value)) {
            return User::where('id', $value)->first();
        }

        return User::where('email', $value)
            ->orWhere('name', $value)
            ->first();
    }
}
