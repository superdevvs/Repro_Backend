<?php

namespace App\Services\Scheduling;

use App\Services\PhotographerAvailabilityService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Evaluates onsite bounds separately from directional travel between affected visits. */
class ScheduleTransitionEvaluator
{
    public function evaluate(array $proposed, array $existing, array $locations): array
    {
        $reasons = [];
        $transitions = [];
        $all = array_merge($existing, $proposed);
        foreach ($proposed as $visit) {
            try {
                app(PhotographerAvailabilityService::class)->assertWithinAvailabilityBounds(
                    $visit['photographer_id'], Carbon::parse($visit['start']), $visit['duration_minutes'],
                    null, true, $visit['timezone']
                );
            } catch (ValidationException) {
                $reasons[] = 'outside_working_hours';
            }
            $samePhotographer = array_values(array_filter($all, fn ($other) => $other['id'] !== $visit['id']
                && $other['photographer_id'] === $visit['photographer_id']));
            $start = Carbon::parse($visit['start']);
            $end = Carbon::parse($visit['end']);
            foreach ($samePhotographer as $other) {
                if ($start < Carbon::parse($other['end']) && $end > Carbon::parse($other['start'])) {
                    $reasons[] = 'capture_overlap';
                }
            }
        }
        // Do not spend provider quota on a schedule that cannot fit capture work.
        if ($reasons !== []) {
            return ['reason_codes' => array_values(array_unique($reasons)), 'transitions' => [],
                'hard_conflict' => true, 'status' => 'conflict'];
        }
        foreach ($proposed as $visit) {
            $samePhotographer = array_values(array_filter($all, fn ($other) => $other['id'] !== $visit['id']
                && $other['photographer_id'] === $visit['photographer_id']));
            $start = Carbon::parse($visit['start']);
            $end = Carbon::parse($visit['end']);
            $before = array_values(array_filter($samePhotographer, fn ($other) => Carbon::parse($other['end']) <= $start));
            $after = array_values(array_filter($samePhotographer, fn ($other) => Carbon::parse($other['start']) >= $end));
            usort($before, fn ($a, $b) => Carbon::parse($b['end'])->getTimestamp() <=> Carbon::parse($a['end'])->getTimestamp());
            usort($after, fn ($a, $b) => Carbon::parse($a['start'])->getTimestamp() <=> Carbon::parse($b['start'])->getTimestamp());
            $edges = [];
            foreach ($before as $neighbor) {
                if ($neighbor['end'] !== $before[0]['end']) {
                    break;
                }
                $edges[] = [$neighbor, $visit, 'incoming'];
            }
            foreach ($after as $neighbor) {
                if ($neighbor['start'] !== $after[0]['start']) {
                    break;
                }
                $edges[] = [$visit, $neighbor, 'outgoing'];
            }
            foreach ($edges as $edge) {
                if (! $edge) {
                    continue;
                }
                [$from, $to, $direction] = $edge;
                $key = hash('sha256', $from['id'].'>'.$to['id']);
                if (isset($transitions[$key])) {
                    continue;
                }
                $fromLocation = $this->location($from, $locations);
                $toLocation = $this->location($to, $locations);
                // Units of one proposed property are a single known site even before geocoding.
                $internalVisit = $from['proposed'] && $to['proposed'] && $from['plan_index'] === $to['plan_index'];
                $estimate = $internalVisit ? ['source' => 'same_building', 'required_minutes' => 0,
                    'review_required' => false, 'reason_code' => null, 'drive_minutes' => 0, 'distance_miles' => 0]
                    : app(TravelTimeEstimator::class)->estimate($fromLocation, $toLocation, Carbon::parse($from['end']));
                $gap = (Carbon::parse($to['start'])->getTimestamp() - Carbon::parse($from['end'])->getTimestamp()) / 60;
                $required = $estimate['required_minutes'];
                $shortfall = $required === null ? null : max(0, $required - $gap);
                if ($estimate['review_required']) {
                    $reasons[] = 'travel_review_required';
                } elseif ($shortfall > 0) {
                    $reasons[] = 'insufficient_travel_time';
                }
                $transitions[$key] = array_merge($estimate, ['id' => substr($key, 0, 20),
                    'from_visit_id' => $from['id'], 'to_visit_id' => $to['id'],
                    'direction' => $from['proposed'] && $to['proposed'] ? 'between_proposed' : $direction,
                    'photographer_id' => $visit['photographer_id'], 'available_minutes' => $gap,
                    'shortfall_minutes' => $shortfall, 'candidate_start' => $visit['start'], 'candidate_end' => $visit['end'],
                    'earliest_start' => $direction === 'incoming' && $required !== null ? Carbon::parse($from['end'])->addMinutes($required)->toIso8601String() : null,
                    'latest_start' => $direction === 'outgoing' && $required !== null ? Carbon::parse($to['start'])->subMinutes($required + $visit['duration_minutes'])->toIso8601String() : null,
                ]);
            }
        }
        $reasons = array_values(array_unique($reasons));
        $hard = array_intersect($reasons, ['capture_overlap', 'outside_working_hours']) !== [];

        return ['reason_codes' => $reasons, 'transitions' => array_values($transitions), 'hard_conflict' => $hard,
            'status' => $hard || in_array('insufficient_travel_time', $reasons, true) ? 'conflict'
                : (in_array('travel_review_required', $reasons, true) ? 'review_required' : 'available')];
    }

    private function location(array $visit, array &$locations): array
    {
        if ($visit['proposed']) {
            return $locations[$visit['plan_index']];
        }
        $key = 'shoot:'.$visit['_shoot']->id;

        return $locations[$key] ??= app(TravelLocationResolver::class)->forShoot($visit['_shoot']);
    }
}
