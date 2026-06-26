<?php

namespace Tests\Unit\Services\ExternalBooking;

use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Services\ExternalBooking\ExternalBookingNotificationService;
use App\Services\ExternalBooking\MappingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for ExternalBookingNotificationService.
 *
 * Covers:
 *   - the `needsReview` truth table (source external/internal × warnings × mapping status ×
 *     flags × multi-service schedule guess)
 *   - a notification is created exactly when review is needed and not otherwise
 *   - the persisted `shoot_assignment_review` row's metadata payload shape
 *     (type, shoot_id, title, message, action_type, action_payload)
 *
 * Validates: Requirements 2.19, 2.20
 */
class ExternalBookingNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExternalBookingNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExternalBookingNotificationService();
    }

    /**
     * Create a persisted shoot, external-sourced unless overridden.
     */
    private function shoot(bool $external = true): Shoot
    {
        return Shoot::factory()->create([
            'created_by' => $external ? 'External (external_website)' : 'admin.user',
            'updated_by' => $external ? 'External (external_website)' : 'admin.user',
        ]);
    }

    /**
     * Build a MappingResult with the given status, flags and per-service assignments.
     *
     * @param array<int, array{photographer_id: ?int, scheduled_at: ?string}> $serviceAssignments
     * @param array<string, mixed>                                            $flagOverrides
     */
    private function mapping(
        string $status = MappingResult::STATUS_FULLY_MAPPED,
        array $serviceAssignments = [],
        array $flagOverrides = []
    ): MappingResult {
        $flags = array_merge([
            'multiplePhotographersForOneService' => false,
            'unmappablePhotographers' => false,
            'unscheduledServices' => [],
            'preferredDateMissingTime' => false,
            'alternateDateMissingTime' => false,
        ], $flagOverrides);

        return new MappingResult(
            shootSchedule: ['scheduled_date' => null, 'time' => null, 'scheduled_at' => null],
            alternateSchedule: [
                'alternate_scheduled_date' => null,
                'alternate_time' => null,
                'alternate_scheduled_at' => null,
            ],
            serviceAssignments: $serviceAssignments,
            legacyPhotographerId: null,
            mappingStatus: $status,
            flags: $flags,
        );
    }

    // ------------------------------------------------------------------
    // needsReview truth table
    // ------------------------------------------------------------------

    /** Non-external source => never needs review, even when the mapping is messy. */
    #[Test]
    public function non_external_source_never_needs_review(): void
    {
        $shoot = $this->shoot(external: false);
        $mapping = $this->mapping(
            status: MappingResult::STATUS_NEEDS_REVIEW,
            flagOverrides: ['multiplePhotographersForOneService' => true],
        );

        $this->assertFalse($this->service->needsReview($shoot, $mapping, ['some warning']));
        $this->assertFalse($this->service->notifyIfNeeded($shoot, $mapping, ['some warning']));
        $this->assertReviewNotificationCount($shoot, 0);
    }

    /** External + fully mapped + no warnings/flags + single service => no review (legacy-shaped). */
    #[Test]
    public function external_clean_single_service_booking_does_not_need_review(): void
    {
        $shoot = $this->shoot();
        $mapping = $this->mapping(
            status: MappingResult::STATUS_FULLY_MAPPED,
            serviceAssignments: [10 => ['photographer_id' => null, 'scheduled_at' => '2026-03-01 11:00:00']],
        );

        $this->assertFalse($this->service->needsReview($shoot, $mapping, []));
        $this->assertFalse($this->service->notifyIfNeeded($shoot, $mapping, []));
        $this->assertReviewNotificationCount($shoot, 0);
    }

    /** External + non-empty warnings => needs review. */
    #[Test]
    public function external_with_warnings_needs_review(): void
    {
        $shoot = $this->shoot();
        $mapping = $this->mapping(status: MappingResult::STATUS_PARTIALLY_MAPPED);

        $this->assertTrue($this->service->needsReview($shoot, $mapping, ['Preferred date was provided without a time. Time requires manual review.']));
    }

    /** External + needs_review status => needs review. */
    #[Test]
    public function external_with_needs_review_status_needs_review(): void
    {
        $shoot = $this->shoot();
        $mapping = $this->mapping(status: MappingResult::STATUS_NEEDS_REVIEW);

        $this->assertTrue($this->service->needsReview($shoot, $mapping, []));
    }

    /** External + partially_mapped status => needs review. */
    #[Test]
    public function external_with_partially_mapped_status_needs_review(): void
    {
        $shoot = $this->shoot();
        $mapping = $this->mapping(status: MappingResult::STATUS_PARTIALLY_MAPPED);

        $this->assertTrue($this->service->needsReview($shoot, $mapping, []));
    }

    /** External + multiple-photographers-for-one-service flag => needs review. */
    #[Test]
    public function external_with_multiple_photographers_flag_needs_review(): void
    {
        $shoot = $this->shoot();
        $mapping = $this->mapping(
            status: MappingResult::STATUS_FULLY_MAPPED,
            flagOverrides: ['multiplePhotographersForOneService' => true],
        );

        $this->assertTrue($this->service->needsReview($shoot, $mapping, []));
    }

    /** External + unmappable-photographers flag => needs review. */
    #[Test]
    public function external_with_unmappable_photographers_flag_needs_review(): void
    {
        $shoot = $this->shoot();
        $mapping = $this->mapping(
            status: MappingResult::STATUS_FULLY_MAPPED,
            flagOverrides: ['unmappablePhotographers' => true],
        );

        $this->assertTrue($this->service->needsReview($shoot, $mapping, []));
    }

    /** External + multi-service schedule guess (fully mapped) => needs review. */
    #[Test]
    public function external_multi_service_schedule_guess_needs_review(): void
    {
        $shoot = $this->shoot();
        $mapping = $this->mapping(
            status: MappingResult::STATUS_FULLY_MAPPED,
            serviceAssignments: [
                10 => ['photographer_id' => null, 'scheduled_at' => '2026-03-01 09:00:00'],
                11 => ['photographer_id' => null, 'scheduled_at' => '2026-03-02 13:00:00'],
            ],
        );

        $this->assertTrue($this->service->needsReview($shoot, $mapping, []));
    }

    /** External + multiple services but none scheduled and no flags => no review. */
    #[Test]
    public function external_multi_service_with_no_schedule_does_not_need_review(): void
    {
        $shoot = $this->shoot();
        $mapping = $this->mapping(
            status: MappingResult::STATUS_FULLY_MAPPED,
            serviceAssignments: [
                10 => ['photographer_id' => null, 'scheduled_at' => null],
                11 => ['photographer_id' => null, 'scheduled_at' => null],
            ],
        );

        $this->assertFalse($this->service->needsReview($shoot, $mapping, []));
    }

    // ------------------------------------------------------------------
    // Notification creation
    // ------------------------------------------------------------------

    /** When review is needed, exactly one notification row is created and true is returned. */
    #[Test]
    public function creates_notification_when_review_needed(): void
    {
        $shoot = $this->shoot();
        $warnings = ['Multiple photographers were requested for one service. Please review manually.'];
        $mapping = $this->mapping(
            status: MappingResult::STATUS_PARTIALLY_MAPPED,
            flagOverrides: ['multiplePhotographersForOneService' => true],
        );

        $created = $this->service->notifyIfNeeded($shoot, $mapping, $warnings);

        $this->assertTrue($created);
        $this->assertReviewNotificationCount($shoot, 1);
    }

    /** When review is NOT needed, no notification row is created and false is returned. */
    #[Test]
    public function does_not_create_notification_when_review_not_needed(): void
    {
        $shoot = $this->shoot();
        $mapping = $this->mapping(
            status: MappingResult::STATUS_FULLY_MAPPED,
            serviceAssignments: [10 => ['photographer_id' => 42, 'scheduled_at' => '2026-03-01 11:00:00']],
        );

        $created = $this->service->notifyIfNeeded($shoot, $mapping, []);

        $this->assertFalse($created);
        $this->assertReviewNotificationCount($shoot, 0);
    }

    // ------------------------------------------------------------------
    // Payload shape (2.20)
    // ------------------------------------------------------------------

    /** The created notification carries the exact structured metadata payload (2.20). */
    #[Test]
    public function notification_payload_has_expected_shape(): void
    {
        $shoot = $this->shoot();
        $warnings = ['Preferred date was provided without a time. Time requires manual review.'];
        $mapping = $this->mapping(
            status: MappingResult::STATUS_PARTIALLY_MAPPED,
            flagOverrides: ['preferredDateMissingTime' => true],
        );

        $this->service->notifyIfNeeded($shoot, $mapping, $warnings);

        $log = ShootActivityLog::query()
            ->where('shoot_id', $shoot->id)
            ->where('action', 'shoot_assignment_review')
            ->firstOrFail();

        $metadata = $log->metadata;

        $this->assertIsArray($metadata);
        $this->assertSame('shoot_assignment_review', $metadata['type']);
        $this->assertSame($shoot->id, $metadata['shoot_id']);
        $this->assertSame('Booking Needs Review', $metadata['title']);
        $this->assertNotEmpty($metadata['message']);
        $this->assertSame('open_shoot_details_popup', $metadata['action_type']);
        $this->assertSame(
            ['shoot_id' => $shoot->id, 'focus' => 'schedule_assignments'],
            $metadata['action_payload']
        );

        // description mirrors the message.
        $this->assertSame($metadata['message'], $log->description);
        // warnings are surfaced in the message.
        $this->assertStringContainsString('Time requires manual review.', $metadata['message']);
    }

    private function assertReviewNotificationCount(Shoot $shoot, int $expected): void
    {
        $this->assertSame(
            $expected,
            ShootActivityLog::query()
                ->where('shoot_id', $shoot->id)
                ->where('action', 'shoot_assignment_review')
                ->count()
        );
    }
}
