<?php

namespace Tests\Unit\Services\ExternalBooking;

use App\Services\ExternalBooking\ExternalBookingAutoMapper;
use App\Services\ExternalBooking\MappingResult;
use App\Services\ExternalBooking\NormalizedBooking;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit + scoped property tests for ExternalBookingAutoMapper.
 *
 * The mapper is a PURE function of the NormalizedBooking (no DB), so these tests build
 * NormalizedBooking instances directly and assert against the returned MappingResult.
 *
 * Coverage:
 *   - photographer decision table cases A–E
 *   - schedule decision table cases 1–4 (incl. explicit-assignment bypass)
 *   - the no-fabricated-time rule for preferred AND alternate
 *   - mapping-status derivation
 *   - scoped property: no photographer assignment is ever fabricated and the preferred
 *     schedule is never copied onto services beyond the first in case 3
 *
 * Repo has no PHP PBT library (faker only); the property test uses a seeded deterministic
 * generator over the input domain (S × P × preferred/alternate presence), mirroring the
 * scoped-PBT approach established in Task 1.
 *
 * Validates: Requirements 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10, 2.11, 2.12, 2.13, 2.14, 2.16
 */
class ExternalBookingAutoMapperTest extends TestCase
{
    private ExternalBookingAutoMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        // Default mapper: conservative eligibility (case D leaves the photographer unassigned).
        $this->mapper = new ExternalBookingAutoMapper();
    }

    /**
     * @param array<int, array{id:int, quantity:?int}> $services
     * @param int[] $photographers
     * @param array<int, array{service_id:int, photographer_id:?int, scheduled_date:?string, scheduled_time:?string}> $assignments
     */
    private function booking(
        array $services,
        array $photographers = [],
        ?string $prefDate = null,
        ?string $prefTime = null,
        ?string $altDate = null,
        ?string $altTime = null,
        array $assignments = []
    ): NormalizedBooking {
        return new NormalizedBooking(
            preferred: ['date' => $prefDate, 'time' => $prefTime],
            alternate: ['date' => $altDate, 'time' => $altTime],
            requested_photographers: $photographers,
            selected_services: $services,
            service_assignments: $assignments,
        );
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id:int, quantity:?int}>
     */
    private function services(array $ids): array
    {
        return array_map(fn (int $id) => ['id' => $id, 'quantity' => 1], $ids);
    }

    // ------------------------------------------------------------------
    // Photographer decision table — cases A–E
    // ------------------------------------------------------------------

    /** Case A: S=1, P=1 -> assign pivot + legacy (2.3). */
    #[Test]
    public function case_a_single_service_single_photographer_assigns_pivot_and_legacy(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10]), [42], '2026-03-01', '10:00'));

        $this->assertSame(42, $result->serviceAssignments[10]['photographer_id']);
        $this->assertSame(42, $result->legacyPhotographerId);
        $this->assertFalse($result->flags['multiplePhotographersForOneService']);
        $this->assertFalse($result->flags['unmappablePhotographers']);
        $this->assertSame(MappingResult::STATUS_FULLY_MAPPED, $result->mappingStatus);
    }

    /** Case B: S=1, P=0 -> unassigned (2.4). */
    #[Test]
    public function case_b_single_service_no_photographer_leaves_unassigned(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10]), [], '2026-03-01', '10:00'));

        $this->assertNull($result->serviceAssignments[10]['photographer_id']);
        $this->assertNull($result->legacyPhotographerId);
        $this->assertSame(MappingResult::STATUS_FULLY_MAPPED, $result->mappingStatus);
    }

    /** Case C: S=1, P>1 -> unassigned + flag (2.5). */
    #[Test]
    public function case_c_single_service_multiple_photographers_unassigned_and_flagged(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10]), [42, 57], '2026-03-01', '10:00'));

        $this->assertNull($result->serviceAssignments[10]['photographer_id']);
        $this->assertNull($result->legacyPhotographerId);
        $this->assertTrue($result->flags['multiplePhotographersForOneService']);
        $this->assertSame(MappingResult::STATUS_PARTIALLY_MAPPED, $result->mappingStatus);
    }

    /** Case D: S>1, P=1, default (not eligible) -> all unassigned + flag (2.6). */
    #[Test]
    public function case_d_multi_service_single_photographer_default_leaves_unassigned(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10, 11]), [42], '2026-03-01', '10:00'));

        $this->assertNull($result->serviceAssignments[10]['photographer_id']);
        $this->assertNull($result->serviceAssignments[11]['photographer_id']);
        $this->assertNull($result->legacyPhotographerId);
        $this->assertTrue($result->flags['unmappablePhotographers']);
    }

    /** Case D: S>1, P=1, eligibility confirms -> assign first service only (2.6). */
    #[Test]
    public function case_d_multi_service_single_photographer_eligible_assigns_first_only(): void
    {
        $mapper = new ExternalBookingAutoMapper(fn (int $photographerId, int $serviceId) => true);

        $result = $mapper->map($this->booking($this->services([10, 11]), [42], '2026-03-01', '10:00'));

        $this->assertSame(42, $result->serviceAssignments[10]['photographer_id']);
        $this->assertNull($result->serviceAssignments[11]['photographer_id']);
        // Legacy photographer is set ONLY in case A, never in case D.
        $this->assertNull($result->legacyPhotographerId);
        $this->assertFalse($result->flags['unmappablePhotographers']);
    }

    /** Case E: S>1, P>1 -> leave all null + flag (2.7). */
    #[Test]
    public function case_e_multi_service_multi_photographer_leaves_all_null(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10, 11]), [42, 57], '2026-03-01', '10:00'));

        $this->assertNull($result->serviceAssignments[10]['photographer_id']);
        $this->assertNull($result->serviceAssignments[11]['photographer_id']);
        $this->assertNull($result->legacyPhotographerId);
        $this->assertTrue($result->flags['unmappablePhotographers']);
    }

    // ------------------------------------------------------------------
    // Schedule decision table — cases 1–4
    // ------------------------------------------------------------------

    /** Case 1: S=1 -> preferred to service + shoot level (2.9). */
    #[Test]
    public function schedule_case_1_single_service_maps_preferred_to_service_and_shoot(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10]), [], '2026-03-01', '09:30'));

        $this->assertSame('2026-03-01', $result->shootSchedule['scheduled_date']);
        $this->assertSame('09:30', $result->shootSchedule['time']);
        $this->assertSame('2026-03-01 09:30:00', $result->shootSchedule['scheduled_at']);
        $this->assertSame('2026-03-01 09:30:00', $result->serviceAssignments[10]['scheduled_at']);
        $this->assertSame([], $result->flags['unscheduledServices']);
    }

    /** Case 2: S>1 with alternate -> preferred->s1, alternate->s2, s3+ unscheduled (2.10). */
    #[Test]
    public function schedule_case_2_multi_service_with_alternate_maps_pref_s1_alt_s2(): void
    {
        $result = $this->mapper->map($this->booking(
            $this->services([10, 11, 12]),
            [],
            '2026-03-01',
            '09:00',
            '2026-03-02',
            '13:00'
        ));

        $this->assertSame('2026-03-01 09:00:00', $result->serviceAssignments[10]['scheduled_at']);
        $this->assertSame('2026-03-02 13:00:00', $result->serviceAssignments[11]['scheduled_at']);
        $this->assertNull($result->serviceAssignments[12]['scheduled_at']);
        $this->assertSame([3], $result->flags['unscheduledServices']);
        // Alternate persisted on the shoot regardless of per-service mapping.
        $this->assertSame('2026-03-02 13:00:00', $result->alternateSchedule['alternate_scheduled_at']);
    }

    /** Case 3: S>1 preferred only -> s1 only, never copy to all (2.11). */
    #[Test]
    public function schedule_case_3_multi_service_preferred_only_schedules_first_only(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10, 11, 12]), [], '2026-03-01', '09:00'));

        $this->assertSame('2026-03-01 09:00:00', $result->serviceAssignments[10]['scheduled_at']);
        $this->assertNull($result->serviceAssignments[11]['scheduled_at']);
        $this->assertNull($result->serviceAssignments[12]['scheduled_at']);
        $this->assertSame([2, 3], $result->flags['unscheduledServices']);
    }

    /** Case 4: explicit service_assignments -> per-service schedules + skip inference (2.8). */
    #[Test]
    public function schedule_case_4_explicit_assignments_apply_directly_and_skip_inference(): void
    {
        // Even though S=1,P=1 would normally be case A, explicit assignments take over.
        $booking = $this->booking(
            $this->services([10, 11]),
            [99], // would otherwise be inferred — must be ignored
            '2026-03-01',
            '09:00',
            null,
            null,
            [
                ['service_id' => 10, 'photographer_id' => 42, 'scheduled_date' => '2026-04-01', 'scheduled_time' => '08:00'],
                ['service_id' => 11, 'photographer_id' => 57, 'scheduled_date' => '2026-04-02', 'scheduled_time' => '14:00'],
            ]
        );

        $result = $this->mapper->map($booking);

        $this->assertSame(42, $result->serviceAssignments[10]['photographer_id']);
        $this->assertSame('2026-04-01 08:00:00', $result->serviceAssignments[10]['scheduled_at']);
        $this->assertSame(57, $result->serviceAssignments[11]['photographer_id']);
        $this->assertSame('2026-04-02 14:00:00', $result->serviceAssignments[11]['scheduled_at']);
        // Inference is skipped: no legacy photographer from the requested list, no inferred flags.
        $this->assertNull($result->legacyPhotographerId);
        $this->assertFalse($result->flags['multiplePhotographersForOneService']);
        $this->assertSame(MappingResult::STATUS_FULLY_MAPPED, $result->mappingStatus);
    }

    // ------------------------------------------------------------------
    // No-fabricated-time rule (2.12, 2.13, 2.14)
    // ------------------------------------------------------------------

    /** Preferred date without time -> never fabricate midnight (2.12). */
    #[Test]
    public function preferred_date_without_time_never_fabricates_midnight(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10]), [], '2026-03-01', null));

        $this->assertSame('2026-03-01', $result->shootSchedule['scheduled_date']);
        $this->assertNull($result->shootSchedule['time']);
        $this->assertNull($result->shootSchedule['scheduled_at']);
        $this->assertNull($result->serviceAssignments[10]['scheduled_at']);
        $this->assertTrue($result->flags['preferredDateMissingTime']);
        // A date-only preference forces at least partially_mapped (2.16).
        $this->assertSame(MappingResult::STATUS_PARTIALLY_MAPPED, $result->mappingStatus);
    }

    /** Alternate date without time -> never fabricate midnight (2.13). */
    #[Test]
    public function alternate_date_without_time_never_fabricates_midnight(): void
    {
        $result = $this->mapper->map($this->booking(
            $this->services([10, 11]),
            [],
            '2026-03-01',
            '09:00',
            '2026-03-02',
            null
        ));

        $this->assertSame('2026-03-02', $result->alternateSchedule['alternate_scheduled_date']);
        $this->assertNull($result->alternateSchedule['alternate_time']);
        $this->assertNull($result->alternateSchedule['alternate_scheduled_at']);
        $this->assertTrue($result->flags['alternateDateMissingTime']);
    }

    /** Both date and time present -> combine consistently (2.14). */
    #[Test]
    public function preferred_date_and_time_combine_consistently(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10]), [], '2026-03-01', '16:45'));

        $this->assertSame('2026-03-01', $result->shootSchedule['scheduled_date']);
        $this->assertSame('16:45', $result->shootSchedule['time']);
        $this->assertSame('2026-03-01 16:45:00', $result->shootSchedule['scheduled_at']);
        $this->assertFalse($result->flags['preferredDateMissingTime']);
    }

    /** No scheduling at all -> all null, no fabrication, fully_mapped when no other intent. */
    #[Test]
    public function no_scheduling_leaves_everything_null(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10]), [], null, null));

        $this->assertNull($result->shootSchedule['scheduled_date']);
        $this->assertNull($result->shootSchedule['time']);
        $this->assertNull($result->shootSchedule['scheduled_at']);
        $this->assertNull($result->serviceAssignments[10]['scheduled_at']);
        $this->assertSame(MappingResult::STATUS_FULLY_MAPPED, $result->mappingStatus);
    }

    // ------------------------------------------------------------------
    // Mapping status derivation (2.16)
    // ------------------------------------------------------------------

    /** Multi-photographer ambiguity with a schedule mapped -> partially_mapped. */
    #[Test]
    public function mapping_status_partial_when_ambiguous_but_schedule_mapped(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10]), [42, 57], '2026-03-01', '10:00'));

        $this->assertSame(MappingResult::STATUS_PARTIALLY_MAPPED, $result->mappingStatus);
    }

    /** Nothing mappable at all (multi/multi, no schedule) -> needs_review. */
    #[Test]
    public function mapping_status_needs_review_when_nothing_mapped(): void
    {
        $result = $this->mapper->map($this->booking($this->services([10, 11]), [42, 57], null, null));

        $this->assertTrue($result->flags['unmappablePhotographers']);
        $this->assertSame(MappingResult::STATUS_NEEDS_REVIEW, $result->mappingStatus);
    }

    // ------------------------------------------------------------------
    // Scoped property-based test (seeded deterministic generator)
    // ------------------------------------------------------------------

    /**
     * Property: across the input domain (S × P × preferred/alternate presence) the mapper
     *  (1) NEVER fabricates a photographer assignment — every assigned pivot id was requested;
     *  (2) in case 3 (S>1, preferred only) NEVER copies the preferred schedule onto services
     *      beyond the first;
     *  (3) NEVER fabricates a time when a date has no time;
     *  (4) always produces a valid mapping status.
     *
     * Validates: Requirements 2.3, 2.6, 2.7, 2.11, 2.12, 2.16
     */
    #[Test]
    public function property_no_fabrication_and_no_copy_to_all_in_case_three(): void
    {
        mt_srand(20260223); // deterministic / reproducible

        $servicePool = [101, 102, 103];
        $photographerPool = [201, 202, 203];
        $validStatuses = [
            MappingResult::STATUS_FULLY_MAPPED,
            MappingResult::STATUS_PARTIALLY_MAPPED,
            MappingResult::STATUS_NEEDS_REVIEW,
        ];

        $iterations = 50;

        for ($i = 0; $i < $iterations; $i++) {
            $s = mt_rand(1, 3);
            $p = mt_rand(0, 3);
            $withPreferredTime = (bool) mt_rand(0, 1);
            $withPreferredDate = (bool) mt_rand(0, 1);
            $withAlternate = (bool) mt_rand(0, 1);

            $serviceIds = array_slice($servicePool, 0, $s);
            $photographers = array_slice($photographerPool, 0, $p);

            $prefDate = $withPreferredDate ? '2026-03-01' : null;
            $prefTime = ($withPreferredDate && $withPreferredTime) ? '09:00' : null;
            $altDate = $withAlternate ? '2026-03-05' : null;
            $altTime = $withAlternate ? '15:30' : null;

            $booking = $this->booking(
                $this->services($serviceIds),
                $photographers,
                $prefDate,
                $prefTime,
                $altDate,
                $altTime
            );

            $result = $this->mapper->map($booking);

            $example = "S={$s}, P={$p}, prefDate=" . ($withPreferredDate ? 'y' : 'n')
                . ', prefTime=' . ($withPreferredTime ? 'y' : 'n')
                . ', alt=' . ($withAlternate ? 'y' : 'n');

            // (1) No fabricated photographer assignment.
            foreach ($result->serviceAssignments as $assignment) {
                if ($assignment['photographer_id'] !== null) {
                    $this->assertContains(
                        $assignment['photographer_id'],
                        $photographers,
                        "FABRICATION [$example]: assigned a photographer that was not requested."
                    );
                }
            }
            if ($result->legacyPhotographerId !== null) {
                $this->assertContains(
                    $result->legacyPhotographerId,
                    $photographers,
                    "FABRICATION [$example]: legacy photographer was not requested."
                );
                // Legacy photographer is only ever set in case A (S=1, P=1).
                $this->assertSame(1, $s, "[$example]: legacy photographer set outside case A.");
                $this->assertSame(1, $p, "[$example]: legacy photographer set outside case A.");
            }

            // (2) Case 3: S>1, preferred date present, no alternate -> never copy preferred to all.
            if ($s > 1 && $withPreferredDate && !$withAlternate) {
                $firstId = $serviceIds[0];
                for ($idx = 1; $idx < $s; $idx++) {
                    $this->assertNull(
                        $result->serviceAssignments[$serviceIds[$idx]]['scheduled_at'],
                        "COPY-TO-ALL [$example]: preferred schedule copied onto service beyond the first."
                    );
                }
                // The first service should carry the preferred schedule when a time was given.
                if ($withPreferredTime) {
                    $this->assertSame(
                        '2026-03-01 09:00:00',
                        $result->serviceAssignments[$firstId]['scheduled_at'],
                        "[$example]: first service must carry the preferred schedule."
                    );
                }
            }

            // (3) No fabricated time when a date lacks a time.
            if ($withPreferredDate && !$withPreferredTime) {
                $this->assertNull($result->shootSchedule['scheduled_at'], "MIDNIGHT [$example]: fabricated shoot scheduled_at.");
                $this->assertNull($result->shootSchedule['time'], "MIDNIGHT [$example]: fabricated shoot time.");
                $this->assertTrue($result->flags['preferredDateMissingTime'], "[$example]: missing-time flag not set.");
            }

            // (4) Valid mapping status always.
            $this->assertContains($result->mappingStatus, $validStatuses, "[$example]: invalid mapping status.");
        }
    }
}
