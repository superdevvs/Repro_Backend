<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PayoutReportService
{
    public function __construct(
        private readonly EditorPayoutService $editorPayoutService,
    ) {
    }

    public function lastCompletedWeekRange(): array
    {
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeek();
        $end = $start->copy()->endOfWeek(Carbon::SUNDAY);

        return [$start, $end];
    }

    /**
     * Build photographer summaries using SERVICE-LEVEL grouping
     * 
     * NEW LOGIC: Groups by resolved photographer per service, not per shoot
     * Fallback: shoot_service.photographer_id ?? shoot.photographer_id
     */
    public function buildPhotographerSummaries(Carbon $start, Carbon $end): Collection
    {
        $shoots = Shoot::with([
                'photographer:id,name,email',
                'services' => function ($q) {
                    $q->withPivot(['photographer_id', 'photographer_pay', 'quantity']);
                },
            ])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('completed_at', [$start, $end])
                  ->orWhere(function ($q2) use ($start, $end) {
                      $q2->whereNull('completed_at')
                         ->whereBetween('admin_verified_at', [$start, $end]);
                  });
            })
            ->whereIn('workflow_status', [
                Shoot::WORKFLOW_COMPLETED,
                Shoot::WORKFLOW_ADMIN_VERIFIED,
            ])
            ->get();

        // Flatten to service-level rows with resolved photographer
        $serviceRows = collect();
        
        foreach ($shoots as $shoot) {
            $fallbackId = $shoot->photographer_id;
            
            foreach ($shoot->services as $service) {
                $resolvedId = $service->pivot->photographer_id ?? $fallbackId;
                if (!$resolvedId) {
                    \Log::warning('Unresolved photographer for payout', [
                        'shoot_id' => $shoot->id,
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                    ]);
                    continue;
                }
                
                // Pivot photographer_pay → service default photographer_pay → 0
                $pivotPay = $service->pivot->photographer_pay;
                $pay = $pivotPay !== null && $pivotPay !== ''
                    ? (float) $pivotPay
                    : (float) ($service->photographer_pay ?? 0);
                $qty = (int) ($service->pivot->quantity ?? 1);
                
                $serviceRows->push([
                    'shoot_id' => $shoot->id,
                    'resolved_photographer_id' => $resolvedId,
                    'photographer_pay' => $pay * $qty,
                ]);
            }
        }

        // Group by resolved photographer
        return $serviceRows
            ->groupBy('resolved_photographer_id')
            ->map(function (Collection $rows, $photographerId) {
                $photographer = User::find($photographerId);
                if (!$photographer) return null;

                $gross = $rows->sum('photographer_pay');
                $shootCount = $rows->pluck('shoot_id')->unique()->count();

                return [
                    'id' => $photographer->id,
                    'name' => $photographer->name,
                    'email' => $photographer->email,
                    'role' => 'photographer',
                    'shoot_count' => $shootCount,
                    'service_count' => $rows->count(),
                    'gross_total' => round($gross, 2),
                    'average_value' => round($gross / max($shootCount, 1), 2),
                    'commission_rate' => null,
                    'commission_total' => null,
                ];
            })
            ->filter()
            ->values();
    }

    public function buildSalesRepSummaries(Carbon $start, Carbon $end): Collection
    {
        $shoots = Shoot::where(function ($q) use ($start, $end) {
                $q->whereBetween('completed_at', [$start, $end])
                  ->orWhere(function ($q2) use ($start, $end) {
                      $q2->whereNull('completed_at')
                         ->whereBetween('admin_verified_at', [$start, $end]);
                  });
            })
            ->whereIn('workflow_status', [
                Shoot::WORKFLOW_COMPLETED,
                Shoot::WORKFLOW_ADMIN_VERIFIED,
            ])
            ->get();

        return $shoots
            ->groupBy(function (Shoot $shoot) {
                return $shoot->created_by ?? 'unknown';
            })
            ->map(function (Collection $group, $key) {
                $rep = $this->resolveUserFromIdentifier($key);
                if (!$rep || $rep->role !== 'salesRep') {
                    return null;
                }

                $gross = (float) $group->sum('total_quote');
                $commissionRate = (float) data_get($rep->metadata, 'repDetails.commissionPercentage', 0);
                $commissionTotal = $commissionRate > 0 ? round($gross * ($commissionRate / 100), 2) : null;

                return [
                    'id' => $rep->id,
                    'name' => $rep->name,
                    'email' => $rep->email,
                    'role' => 'salesRep',
                    'shoot_count' => $group->count(),
                    'gross_total' => round($gross, 2),
                    'average_value' => round($group->avg('total_quote') ?? 0, 2),
                    'commission_rate' => $commissionRate ?: null,
                    'commission_total' => $commissionTotal,
                    'categories' => data_get($rep->metadata, 'repDetails.salesCategories', []),
                ];
            })
            ->filter()
            ->values();
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

