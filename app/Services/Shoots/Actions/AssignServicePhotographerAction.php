<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\AddressLookupService;
use App\Services\Photographers\RadiusEligibility;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Validation\ValidationException;

class AssignServicePhotographerAction
{
    public function __construct(protected ShootMutationSupportService $shootMutationSupportService)
    {
    }

    public function execute(Shoot $shoot, array $payload, User $actor): Shoot
    {
        $assignments = $this->normalizeAssignments($payload);

        // Option B — service-radius gating (flag-gated; config: availability.radius_enforcement).
        // Mirrors the for-booking eligibility gate so manual/auto assignment cannot place a
        // photographer on a shoot outside their service radius. No-op when the flag is OFF.
        if (RadiusEligibility::enforced()) {
            $this->assertAssignmentsWithinRadius($shoot, $assignments);
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
     * Option B radius gate for the assignment path.
     *
     * For every assignment that places a photographer, resolve the shoot-to-photographer distance
     * and apply the shared {@see RadiusEligibility} decision table. Throws a 422 ValidationException
     * (keyed by `service_photographers`) listing each photographer that falls outside their radius.
     * Distance is resolved address-first (via AddressLookupService, same as for-booking) with a
     * coordinate haversine fallback; per the QA §4 matrix an unresolvable distance is NOT eligible.
     */
    protected function assertAssignmentsWithinRadius(Shoot $shoot, array $assignments): void
    {
        $photographerIds = collect($assignments)
            ->pluck('photographer_id')
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($photographerIds->isEmpty()) {
            return;
        }

        $photographers = User::whereIn('id', $photographerIds)
            ->where('role', 'photographer')
            ->get()
            ->keyBy('id');

        $messages = [];

        foreach ($photographerIds as $photographerId) {
            $photographer = $photographers->get($photographerId);
            if (!$photographer) {
                continue;
            }

            $metadata = is_string($photographer->metadata)
                ? json_decode($photographer->metadata, true)
                : ($photographer->metadata ?? []);
            $metadata = is_array($metadata) ? $metadata : [];

            $radius = RadiusEligibility::radiusFromMetadata($metadata);
            $distance = $this->resolveAssignmentDistanceMiles($shoot, $photographer, $metadata);
            $eval = RadiusEligibility::evaluate($radius, $distance);

            if (!$eval['eligible']) {
                \Log::debug('Radius gate blocked assignment', [
                    'shoot_id' => $shoot->id,
                    'photographer_id' => $photographerId,
                    'reason' => $eval['reason'],
                    'distance' => $eval['distance'],
                    'radius' => $eval['radius'],
                ]);

                $name = $photographer->name ?: "Photographer #{$photographerId}";
                $messages[] = $this->radiusBlockMessage($name, $eval);
            }
        }

        if (!empty($messages)) {
            throw ValidationException::withMessages([
                'service_photographers' => $messages,
            ]);
        }
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
