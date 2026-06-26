<?php

namespace App\Services\ExternalBooking;

/**
 * Turns the {@see ExternalBookingAutoMapper} mapping flags into the human-readable
 * warnings list persisted on the shoot (`shoots.external_booking_warnings`).
 *
 * Stateless and a pure function of the {@see NormalizedBooking} + {@see MappingResult}:
 * given the same inputs it always emits the same warnings, in a stable order, with NO
 * DB interaction. The exact strings come from the design's warning catalog.
 *
 * Warning catalog (exact strings):
 *   - "Multiple photographers were requested for one service. Please review manually."        (2.5, case C)
 *   - "Multiple photographers were requested across multiple services. Please assign manually." (2.7, case E)
 *   - "Service #{n} could not be scheduled automatically and needs manual scheduling."         (2.10, 2.11)
 *   - "Preferred date was provided without a time. Time requires manual review."               (2.12)
 *   - "Alternate date was provided without a time. Time requires manual review."               (2.13)
 *   - "A single photographer was requested for multiple services. Assignment left for manual review." (2.6, case D)
 *
 * The mapper raises a single `unmappablePhotographers` flag for BOTH the multi-service /
 * single-photographer case (D) and the multi-service / multi-photographer case (E). This
 * builder distinguishes them using the normalized requested-photographer count: more than
 * one requested photographer ⇒ case E (2.7), otherwise case D (2.6).
 *
 * Validates: Requirements 2.5, 2.6, 2.7, 2.10, 2.11, 2.12, 2.13
 */
final class ExternalBookingWarningBuilder
{
    /**
     * @return string[] ordered list of human-readable warnings (empty when fully mapped)
     */
    public function build(NormalizedBooking $booking, MappingResult $result): array
    {
        $flags = $result->flags;
        $warnings = [];

        // Case C — single service, multiple photographers (2.5).
        if (!empty($flags['multiplePhotographersForOneService'])) {
            $warnings[] = 'Multiple photographers were requested for one service. Please review manually.';
        }

        // Cases D/E — photographer(s) that could not be mapped across multiple services.
        // Distinguish using the requested-photographer count (2.6 vs 2.7).
        if (!empty($flags['unmappablePhotographers'])) {
            if (count($booking->requested_photographers) > 1) {
                // Case E (2.7)
                $warnings[] = 'Multiple photographers were requested across multiple services. Please assign manually.';
            } else {
                // Case D (2.6)
                $warnings[] = 'A single photographer was requested for multiple services. Assignment left for manual review.';
            }
        }

        // Unscheduled services — one warning per unscheduled position (2.10, 2.11).
        foreach ($flags['unscheduledServices'] as $position) {
            $warnings[] = "Service #{$position} could not be scheduled automatically and needs manual scheduling.";
        }

        // Date-only preferred schedule (2.12).
        if (!empty($flags['preferredDateMissingTime'])) {
            $warnings[] = 'Preferred date was provided without a time. Time requires manual review.';
        }

        // Date-only alternate schedule (2.13).
        if (!empty($flags['alternateDateMissingTime'])) {
            $warnings[] = 'Alternate date was provided without a time. Time requires manual review.';
        }

        return $warnings;
    }
}
