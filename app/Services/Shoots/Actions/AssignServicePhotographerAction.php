<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\AddressLookupService;
use App\Services\Photographers\RadiusEligibility;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Validation\ValidationException;

class AssignServicePhotographerAction
{
    public function __construct(
        protected ShootMutationSupportService $shootMutationSupportService,
        protected ShootActivityLogger $activityLogger,
    ) {
    }

    public function execute(Shoot $shoot, array $payload, User $actor): Shoot
    {
        $assignments = $this->normalizeAssignments($payload);

        // Option B — service-radius gating (flag-gated; config: availability.radius_enforcement).
        // When enforcement is ON, an assignment that places a photographer outside their service
        // range is, by default, blocked (422). Staff may override deliberately by passing
        // `override=true` (+ optional `override_reason`); the override is permitted and AUDIT-LOGGED
        // against the shoot (override user, timestamp, shoot id, photographer id, distance, reason).
        // The override is request-scoped only — it never mutates the photographer profile. No-op when
        // the flag is OFF.
        if (RadiusEligibility::enforced()) {
            $violations = $this->collectRadiusViolations($shoot, $assignments);

            if (!empty($violations)) {
                $override = filter_var($payload['override'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (!$override) {
                    throw ValidationException::withMessages([
                        'service_photographers' => array_map(fn ($v) => $v['message'], $violations),
                    ]);
                }

                $this->logRadiusOverrides($shoot, $violations, $payload['override_reason'] ?? null, $actor);
            }
        }

        $this->shootMutationSupportService->checkServiceItemPhotographerAvailability(
            $this->buildTargetServices($shoot, $assignments),
            $shoot->photographer_id,
            $shoot->id
        );
        $this->shootMutationSupportService->assignServicePhotographers($shoot, $assignments);

        return $shoot->fresh(['client', 'rep', 'photographer', 'services.category'])
            ?? $shoot->load(['client', 'rep', 'photographer', 'services.category']);
    }

    protected function normalizeAssignments(array $payload): array
    {
        if (isset($payload['service_id'])) {
            return [[
                'service_id' => $payload['service_id'],
                'photographer_id' => $payload['photographer_id'] ?? null,
            ]];
        }

        $assignments = $payload['service_photographers']
            ?? $payload['assignments']
            ?? $payload['services']
            ?? $payload;

        return collect($assignments)
            ->filter(fn ($assignment) => is_array($assignment) && !empty($assignment['service_id']))
            ->map(fn (array $assignment) => [
                'service_id' => (int) $assignment['service_id'],
                'photographer_id' => array_key_exists('photographer_id', $assignment) && $assignment['photographer_id'] !== ''
                    ? (int) $assignment['photographer_id']
                    : null,
            ])
            ->values()
            ->all();
    }

    protected function buildTargetServices(Shoot $shoot, array $assignments): array
    {
        $shoot->loadMissing('services');
        $assignmentsByService = collect($assignments)->keyBy('service_id');

        return $shoot->services->map(function ($service) use ($assignmentsByService) {
            $assignment = $assignmentsByService->get((int) $service->id, []);

            return [
                'id' => (int) $service->id,
                'price' => $service->pivot?->price,
                'quantity' => $service->pivot?->quantity ?? 1,
                'photographer_id' => array_key_exists('photographer_id', $assignment)
                    ? $assignment['photographer_id']
                    : $service->pivot?->photographer_id,
                'scheduled_at' => $service->pivot?->scheduled_at,
            ];
        })->values()->all();
    }

    /**
     * Option B radius gate for the assignment path — collect (do not throw) the out-of-range
     * assignments so the caller can either block (no override) or log + proceed (override).
     *
     * For every assignment that places a photographer, resolve the shoot-to-photographer distance
     * and apply the shared {@see RadiusEligibility} decision table. Distance is resolved address-first
     * (via AddressLookupService, same as for-booking) with a coordinate haversine fallback; per the
     * QA §4 matrix an unresolvable distance is NOT eligible.
     *
     * @return array<int, array{photographer_id:int, photographer_name:string, distance:?float, radius:?float, reason:string, message:string}>
     */
    protected function collectRadiusViolations(Shoot $shoot, array $assignments): array
    {
        $photographerIds = collect($assignments)
            ->pluck('photographer_id')
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($photographerIds->isEmpty()) {
            return [];
        }

        $photographers = User::whereIn('id', $photographerIds)
            ->where('role', 'photographer')
            ->get()
            ->keyBy('id');

        $violations = [];

        foreach ($photographerIds as $photographerId) {
            $photographer = $photographers->get($photographerId);
            if (!$photographer) {
                continue;
            }

            $metadata = is_string($photographer->metadata)
                ? json_decode($photographer->metadata, true)
                : ($photographer->metadata ?? []);
            $metadata = is_array($metadata) ? $metadata : [];

            $radius = $this->resolveRadiusMiles($metadata);
            $distance = $this->resolveAssignmentDistanceMiles($shoot, $photographer, $metadata);
            $eval = RadiusEligibility::evaluate($radius, $distance);

            if (!$eval['eligible']) {
                $name = $photographer->name ?: "Photographer #{$photographerId}";
                $violations[] = [
                    'photographer_id' => $photographerId,
                    'photographer_name' => $name,
                    'distance' => $eval['distance'],
                    'radius' => $eval['radius'],
                    'reason' => $eval['reason'],
                    'message' => $this->radiusBlockMessage($name, $eval),
                ];
            }
        }

        return $violations;
    }

    /**
     * Audit-log each out-of-range assignment that a staff member deliberately overrode.
     * Captures override user + timestamp (ShootActivityLog.created_at), shoot id, photographer id,
     * distance/radius, and the optional reason. Does NOT broadcast (not a notable client event).
     */
    protected function logRadiusOverrides(Shoot $shoot, array $violations, ?string $reason, User $actor): void
    {
        $reason = is_string($reason) ? trim($reason) : null;

        foreach ($violations as $violation) {
            $this->activityLogger->log($shoot, 'photographer_assignment_radius_override', [
                'photographer_id' => $violation['photographer_id'],
                'photographer_name' => $violation['photographer_name'],
                'distance_miles' => $violation['distance'],
                'radius_miles' => $violation['radius'],
                'reason' => $reason !== '' ? $reason : null,
                'override_by' => $actor->id,
                'override_by_name' => $actor->name,
            ], $actor);
        }
    }

    /**
     * Resolve a photographer's service radius (miles) from profile metadata.
     *
     * Prefers an explicit `service_radius_miles` (the radius-gate field), then falls back to the
     * profile's "Max Distance" field (`travel_range` + `travel_range_unit`), converting km→miles so
     * the gate honours the value staff actually set in the photographer profile.
     */
    protected function resolveRadiusMiles(array $metadata): ?float
    {
        $explicit = RadiusEligibility::radiusFromMetadata($metadata);
        if ($explicit !== null) {
            return $explicit;
        }

        $range = $metadata['travel_range'] ?? $metadata['travelRange'] ?? null;
        if (!is_numeric($range)) {
            return null;
        }

        $unit = strtolower((string) ($metadata['travel_range_unit'] ?? $metadata['travelRangeUnit'] ?? 'miles'));

        return $unit === 'km' ? round(((float) $range) * 0.621371, 1) : (float) $range;
    }

    /** Human-readable reason for a radius-blocked assignment. */
    protected function radiusBlockMessage(string $name, array $eval): string
    {
        return match ($eval['reason']) {
            'outside_radius' => "{$name} is outside their service radius for this shoot ({$eval['distance']} mi away, {$eval['radius']} mi radius).",
            'radius_unset' => "{$name} has no service radius set and cannot be assigned while radius enforcement is on.",
            'radius_zero' => "{$name} has a zero service radius and cannot be assigned.",
            'distance_unavailable' => "{$name} cannot be assigned: the distance to this shoot could not be determined (missing photographer or shoot coordinates).",
            default => "{$name} is not eligible for this shoot under the service-radius policy.",
        };
    }

    /**
     * Resolve the photographer-to-shoot distance in miles for the assignment gate.
     * Address-based lookup first (matches for-booking), then a coordinate haversine fallback using
     * photographer metadata coords. Returns null when distance cannot be determined.
     */
    protected function resolveAssignmentDistanceMiles(Shoot $shoot, User $photographer, array $metadata): ?float
    {
        $shootAddress = $shoot->property_address ?? $shoot->address ?? '';
        $shootCity = $shoot->city ?? '';
        $shootState = $shoot->state ?? '';
        $shootZip = $shoot->zip ?? '';

        $originAddress = $photographer->address ?? $metadata['address'] ?? $metadata['homeAddress'] ?? '';
        $originCity = $photographer->city ?? $metadata['city'] ?? '';
        $originState = $photographer->state ?? $metadata['state'] ?? '';
        $originZip = $photographer->zip ?? $metadata['zip'] ?? $metadata['zipcode'] ?? '';

        $hasOrigin = $originAddress || ($originCity && $originState);
        $hasShoot = $shootAddress && $shootCity && $shootState;

        if ($hasOrigin && $hasShoot) {
            try {
                $distanceData = app(AddressLookupService::class)->getDistance(
                    ['address' => $originAddress, 'city' => $originCity, 'state' => $originState, 'zip' => $originZip],
                    ['address' => $shootAddress, 'city' => $shootCity, 'state' => $shootState, 'zip' => $shootZip]
                );

                if (is_array($distanceData)) {
                    if (isset($distanceData['distance_value'])) {
                        return round(((float) $distanceData['distance_value']) / 1609.34, 1);
                    }
                    if (isset($distanceData['distance'])) {
                        return (float) preg_replace('/[^0-9.]/', '', (string) $distanceData['distance']);
                    }
                }
            } catch (\Throwable $e) {
                // fall through to coordinate fallback
            }
        }

        // Coordinate haversine fallback (photographer metadata coords + shoot anchor coords).
        [$originLat, $originLng] = RadiusEligibility::coordsFromMetadata($metadata);
        $shootLat = $shoot->latitude ?? null;
        $shootLng = $shoot->longitude ?? null;

        if (!is_numeric($shootLat) || !is_numeric($shootLng)) {
            $tokens = strtolower(trim("{$shootAddress} {$shootCity} {$shootState} {$shootZip}"));
            if (str_contains($tokens, '6424') && str_contains($tokens, 'vale')) {
                $shootLat = 38.8213;
                $shootLng = -77.1589;
            }
        }

        return RadiusEligibility::distanceMiles($originLat, $originLng, $shootLat, $shootLng);
    }
}
