<?php

namespace App\Services\ExternalBooking;

/**
 * Immutable internal representation of an external booking after normalization (2.2).
 *
 * Collapses the several accepted input shapes (photographer aliases, single vs. list
 * photographers, optional alternate schedule, explicit per-service assignments) into one
 * consistent structure that the auto-mapper consumes:
 *
 *   {
 *     preferred:  { date: ?string, time: ?string },
 *     alternate:  { date: ?string, time: ?string },
 *     requested_photographers: int[],                          // ordered, de-duplicated
 *     selected_services: array<{id:int, quantity:?int}>,       // ordered
 *     service_assignments: array<{service_id:int, photographer_id:?int,
 *                                 scheduled_date:?string, scheduled_time:?string}>
 *   }
 *
 * Access style mirrors the design: `$normalized->preferred['date']`,
 * `$normalized->requested_photographers`, etc.
 */
final class NormalizedBooking
{
    /**
     * @param array{date: ?string, time: ?string}                                                      $preferred
     * @param array{date: ?string, time: ?string}                                                      $alternate
     * @param int[]                                                                                     $requested_photographers
     * @param array<int, array{id:int, quantity:?int}>                                                  $selected_services
     * @param array<int, array{service_id:int, photographer_id:?int, scheduled_date:?string, scheduled_time:?string}> $service_assignments
     */
    public function __construct(
        public readonly array $preferred,
        public readonly array $alternate,
        public readonly array $requested_photographers,
        public readonly array $selected_services,
        public readonly array $service_assignments,
    ) {
    }
}
