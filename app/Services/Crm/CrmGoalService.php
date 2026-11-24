<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmGoal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Monthly goals per person and progress against them. */
class CrmGoalService
{
    public static function month(?string $month = null): string
    {
        return $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : now()->format('Y-m');
    }

    /** @return array<int, array{metric: string, label: string, target: int|null, actual: int}> */
    public function progress(int $userId, ?string $month = null): array
    {
        $month = self::month($month);
        $targets = CrmGoal::query()->where('user_id', $userId)->where('month', $month)->pluck('target', 'metric');
        $actuals = $this->actuals($userId, $month);

        return collect(CrmGoal::METRICS)->map(fn ($metric) => [
            'metric' => $metric,
            'label' => CrmGoal::METRIC_LABELS[$metric],
            'target' => isset($targets[$metric]) ? (int) $targets[$metric] : null,
            'actual' => $actuals[$metric] ?? 0,
        ])->all();
    }

    /** @param  array<string, int|null>  $targets */
    public function set(int $userId, string $month, array $targets, User $actor): void
    {
        foreach ($targets as $metric => $target) {
            if (! in_array($metric, CrmGoal::METRICS, true)) {
                continue;
            }
            if ($target === null) {
                CrmGoal::query()->where(['user_id' => $userId, 'month' => $month, 'metric' => $metric])->delete();

                continue;
            }
            CrmGoal::query()->updateOrCreate(['user_id' => $userId, 'month' => $month, 'metric' => $metric], ['target' => max(0, (int) $target), 'set_by' => $actor->id]);
        }
    }

    /** Counts for one person in one month, credited to whoever owns the contact now. */
    public function actuals(int $userId, string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $owned = CrmContact::unscopedForSystem()->where('assigned_rep_id', $userId)->select('id');

        $stageCount = fn (string $stage) => DB::table('crm_stage_changes')->whereIn('contact_id', $owned)
            ->where('to_stage', $stage)->whereBetween('occurred_at', [$start, $end])->distinct()->count('contact_id');

        return [
            'new_contacts' => CrmContact::unscopedForSystem()->where('assigned_rep_id', $userId)->whereBetween('created_at', [$start, $end])->count(),
            'booked' => $stageCount(CrmContact::STAGE_BOOKED),
            'first_shoots' => $stageCount(CrmContact::STAGE_CLIENT_POOL),
            'follow_ups_done' => CrmEvent::query()->where('assigned_user_id', $userId)->where('status', 'done')->whereBetween('completed_at', [$start, $end])->count(),
        ];
    }
}
