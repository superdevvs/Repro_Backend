<?php

namespace Tests\Unit\Services\ExternalBooking;

use App\Services\ExternalBooking\ExternalBookingAutoMapper;
use App\Services\ExternalBooking\ExternalBookingWarningBuilder;
use App\Services\ExternalBooking\MappingResult;
use App\Services\ExternalBooking\NormalizedBooking;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExternalBookingWarningBuilder.
 *
 * The builder is a PURE, stateless function of the NormalizedBooking + MappingResult, so
 * these tests drive it with NormalizedBooking inputs run through the real
 * ExternalBookingAutoMapper (exercising the actual flag combinations the builder consumes)
 * and assert the exact warning strings from the design catalog.
 *
 * Coverage:
 *   - each warning string emitted for the right flag combination (2.5, 2.6, 2.7, 2.10/2.11, 2.12, 2.13)
 *   - case D vs case E disambiguation via the requested-photographer count
 *   - no warnings when fully mapped
 *
 * Validates: Requirements 2.5, 2.6, 2.7, 2.10, 2.11, 2.12, 2.13
 */
class ExternalBookingWarningBuilderTest extends TestCase
{
    private ExternalBookingWarningBuilder $builder;
    private ExternalBookingAutoMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ExternalBookingWarningBuilder();
        // Conservative eligibility (case D leaves the single photographer unassigned).
        $this->mapper = new ExternalBookingAutoMapper();
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id:int, quantity:int}>
     */
    private function services(array $ids): array
    {
        return array_map(fn (int $id) => ['id' => $id, 'quantity' => 1], $ids);
    }

    /**
     * @param array<int, array{id:int, quantity:int}> $services
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

    private function buildFor(NormalizedBooking $booking): array
    {
        return $this->builder->build($booking, $this->mapper->map($booking));
    }

    // ------------------------------------------------------------------
    // Each catalog string for the right flag combination
    // ------------------------------------------------------------------

    /** 2.5 — case C: single service, multiple photographers. */
    #[Test]
    public function emits_multiple_photographers_for_one_service_warning(): void
    {
        $warnings = $this->buildFor($this->booking($this->services([10]), [42, 57], '2026-03-01', '10:00'));

        $this->assertContains(
            'Multiple photographers were requested for one service. Please review manually.',
            $warnings
        );
    }

    /** 2.7 — case E: multiple services, multiple photographers. */
    #[Test]
    public function emits_multiple_photographers_across_multiple_services_warning(): void
    {
        $warnings = $this->buildFor($this->booking($this->services([10, 11]), [42, 57], '2026-03-01', '10:00'));

        $this->assertContains(
            'Multiple photographers were requested across multiple services. Please assign manually.',
            $warnings
        );
        // Must NOT emit the single-photographer (case D) variant.
        $this->assertNotContains(
            'A single photographer was requested for multiple services. Assignment left for manual review.',
            $warnings
        );
    }

    /** 2.6 — case D: multiple services, single photographer, eligibility cannot confirm. */
    #[Test]
    public function emits_single_photographer_for_multiple_services_warning(): void
    {
        $warnings = $this->buildFor($this->booking($this->services([10, 11]), [42], '2026-03-01', '10:00'));

        $this->assertContains(
            'A single photographer was requested for multiple services. Assignment left for manual review.',
            $warnings
        );
        // Must NOT emit the multi-photographer (case E) variant.
        $this->assertNotContains(
            'Multiple photographers were requested across multiple services. Please assign manually.',
            $warnings
        );
    }

    /** 2.6 — case D suppressed when eligibility confirms the assignment is safe. */
    #[Test]
    public function no_unmappable_warning_when_case_d_eligibility_confirms(): void
    {
        $eligibleMapper = new ExternalBookingAutoMapper(fn (int $p, int $s) => true);
        $booking = $this->booking($this->services([10, 11]), [42], '2026-03-01', '10:00');

        $warnings = $this->builder->build($booking, $eligibleMapper->map($booking));

        $this->assertNotContains(
            'A single photographer was requested for multiple services. Assignment left for manual review.',
            $warnings
        );
    }

    /** 2.10 / 2.11 — unscheduled services each get their own warning with the right position. */
    #[Test]
    public function emits_unscheduled_service_warning_per_position(): void
    {
        // S=3, preferred only -> services #2 and #3 unscheduled.
        $warnings = $this->buildFor($this->booking($this->services([10, 11, 12]), [], '2026-03-01', '09:00'));

        $this->assertContains(
            'Service #2 could not be scheduled automatically and needs manual scheduling.',
            $warnings
        );
        $this->assertContains(
            'Service #3 could not be scheduled automatically and needs manual scheduling.',
            $warnings
        );
    }

    /**
     * 2.10 — with an alternate present, the alternate is no longer mapped onto service #2.
     * Services #2..N are all left unscheduled by the auto-mapper, so both #2 and #3 are flagged.
     */
    #[Test]
    public function emits_unscheduled_service_warnings_for_second_and_third_services_with_alternate(): void
    {
        $warnings = $this->buildFor($this->booking(
            $this->services([10, 11, 12]),
            [],
            '2026-03-01',
            '09:00',
            '2026-03-02',
            '13:00'
        ));

        // The alternate is persisted on the shoot only; it is never applied to a service,
        // so service #2 is now unscheduled alongside service #3.
        $this->assertContains(
            'Service #2 could not be scheduled automatically and needs manual scheduling.',
            $warnings
        );
        $this->assertContains(
            'Service #3 could not be scheduled automatically and needs manual scheduling.',
            $warnings
        );
    }

    /** 2.12 — preferred date without a time. */
    #[Test]
    public function emits_preferred_date_missing_time_warning(): void
    {
        $warnings = $this->buildFor($this->booking($this->services([10]), [], '2026-03-01', null));

        $this->assertContains(
            'Preferred date was provided without a time. Time requires manual review.',
            $warnings
        );
    }

    /** 2.13 — alternate date without a time. */
    #[Test]
    public function emits_alternate_date_missing_time_warning(): void
    {
        $warnings = $this->buildFor($this->booking(
            $this->services([10, 11]),
            [],
            '2026-03-01',
            '09:00',
            '2026-03-02',
            null
        ));

        $this->assertContains(
            'Alternate date was provided without a time. Time requires manual review.',
            $warnings
        );
    }

    // ------------------------------------------------------------------
    // No warnings when fully mapped
    // ------------------------------------------------------------------

    /** Case A fully mapped (S=1, P=1, preferred date+time) -> no warnings. */
    #[Test]
    public function no_warnings_when_fully_mapped(): void
    {
        $warnings = $this->buildFor($this->booking($this->services([10]), [42], '2026-03-01', '10:00'));

        $this->assertSame([], $warnings);
    }

    /** No scheduling, no photographers -> no warnings. */
    #[Test]
    public function no_warnings_for_bare_single_service_booking(): void
    {
        $warnings = $this->buildFor($this->booking($this->services([10]), []));

        $this->assertSame([], $warnings);
    }

    // ------------------------------------------------------------------
    // Combined flags accumulate all relevant warnings
    // ------------------------------------------------------------------

    /** Multiple independent issues -> all corresponding warnings present. */
    #[Test]
    public function accumulates_multiple_warnings(): void
    {
        // S=3, P>1 (case E), preferred date only (date-only + unscheduled #2/#3).
        $warnings = $this->buildFor($this->booking($this->services([10, 11, 12]), [42, 57], '2026-03-01', null));

        $this->assertContains(
            'Multiple photographers were requested across multiple services. Please assign manually.',
            $warnings
        );
        $this->assertContains(
            'Preferred date was provided without a time. Time requires manual review.',
            $warnings
        );
        $this->assertContains(
            'Service #2 could not be scheduled automatically and needs manual scheduling.',
            $warnings
        );
        $this->assertContains(
            'Service #3 could not be scheduled automatically and needs manual scheduling.',
            $warnings
        );
    }
}
