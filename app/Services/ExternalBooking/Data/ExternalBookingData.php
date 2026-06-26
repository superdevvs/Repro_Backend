<?php

namespace App\Services\ExternalBooking\Data;

use App\Http\Requests\ExternalBookingRequest;

/**
 * Immutable carrier of the validated external-booking request plus the raw payload.
 *
 * Built once from the request and passed to the schedule normalizer. Holds no
 * business logic — its only job is to capture the validated input in a stable
 * shape while preserving the full original payload for provenance (2.15).
 */
final class ExternalBookingData
{
    public function __construct(
        public readonly array $rawPayload,                 // full original request (provenance, 2.15)
        public readonly array $services,                   // [['id'=>int,'quantity'=>?int], ...]
        public readonly ?string $preferredDate,            // 'Y-m-d' or null
        public readonly ?string $preferredTime,            // 'HH:MM' or null
        public readonly ?string $alternateDate,            // 'Y-m-d' or null (2.1)
        public readonly ?string $alternateTime,            // 'HH:MM' or null (2.1)
        public readonly array $requestedPhotographerIds,   // normalized, de-duped (2.1, 2.2)
        public readonly array $serviceAssignments,         // explicit [{service_id, photographer_id,
                                                           //  scheduled_date, scheduled_time}] (2.8)
        public readonly string $source,
    ) {
    }

    /**
     * Build the DTO from a validated external-booking request.
     *
     * Pulls validated values, normalizes/de-dupes the requested photographer ids
     * from their accepted aliases, and preserves the raw payload for provenance.
     */
    public static function fromRequest(ExternalBookingRequest $request): self
    {
        $validated = $request->validated();

        return new self(
            rawPayload: $request->all(),
            services: self::normalizeServices($validated['services'] ?? []),
            preferredDate: self::nullableString($validated['preferred_date'] ?? null),
            preferredTime: self::nullableString($validated['preferred_time'] ?? null),
            alternateDate: self::nullableString($validated['alternate_date'] ?? null),
            alternateTime: self::nullableString($validated['alternate_time'] ?? null),
            requestedPhotographerIds: self::normalizePhotographerIds($validated),
            serviceAssignments: self::normalizeServiceAssignments($validated['service_assignments'] ?? []),
            source: $validated['source'] ?? 'external_website',
        );
    }

    /**
     * Normalize the services list to [['id'=>int, 'quantity'=>?int], ...].
     */
    private static function normalizeServices(array $services): array
    {
        $normalized = [];

        foreach ($services as $service) {
            if (!is_array($service) || !isset($service['id'])) {
                continue;
            }

            $entry = ['id' => (int) $service['id']];

            if (isset($service['quantity']) && $service['quantity'] !== null && $service['quantity'] !== '') {
                $entry['quantity'] = (int) $service['quantity'];
            } else {
                $entry['quantity'] = null;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * Collapse the single (`selected_photographer_id`/`photographer_id`) and list
     * (`selected_photographers`/`requested_photographers`) aliases into one ordered,
     * de-duplicated list of integer photographer ids.
     *
     * @return int[]
     */
    private static function normalizePhotographerIds(array $validated): array
    {
        $ids = [];

        foreach (['selected_photographer_id', 'photographer_id'] as $singleKey) {
            if (isset($validated[$singleKey]) && $validated[$singleKey] !== null && $validated[$singleKey] !== '') {
                $ids[] = (int) $validated[$singleKey];
            }
        }

        foreach (['selected_photographers', 'requested_photographers'] as $listKey) {
            if (!empty($validated[$listKey]) && is_array($validated[$listKey])) {
                foreach ($validated[$listKey] as $id) {
                    if ($id !== null && $id !== '') {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Normalize explicit per-service assignments, preserving order and keys.
     *
     * @return array<int, array{service_id:int, photographer_id:?int, scheduled_date:?string, scheduled_time:?string}>
     */
    private static function normalizeServiceAssignments(array $assignments): array
    {
        $normalized = [];

        foreach ($assignments as $assignment) {
            if (!is_array($assignment) || !isset($assignment['service_id'])) {
                continue;
            }

            $photographerId = $assignment['photographer_id'] ?? null;

            $normalized[] = [
                'service_id' => (int) $assignment['service_id'],
                'photographer_id' => ($photographerId !== null && $photographerId !== '') ? (int) $photographerId : null,
                'scheduled_date' => self::nullableString($assignment['scheduled_date'] ?? null),
                'scheduled_time' => self::nullableString($assignment['scheduled_time'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * Coerce empty strings to null while leaving meaningful strings intact.
     */
    private static function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }
}
