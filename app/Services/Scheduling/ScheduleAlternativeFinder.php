<?php

namespace App\Services\Scheduling;

use App\Models\User;
use Carbon\Carbon;

class ScheduleAlternativeFinder
{
    /** Bounded, on-demand checks, never a route lookup for every calendar minute. */
    public function find(array $plan, ?User $actor, array $assessment): array
    {
        $visits = $assessment['visits'];
        usort($visits, fn ($a, $b) => strcmp($a['start'], $b['start']));
        $anchor = Carbon::parse($visits[0]['start']);
        $offsets = [];
        foreach ($assessment['transitions'] as $edge) {
            foreach (['earliest_start', 'latest_start'] as $bound) {
                if (empty($edge[$bound])) {
                    continue;
                }
                $delta = (Carbon::parse($edge[$bound])->getTimestamp() - Carbon::parse($edge['candidate_start'])->getTimestamp()) / 60;
                $offsets[] = (int) ($delta >= 0 ? ceil($delta / 5) : floor($delta / 5)) * 5;
            }
        }
        $offsets = array_values(array_unique(array_filter(array_merge($offsets, [5, -5, 15, -15, 30, -30, 60, -60]))));
        $suggestions = [];
        foreach (array_slice($offsets, 0, 8) as $offset) {
            $shifted = array_map(fn ($visit) => ['photographer_id' => $visit['photographer_id'],
                'scheduled_at' => Carbon::parse($visit['start'])->addMinutes($offset)->toIso8601String(),
                'duration_minutes' => $visit['duration_minutes'], 'timezone' => $visit['timezone']], $visits);
            $payload = array_merge($plan['payload'], ['_schedule_visits' => $shifted]);
            $candidate = app(ScheduleFeasibilityService::class)->evaluate($payload, $plan['shoot'] ?? null, $actor);
            if (! $candidate['available']) {
                continue;
            }
            $suggestions[] = ['scheduled_at' => $anchor->copy()->addMinutes($offset)->toIso8601String(),
                'photographer_id' => $visits[0]['photographer_id'], 'offset_minutes' => $offset,
                'shifted_visits' => $shifted];
            if (count($suggestions) === 3) {
                break;
            }
        }

        return $suggestions;
    }
}
