<?php

namespace App\Services\Photographers;

/**
 * Photographer service-radius eligibility (Option B — flag-gated).
 *
 * Pure, side-effect-free decision logic shared by the booking `for-booking` eligibility path and
 * the manual/auto assignment path, so both gate identically. Gated by
 * `config('availability.radius_enforcement')` — when OFF (production default) every call reports
 * eligible and the historical behavior is unchanged.
 *
 * Decision table (when enforcement is ON), per the QA §4 matrix:
 *   - radius null/empty   → eligible only if `radius_unlimited_when_null` is true; else NOT eligible.
 *   - radius <= 0         → NOT eligible (a zero radius offers nobody).
 *   - distance unknown    → NOT eligible (missing photographer/shoot coordinates; reason explains).
 *   - distance <= radius  → eligible.
 *   - distance >  radius  → NOT eligible.
 *
 * Service-area matching is a SEPARATE gate (region/state/area). "Outside service area" is excluded
 * by the ServiceAreaMatcher regardless of radius; this helper only adds the point-distance gate on
 * top of an already service-area-eligible photographer.
 */
class RadiusEligibility
{
    /** Mean earth radius in statute miles (matches the controller's haversine). */
    private const EARTH_RADIUS_MILES = 3958.8;

    /** Whether radius gating is enabled (config flag). */
    public static function enforced(): bool
    {
        return (bool) config('availability.radius_enforcement', false);
    }

    /** Whether a null/empty radius means "unlimited" (product-approved) vs "not eligible". */
    public static function unlimitedWhenNull(): bool
    {
        return (bool) config('availability.radius_unlimited_when_null', false);
    }

    /**
     * Great-circle distance in statute miles, or null when any coordinate is missing/non-numeric.
     */
    public static function distanceMiles($lat1, $lng1, $lat2, $lng2): ?float
    {
        if (!is_numeric($lat1) || !is_numeric($lng1) || !is_numeric($lat2) || !is_numeric($lng2)) {
            return null;
        }
        $dLat = deg2rad((float) $lat2 - (float) $lat1);
        $dLng = deg2rad((float) $lng2 - (float) $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad((float) $lat1)) * cos(deg2rad((float) $lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round(self::EARTH_RADIUS_MILES * $c, 1);
    }

    /**
     * Evaluate eligibility for an already-computed distance + radius.
     *
     * @return array{eligible: bool, reason: string, distance: ?float, radius: ?float}
     */
    public static function evaluate($radiusMiles, ?float $distanceMiles): array
    {
        $radius = is_numeric($radiusMiles) ? (float) $radiusMiles : null;

        // Enforcement OFF → never gate (historical behavior).
        if (!self::enforced()) {
            return ['eligible' => true, 'reason' => 'enforcement_off', 'distance' => $distanceMiles, 'radius' => $radius];
        }

        if ($radius === null) {
            return self::unlimitedWhenNull()
                ? ['eligible' => true, 'reason' => 'radius_null_unlimited', 'distance' => $distanceMiles, 'radius' => null]
                : ['eligible' => false, 'reason' => 'radius_unset', 'distance' => $distanceMiles, 'radius' => null];
        }

        if ($radius <= 0) {
            return ['eligible' => false, 'reason' => 'radius_zero', 'distance' => $distanceMiles, 'radius' => $radius];
        }

        if ($distanceMiles === null) {
            return ['eligible' => false, 'reason' => 'distance_unavailable', 'distance' => null, 'radius' => $radius];
        }

        return $distanceMiles <= $radius
            ? ['eligible' => true, 'reason' => 'within_radius', 'distance' => $distanceMiles, 'radius' => $radius]
            : ['eligible' => false, 'reason' => 'outside_radius', 'distance' => $distanceMiles, 'radius' => $radius];
    }

    /** Read a service-radius (miles) from a photographer metadata array. */
    public static function radiusFromMetadata(?array $metadata): ?float
    {
        if (!is_array($metadata)) {
            return null;
        }
        $value = $metadata['service_radius_miles'] ?? $metadata['serviceRadius'] ?? $metadata['serviceRadiusMiles'] ?? null;
        return is_numeric($value) ? (float) $value : null;
    }

    /** Read [lat, lng] from a photographer metadata array, or [null, null]. */
    public static function coordsFromMetadata(?array $metadata): array
    {
        if (!is_array($metadata)) {
            return [null, null];
        }
        $lat = $metadata['latitude'] ?? $metadata['lat'] ?? null;
        $lng = $metadata['longitude'] ?? $metadata['lng'] ?? null;
        return [is_numeric($lat) ? (float) $lat : null, is_numeric($lng) ? (float) $lng : null];
    }
}
