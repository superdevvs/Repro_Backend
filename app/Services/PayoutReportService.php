<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PayoutReportService
{
    public function lastCompletedWeekRange(): array
    {
        $end = Carbon::now()->startOfWeek(Carbon::SUNDAY)->subDay(); // Saturday before the current week
        $start = $end->copy()->subDays(6)->startOfDay();

        return [$start, $end->endOfDay()];
    }

    public function buildPhotographerSummaries(Carbon $start, Carbon $end): Collection
    {
        $shoots = Shoot::with('photographer:id,name,email')
            ->whereNotNull('photographer_id')
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('workflow_status', [
                Shoot::WORKFLOW_COMPLETED,
                Shoot::WORKFLOW_ADMIN_VERIFIED,
            ])
            ->get();

        return $shoots
            ->groupBy('photographer_id')
            ->map(function (Collection $group, $photographerId) {
                $photographer = $group->first()->photographer ?: User::find($photographerId);
                if (!$photographer) {
                    return null;
                }

                $gross = (float) $group->sum('total_quote');

                return [
                    'id' => $photographer->id,
                    'name' => $photographer->name,
                    'email' => $photographer->email,
                    'role' => 'photographer',
                    'shoot_count' => $group->count(),
                    'gross_total' => round($gross, 2),
                    'average_value' => round($group->avg('total_quote') ?? 0, 2),
                    'commission_rate' => null,
                    'commission_total' => null,
                ];
            })
            ->filter()
            ->values();
    }

    public function buildSalesRepSummaries(Carbon $start, Carbon $end): Collection
    {
        $shoots = Shoot::whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
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

