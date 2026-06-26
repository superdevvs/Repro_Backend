<?php

namespace App\Services\ExternalBooking;

use App\Services\ExternalBooking\Data\ExternalBookingData;

/**
 * Collapses the several accepted external-booking input shapes into one consistent
 * internal structure before mapping (2.2).
 *
 * Most of the alias resolution (`selected_photographer_id`/`photographer_id` and
 * `selected_photographers`/`requested_photographers`) and de-duplication is already
 * performed by {@see ExternalBookingData::fromRequest}, which exposes the normalized
 * ids via `requestedPhotographerIds`. This normalizer reuses that work, re-asserts the
 * de-dupe defensively, and reshapes the DTO into the {@see NormalizedBooking} structure
 * the auto-mapper consumes — preserving the ordered selected services and any explicit
 * service assignments verbatim.
 */
final class ExternalBookingScheduleNormalizer
{
    public function normalize(ExternalBookingData $data): NormalizedBooking
    {
        return new NormalizedBooking(
            preferred: [
                'date' => $data->preferredDate,
                'time' => $data->preferredTime,
            ],
            alternate: [
                'date' => $data->alternateDate,
                'time' => $data->alternateTime,
            ],
            requested_photographers: $this->normalizePhotographerIds($data->requestedPhotographerIds),
            selected_services: $this->normalizeServices($data->services),
            service_assignments: $this->normalizeServiceAssignments($data->serviceAssignments),
        );
    }

    /**
     * Coerce to integers, drop empties, and de-duplicate while preserving order.
     *
     * @param  array<int, mixed>  $ids
     * @return int[]
     */
    private function normalizePhotographerIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }

            $normalized[] = (int) $id;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Preserve the ordered selected services as [['id'=>int, 'quantity'=>?int], ...].
     *
     * @param  array<int, array{id:int, quantity:?int}>  $services
     * @return array<int, array{id:int, quantity:?int}>
     */
    private function normalizeServices(array $services): array
    {
        $normalized = [];

        foreach ($services as $service) {
            if (!is_array($service) || !isset($service['id'])) {
                continue;
            }

            $quantity = $service['quantity'] ?? null;

            $normalized[] = [
                'id' => (int) $service['id'],
                'quantity' => ($quantity === null || $quantity === '') ? null : (int) $quantity,
            ];
        }

        return $normalized;
    }

    /**
     * Preserve explicit per-service assignments, keeping order and normalizing types.
     *
     * @param  array<int, array{service_id:int, photographer_id:?int, scheduled_date:?string, scheduled_time:?string}>  $assignments
     * @return array<int, array{service_id:int, photographer_id:?int, scheduled_date:?string, scheduled_time:?string}>
     */
    private function normalizeServiceAssignments(array $assignments): array
    {
        $normalized = [];

        foreach ($assignments as $assignment) {
            if (!is_array($assignment) || !isset($assignment['service_id'])) {
                continue;
            }

            $photographerId = $assignment['photographer_id'] ?? null;

            $normalized[] = [
                'service_id' => (int) $assignment['service_id'],
                'photographer_id' => ($photographerId === null || $photographerId === '') ? null : (int) $photographerId,
                'scheduled_date' => $this->nullableString($assignment['scheduled_date'] ?? null),
                'scheduled_time' => $this->nullableString($assignment['scheduled_time'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * Coerce empty strings to null while leaving meaningful strings intact.
     */
    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }
}
