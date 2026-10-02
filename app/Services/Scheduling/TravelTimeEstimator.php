<?php

namespace App\Services\Scheduling;

use Carbon\CarbonInterface;

class TravelTimeEstimator
{
    public function __construct(private GoogleRoutesProvider $routes) {}

    public function reset(): void
    {
        $this->routes->reset();
    }

    public function estimate(array $origin, array $destination, CarbonInterface $departure): array
    {
        if (! empty($origin['verified']) && ! empty($destination['verified'])
            && ! empty($origin['building_key']) && $origin['building_key'] === ($destination['building_key'] ?? null)) {
            return $this->result('same_building', 0, 0, 0, 'verified_same_building');
        }
        if (empty($origin['full_address']) || empty($destination['full_address'])
            || empty($origin['complete']) || empty($destination['complete'])) {
            return $this->result('unknown', null, null, null, 'location_incomplete');
        }
        if (empty($origin['verified']) || empty($destination['verified'])) {
            // Matrix address geocoding does not return precision. A syntactically
            // complete address alone cannot prove it selected the intended site.
            return $this->result('unknown', null, null, null, 'location_unverified');
        }
        $route = $this->routes->route($origin, $destination, $departure);
        if ($route['status'] === 'no_route') {
            return $this->result('unknown', null, null, null, 'route_not_found');
        }
        if ($route['status'] === 'ok') {
            $minutes = $route['duration_seconds'] / 60;

            return $this->result('google_routes', max(15, (int) ceil(($minutes + 5) / 5) * 5),
                $minutes, $route['distance_meters'] / 1609.344, 'traffic_aware_route', 'Google Maps');
        }
        if (! $this->exactCoordinates($origin) || ! $this->exactCoordinates($destination)) {
            return $this->result('unknown', null, null, null, $route['reason_code'].'_location_unknown');
        }
        $lat1 = deg2rad((float) $origin['latitude']);
        $lat2 = deg2rad((float) $destination['latitude']);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $destination['longitude'] - (float) $origin['longitude']);
        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $miles = 3958.7613 * 2 * atan2(sqrt(min(1, $a)), sqrt(max(0, 1 - $a))) * 1.3;
        $required = $miles <= 5 ? 15 : ($miles <= 15 ? 30 : ($miles <= 30 ? 45 : null));

        return $this->result($required === null ? 'unknown' : 'mileage_band', $required, null, $miles,
            $required === null ? 'fallback_distance_exceeds_limit' : $route['reason_code']);
    }

    private function exactCoordinates(array $location): bool
    {
        return ! empty($location['verified']) && ($location['precision'] ?? '') === 'exact'
            && is_numeric($location['latitude'] ?? null) && is_numeric($location['longitude'] ?? null)
            && is_finite((float) $location['latitude']) && is_finite((float) $location['longitude'])
            && abs((float) $location['latitude']) <= 90 && abs((float) $location['longitude']) <= 180;
    }

    private function result(string $source, ?int $required, ?float $drive, ?float $distance, string $reason, ?string $attribution = null): array
    {
        return ['source' => $source, 'required_minutes' => $required, 'drive_minutes' => $drive,
            'distance_miles' => $distance === null ? null : round($distance, 2), 'reason_code' => $reason,
            'review_required' => $required === null, 'attribution' => $attribution];
    }
}
