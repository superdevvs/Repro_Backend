<?php

namespace App\Services\ExternalBooking;

/**
 * Immutable result of {@see ExternalBookingAutoMapper::map()}.
 *
 * Pure value object describing how an external booking was conservatively mapped onto
 * the dashboard shoot model. It carries the shoot-level schedule, the alternate
 * schedule, per-service assignments, the legacy shoot-level photographer (case A only),
 * the derived mapping status, and the review flags consumed by the warning builder and
 * notification service. No DB interaction — the mapper that produces this is a pure
 * function of the {@see NormalizedBooking}.
 *
 * Shape (from the design):
 *   shootSchedule:       { scheduled_date: ?string, time: ?string, scheduled_at: ?string }
 *   alternateSchedule:   { alternate_scheduled_date: ?string, alternate_time: ?string,
 *                          alternate_scheduled_at: ?string }
 *   serviceAssignments:  array<int serviceId, { photographer_id: ?int, scheduled_at: ?string }>
 *   legacyPhotographerId: ?int    // shoot-level photographer_id (case A only)
 *   mappingStatus:       'fully_mapped' | 'partially_mapped' | 'needs_review'
 *   flags: {
 *     multiplePhotographersForOneService: bool,
 *     unmappablePhotographers: bool,
 *     unscheduledServices: int[],          // 1-based service positions left unscheduled
 *     preferredDateMissingTime: bool,
 *     alternateDateMissingTime: bool
 *   }
 *
 * Validates: Requirements 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 2.11, 2.12, 2.13, 2.14, 2.16
 */
final class MappingResult
{
    public const STATUS_FULLY_MAPPED = 'fully_mapped';
    public const STATUS_PARTIALLY_MAPPED = 'partially_mapped';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /**
     * @param array{scheduled_date: ?string, time: ?string, scheduled_at: ?string}                          $shootSchedule
     * @param array{alternate_scheduled_date: ?string, alternate_time: ?string, alternate_scheduled_at: ?string} $alternateSchedule
     * @param array<int, array{photographer_id: ?int, scheduled_at: ?string}>                                $serviceAssignments
     * @param array{multiplePhotographersForOneService: bool, unmappablePhotographers: bool, unscheduledServices: int[], preferredDateMissingTime: bool, alternateDateMissingTime: bool} $flags
     */
    public function __construct(
        public readonly array $shootSchedule,
        public readonly array $alternateSchedule,
        public readonly array $serviceAssignments,
        public readonly ?int $legacyPhotographerId,
        public readonly string $mappingStatus,
        public readonly array $flags,
    ) {
    }

    /**
     * Per-service assignment for a given service id, or a fully-null assignment when
     * the service was not part of the mapping.
     *
     * @return array{photographer_id: ?int, scheduled_at: ?string}
     */
    public function assignmentFor(int $serviceId): array
    {
        return $this->serviceAssignments[$serviceId] ?? ['photographer_id' => null, 'scheduled_at' => null];
    }
}
