<?php

namespace App\Services\Scheduling;

use App\Models\User;
use App\Services\AddressLookupService;
use App\Services\Photographers\RadiusEligibility;
use Carbon\Carbon;

/** Home territory and travel from the preceding visit are independent checks. */
class ScheduleRadiusEvaluator
{
    public function evaluate(array $proposed, array $existing, array $locations): array
    {
        if (! RadiusEligibility::enforced()) {
            return [];
        }
        $photographers = User::whereIn('id', array_column($proposed, 'photographer_id'))->get()->keyBy('id');
        $checks = [];
        $policy = ['network_allowed' => true, 'deadline' => microtime(true) + 3];
        foreach ($proposed as $visit) {
            $photographer = $photographers->get($visit['photographer_id']);
            $metadata = $photographer?->metadata ?? [];
            $start = Carbon::parse($visit['start']);
            $date = $start->copy()->setTimezone($visit['timezone'])->toDateString();
            $previous = array_values(array_filter(array_merge($existing, $proposed), fn ($other) => $other['id'] !== $visit['id'] && $other['photographer_id'] === $visit['photographer_id']
                && Carbon::parse($other['end'])->lte($start)
                && Carbon::parse($other['end'])->setTimezone($visit['timezone'])->toDateString() === $date));
            usort($previous, fn ($a, $b) => Carbon::parse($b['end'])->getTimestamp() <=> Carbon::parse($a['end'])->getTimestamp());
            $neighbor = $previous[0] ?? null;
            $destination = $locations[$visit['plan_index']] ?? [];
            [$lat, $lng] = RadiusEligibility::coordsFromMetadata($metadata);
            $origins = ['home' => ['address' => $photographer?->address ?: ($metadata['address'] ?? $metadata['homeAddress'] ?? ''),
                'city' => $photographer?->city ?: ($metadata['city'] ?? ''),
                'state' => $photographer?->state ?: ($metadata['state'] ?? ''),
                'zip' => $photographer?->zip ?: ($metadata['zip'] ?? $metadata['zipcode'] ?? ''),
                'latitude' => $lat, 'longitude' => $lng]];
            if ($neighbor) {
                $origins['previous_shoot'] = $neighbor['proposed'] ? ($locations[$neighbor['plan_index']] ?? [])
                    : app(TravelLocationResolver::class)->forShoot($neighbor['_shoot']);
            }
            foreach ($origins as $source => $origin) {
                $sameBuilding = ! empty($origin['building_key'])
                    && $origin['building_key'] === ($destination['building_key'] ?? null);
                $distance = $sameBuilding ? 0.0 : null;
                if ($distance === null) {
                    try {
                        $lookup = app(AddressLookupService::class)->getDistance($origin, $destination, $policy);
                        if (is_numeric($lookup['distance_value'] ?? null)) {
                            $distance = round((float) $lookup['distance_value'] / 1609.34, 1);
                        }
                    } catch (\Throwable) {
                        // Missing location evidence requires an explicit staff exception.
                    }
                }
                $distance ??= RadiusEligibility::distanceMiles($origin['latitude'] ?? null, $origin['longitude'] ?? null,
                    $destination['latitude'] ?? null, $destination['longitude'] ?? null);
                $eligibility = RadiusEligibility::evaluate(RadiusEligibility::radiusFromMetadata($metadata), $distance);
                $checks[] = ['photographer_id' => (int) $visit['photographer_id'],
                    'origin' => $source, 'distance_miles' => $eligibility['distance'],
                    'radius_miles' => $eligibility['radius'], 'eligible' => $eligibility['eligible'],
                    'reason' => $eligibility['reason'], 'candidate_start' => $visit['start']];
            }
        }

        return $checks;
    }
}
