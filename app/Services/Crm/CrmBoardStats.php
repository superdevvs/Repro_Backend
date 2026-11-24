<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmStageChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers for the stat strip. Every count goes through the owner scope, so a
 * rep's strip covers only their own contacts.
 */
class CrmBoardStats
{
    public function pipeline(): array
    {
        $now = now();
        $weekStart = $now->copy()->startOfWeek();
        $visibleIds = fn () => CrmContact::query()->select('id');

        $newThisWeek = CrmContact::query()->where('created_at', '>=', $weekStart)->count();
        $newLastWeek = CrmContact::query()->whereBetween('created_at', [$weekStart->copy()->subWeek(), $weekStart])->count();
        $inPipeline = CrmContact::query()->whereIn('stage', CrmContact::PIPELINE_STAGES)->count();
        $booked = CrmContact::query()->where('stage', CrmContact::STAGE_BOOKED)->count();
        $bookedThisWeek = CrmStageChange::query()->whereIn('contact_id', $visibleIds())->where('to_stage', CrmContact::STAGE_BOOKED)->where('occurred_at', '>=', $weekStart)->count();

        $overdue = 0;
        if (Schema::hasTable('crm_events')) {
            $overdue = app(CrmEventPresenter::class)->overdueCount();
        }

        return [
            ['key' => 'new_this_week', 'label' => 'New this week', 'value' => $newThisWeek, 'delta' => $newThisWeek - $newLastWeek, 'series' => $this->weeklyCreated(9)],
            ['key' => 'in_pipeline', 'label' => 'In pipeline', 'value' => $inPipeline, 'delta' => null, 'series' => null],
            ['key' => 'booked', 'label' => 'Booked first shoots', 'value' => $booked, 'delta' => $bookedThisWeek, 'series' => $this->weeklyStage(CrmContact::STAGE_BOOKED, 9)],
            ['key' => 'overdue', 'label' => 'Overdue follow-ups', 'value' => $overdue, 'delta' => null, 'series' => null, 'tone' => $overdue > 0 ? 'bad' : 'neutral'],
        ];
    }

    public function contacts(): array
    {
        $total = CrmContact::query()->count();
        $weekStart = now()->startOfWeek();

        return [
            ['key' => 'total', 'label' => 'Total contacts', 'value' => $total, 'delta' => CrmContact::query()->where('created_at', '>=', $weekStart)->count(), 'series' => $this->weeklyCreated(9, true)],
            ['key' => 'in_pipeline', 'label' => 'In pipeline', 'value' => CrmContact::query()->whereIn('stage', CrmContact::PIPELINE_STAGES)->count(), 'delta' => null, 'series' => null],
            ['key' => 'client_pool', 'label' => 'Client pool', 'value' => CrmContact::query()->where('stage', CrmContact::STAGE_CLIENT_POOL)->count(), 'delta' => null, 'series' => null],
            ['key' => 'lost', 'label' => 'Lost', 'value' => CrmContact::query()->where('stage', CrmContact::STAGE_LOST)->count(), 'delta' => null, 'series' => null],
        ];
    }

    /** New contacts per week, oldest first. Cumulative totals when $cumulative. */
    private function weeklyCreated(int $weeks, bool $cumulative = false): array
    {
        $start = now()->startOfWeek()->subWeeks($weeks - 1);
        $dates = CrmContact::query()->where('created_at', '>=', $start)->pluck('created_at');
        $series = $this->bucket($dates, $start, $weeks);

        if (! $cumulative) {
            return $series;
        }
        $running = CrmContact::query()->where('created_at', '<', $start)->count();

        return array_map(function ($n) use (&$running) {
            $running += $n;

            return $running;
        }, $series);
    }

    private function weeklyStage(string $stage, int $weeks): array
    {
        $start = now()->startOfWeek()->subWeeks($weeks - 1);
        $dates = CrmStageChange::query()->whereIn('contact_id', CrmContact::query()->select('id'))->where('to_stage', $stage)->where('occurred_at', '>=', $start)->pluck('occurred_at');

        return $this->bucket($dates, $start, $weeks);
    }

    private function bucket($dates, Carbon $start, int $weeks): array
    {
        $series = array_fill(0, $weeks, 0);
        foreach ($dates as $date) {
            $index = (int) floor($start->diffInDays(Carbon::parse($date)) / 7);
            if ($index >= 0 && $index < $weeks) {
                $series[$index]++;
            }
        }

        return $series;
    }
}
