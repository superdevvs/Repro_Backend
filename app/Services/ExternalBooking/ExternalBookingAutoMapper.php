<?php

namespace App\Services\ExternalBooking;

use Carbon\Carbon;

/**
 * Conservative, lossless auto-mapper for external bookings.
 *
 * Pure function of the {@see NormalizedBooking} (NO DB writes) so the photographer cases
 * A–E and the schedule cases 1–4 are exhaustively unit/property testable. The guiding
 * principle is "a wrong assignment is worse than no assignment": the mapper assigns a
 * photographer/schedule only when it is unambiguous and leaves anything unclear unassigned
 * with a flag/warning signal.
 *
 * Decision tables (S = resolved services, P = requested photographers):
 *
 *   Photographer (A–E):
 *     A  S==1, P==1  -> assign pivot + legacy shoot photographer
 *     B  S==1, P==0  -> leave unassigned
 *     C  S==1, P>1   -> leave unassigned + flag multiplePhotographersForOneService
 *     D  S>1,  P==1   -> assign first service ONLY if eligibility confirms; else unassigned
 *                        + flag unmappablePhotographers
 *     E  S>1,  P>1    -> leave all unassigned + flag unmappablePhotographers
 *
 *   Schedule (1–4):
 *     1  S==1                       -> preferred -> service + shoot-level
 *     2  S>1, preferred & alternate -> preferred -> s1, s2+ unscheduled; alternate persists
 *                                      ONLY into the shoot-level alternate field, never a service
 *     3  S>1, preferred only        -> preferred -> s1, s2+ unscheduled (never copy-to-all)
 *     4  explicit service_assignments -> apply per-service schedules directly (inference skipped)
 *
 *   Cases 2 and 3 collapse: a multi-service booking with a preferred date always leaves
 *   services #2..N unscheduled regardless of alternate presence. The alternate date is
 *   recorded only on the shoot-level alternate field (alternateSchedule).
 *
 *   No-fabricated-time rule (resolveSchedule) applies at both shoot and pivot level: a
 *   date without a time NEVER produces a fabricated midnight — time/scheduled_at stay null.
 *
 * Validates: Requirements 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 2.11, 2.12, 2.13, 2.14, 2.16
 */
final class ExternalBookingAutoMapper
{
    /**
     * Optional eligibility hook for photographer case D (S>1, P==1). Conservative by
     * design: when no resolver is injected the assignment is treated as NOT safe, so the
     * mapper never fabricates an assignment.
     *
     * @var (callable(int $photographerId, int $serviceId): bool)|null
     */
    private $eligibilityResolver;

    /**
     * @param (callable(int $photographerId, int $serviceId): bool)|null $eligibilityResolver
     */
    public function __construct(?callable $eligibilityResolver = null)
    {
        $this->eligibilityResolver = $eligibilityResolver;
    }

    public function map(NormalizedBooking $booking): MappingResult
    {
        // Shoot-level schedule is always derived from the preferred/alternate values,
        // subject to the no-fabricated-time rule (2.12, 2.13, 2.14).
        $preferred = $this->resolveSchedule($booking->preferred['date'] ?? null, $booking->preferred['time'] ?? null);
        $alternate = $this->resolveSchedule($booking->alternate['date'] ?? null, $booking->alternate['time'] ?? null);

        $shootSchedule = [
            'scheduled_date' => $preferred['scheduled_date'],
            'time' => $preferred['time'],
            'scheduled_at' => $preferred['scheduled_at'],
        ];

        $alternateSchedule = [
            'alternate_scheduled_date' => $alternate['scheduled_date'],
            'alternate_time' => $alternate['time'],
            'alternate_scheduled_at' => $alternate['scheduled_at'],
        ];

        $flags = [
            'multiplePhotographersForOneService' => false,
            'unmappablePhotographers' => false,
            'unscheduledServices' => [],
            'preferredDateMissingTime' => $preferred['missingTime'],
            'alternateDateMissingTime' => $alternate['missingTime'],
        ];

        // Explicit per-service assignments bypass all inference (2.8 / schedule case 4).
        if (!empty($booking->service_assignments)) {
            return $this->mapExplicitAssignments($booking, $shootSchedule, $alternateSchedule, $flags);
        }

        $services = array_values($booking->selected_services);
        $photographers = array_values($booking->requested_photographers);
        $serviceCount = count($services);
        $photographerCount = count($photographers);

        // Seed a fully-null assignment for every resolved service.
        $serviceAssignments = [];
        foreach ($services as $service) {
            $serviceAssignments[(int) $service['id']] = ['photographer_id' => null, 'scheduled_at' => null];
        }

        // ---- Photographer decision table (cases A–E) ----
        $legacyPhotographerId = null;

        if ($serviceCount === 1 && $photographerCount === 1) {
            // Case A
            $serviceId = (int) $services[0]['id'];
            $serviceAssignments[$serviceId]['photographer_id'] = (int) $photographers[0];
            $legacyPhotographerId = (int) $photographers[0];
        } elseif ($serviceCount === 1 && $photographerCount > 1) {
            // Case C
            $flags['multiplePhotographersForOneService'] = true;
        } elseif ($serviceCount > 1 && $photographerCount === 1) {
            // Case D — assign first service only if eligibility confirms it is safe.
            $serviceId = (int) $services[0]['id'];
            if ($this->isPhotographerEligibleForService((int) $photographers[0], $serviceId)) {
                $serviceAssignments[$serviceId]['photographer_id'] = (int) $photographers[0];
            } else {
                $flags['unmappablePhotographers'] = true;
            }
        } elseif ($serviceCount > 1 && $photographerCount > 1) {
            // Case E
            $flags['unmappablePhotographers'] = true;
        }
        // Case B (S==1, P==0) and S>1,P==0 leave everything unassigned (no flag).

        // ---- Schedule decision table (cases 1–3) ----
        $preferredPresent = !empty($booking->preferred['date'] ?? null);

        if ($serviceCount === 1) {
            // Case 1 — preferred -> the single service (subject to no-fabricated-time).
            $serviceId = (int) $services[0]['id'];
            $serviceAssignments[$serviceId]['scheduled_at'] = $preferred['scheduled_at'];
        } elseif ($serviceCount > 1 && $preferredPresent) {
            // Multi-service with scheduling intent (cases 2 and 3, now collapsed).
            $firstServiceId = (int) $services[0]['id'];
            $serviceAssignments[$firstServiceId]['scheduled_at'] = $preferred['scheduled_at'];

            // The alternate is NEVER applied to a service. It persists only into the
            // shoot-level alternate field (alternateSchedule, populated above). Whether or
            // not an alternate is present, services #2..N are left unscheduled.
            for ($position = 2; $position <= $serviceCount; $position++) {
                $flags['unscheduledServices'][] = $position;
            }
        }
        // S>1 with no preferred date => no scheduling intent; leave all unscheduled, no warning.

        $mappingStatus = $this->deriveMappingStatus($shootSchedule, $serviceAssignments, $legacyPhotographerId, $flags);

        return new MappingResult(
            shootSchedule: $shootSchedule,
            alternateSchedule: $alternateSchedule,
            serviceAssignments: $serviceAssignments,
            legacyPhotographerId: $legacyPhotographerId,
            mappingStatus: $mappingStatus,
            flags: $flags,
        );
    }

    /**
     * Apply explicit per-service assignments directly and skip inference (2.8).
     *
     * @param array{scheduled_date: ?string, time: ?string, scheduled_at: ?string} $shootSchedule
     * @param array{alternate_scheduled_date: ?string, alternate_time: ?string, alternate_scheduled_at: ?string} $alternateSchedule
     * @param array{multiplePhotographersForOneService: bool, unmappablePhotographers: bool, unscheduledServices: int[], preferredDateMissingTime: bool, alternateDateMissingTime: bool} $flags
     */
    private function mapExplicitAssignments(
        NormalizedBooking $booking,
        array $shootSchedule,
        array $alternateSchedule,
        array $flags
    ): MappingResult {
        $serviceAssignments = [];

        // Seed every resolved service so the controller can attach them all.
        foreach (array_values($booking->selected_services) as $service) {
            $serviceAssignments[(int) $service['id']] = ['photographer_id' => null, 'scheduled_at' => null];
        }

        $allComplete = true;
        $mappedAnything = false;

        foreach ($booking->service_assignments as $assignment) {
            $serviceId = (int) $assignment['service_id'];
            $photographerId = $assignment['photographer_id'] ?? null;
            $schedule = $this->resolveSchedule($assignment['scheduled_date'] ?? null, $assignment['scheduled_time'] ?? null);

            $serviceAssignments[$serviceId] = [
                'photographer_id' => $photographerId !== null ? (int) $photographerId : null,
                'scheduled_at' => $schedule['scheduled_at'],
            ];

            if ($photographerId !== null || $schedule['scheduled_at'] !== null) {
                $mappedAnything = true;
            }
            if ($photographerId === null || $schedule['scheduled_at'] === null) {
                $allComplete = false;
            }
        }

        // Shoot-level schedule still counts as mapped content for status derivation.
        if ($shootSchedule['scheduled_date'] !== null) {
            $mappedAnything = true;
        }

        if ($allComplete && !$flags['preferredDateMissingTime'] && !$flags['alternateDateMissingTime']) {
            $mappingStatus = MappingResult::STATUS_FULLY_MAPPED;
        } elseif ($mappedAnything) {
            $mappingStatus = MappingResult::STATUS_PARTIALLY_MAPPED;
        } else {
            $mappingStatus = MappingResult::STATUS_NEEDS_REVIEW;
        }

        return new MappingResult(
            shootSchedule: $shootSchedule,
            alternateSchedule: $alternateSchedule,
            serviceAssignments: $serviceAssignments,
            legacyPhotographerId: null, // legacy photographer is set only in inferred case A
            mappingStatus: $mappingStatus,
            flags: $flags,
        );
    }

    /**
     * No-fabricated-time rule (2.12, 2.13, 2.14).
     *
     * - date empty            -> { all null }
     * - date set, time empty  -> { date kept, time/scheduled_at null, missingTime: true }  (NEVER 00:00)
     * - both present          -> { combined scheduled_at }
     *
     * @return array{scheduled_date: ?string, time: ?string, scheduled_at: ?string, missingTime: bool}
     */
    private function resolveSchedule(?string $date, ?string $time): array
    {
        $date = ($date === null || $date === '') ? null : $date;
        $time = ($time === null || $time === '') ? null : $time;

        if ($date === null) {
            return ['scheduled_date' => null, 'time' => null, 'scheduled_at' => null, 'missingTime' => false];
        }

        if ($time === null) {
            return ['scheduled_date' => $date, 'time' => null, 'scheduled_at' => null, 'missingTime' => true];
        }

        return [
            'scheduled_date' => $date,
            'time' => $time,
            'scheduled_at' => $this->combine($date, $time),
            'missingTime' => false,
        ];
    }

    /**
     * Combine a date and time into a normalized 'Y-m-d H:i:s' datetime string.
     */
    private function combine(string $date, string $time): string
    {
        return Carbon::parse("{$date} {$time}")->format('Y-m-d H:i:s');
    }

    /**
     * Single extension point for case-D eligibility. Conservative: defaults to false when
     * no resolver is injected so the mapper never fabricates an assignment (2.6).
     */
    private function isPhotographerEligibleForService(int $photographerId, int $serviceId): bool
    {
        if ($this->eligibilityResolver === null) {
            return false;
        }

        return (bool) ($this->eligibilityResolver)($photographerId, $serviceId);
    }

    /**
     * Derive the mapping status (2.16).
     *
     * - No review signals                 -> fully_mapped
     * - Review signals + something mapped -> partially_mapped  (a date-only preference forces this)
     * - Review signals + nothing mapped   -> needs_review
     *
     * @param array{scheduled_date: ?string, time: ?string, scheduled_at: ?string} $shootSchedule
     * @param array<int, array{photographer_id: ?int, scheduled_at: ?string}>       $serviceAssignments
     * @param array{multiplePhotographersForOneService: bool, unmappablePhotographers: bool, unscheduledServices: int[], preferredDateMissingTime: bool, alternateDateMissingTime: bool} $flags
     */
    private function deriveMappingStatus(
        array $shootSchedule,
        array $serviceAssignments,
        ?int $legacyPhotographerId,
        array $flags
    ): string {
        $needsReview = $flags['multiplePhotographersForOneService']
            || $flags['unmappablePhotographers']
            || $flags['preferredDateMissingTime']
            || $flags['alternateDateMissingTime']
            || count($flags['unscheduledServices']) > 0;

        if (!$needsReview) {
            return MappingResult::STATUS_FULLY_MAPPED;
        }

        $mappedAnything = $legacyPhotographerId !== null || $shootSchedule['scheduled_date'] !== null;

        if (!$mappedAnything) {
            foreach ($serviceAssignments as $assignment) {
                if ($assignment['photographer_id'] !== null || $assignment['scheduled_at'] !== null) {
                    $mappedAnything = true;
                    break;
                }
            }
        }

        return $mappedAnything ? MappingResult::STATUS_PARTIALLY_MAPPED : MappingResult::STATUS_NEEDS_REVIEW;
    }
}
