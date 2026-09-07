<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Backend out-of-hours rejection test for the UPDATE path (MANDATORY, tasks.md 3.4).
 *
 * Feature: booking-scheduling-fixes
 *
 * Focuses narrowly on the reschedule/update endpoint (PATCH /api/shoots/{id}):
 *  - An out-of-bounds update (19:30, after the canonical Backend_Fallback_Hours
 *    end of 18:00) is rejected with HTTP 422 and a STRUCTURED validation error
 *    keyed on `start_time` whose message identifies the violated bound, and the
 *    rejected time is NOT persisted.
 *  - An in-bounds update (11:00, inside the 09:00–18:00 fallback window) succeeds
 *    with HTTP 200 and the new time IS persisted.
 *
 * The photographer has no configured availability slots, so the single canonical
 * Backend_Fallback_Hours (config/availability.php fallback_start_time /
 * fallback_end_time = 09:00 / 18:00) is the authoritative window. The REAL
 * PhotographerAvailabilityService stays bound so the shared bounds method
 * (assertWithinAvailabilityBounds) is genuinely enforced on the update path
 * (admin update no longer bypasses the configured-hours bound). Only the
 * non-availability side effects are faked.
 *
 * This is intentionally DISTINCT from CreateUpdateAvailabilityParityTest, which
 * proves create/update decision parity; this class isolates the update-path
 * 422 contract and persistence behavior.
 *
 * Validates: Requirements 2.2, 2.5
 */
class UpdateOutOfHoursRejectionTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected User $photographer;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake only the non-availability side effects so the REAL
        // PhotographerAvailabilityService stays bound and the configured-hours
        // bound is genuinely enforced on the update path.
        $this->bindSideEffectFakes();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'OOH Admin',
            'email' => 'ooh-admin@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'name' => 'OOH Client',
            'email' => 'ooh-client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'OOH Photographer',
            'email' => 'ooh-photographer@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150.00,
        ]);
    }

    /**
     * An out-of-bounds reschedule (19:30, past the 18:00 fallback end) is rejected
     * with HTTP 422, a structured `start_time` validation error carrying the bound
     * message, and the original time is left unchanged.
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function out_of_hours_update_is_rejected_with_structured_422(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createScheduledShoot();
        $originalScheduledAt = $shoot->scheduled_at?->format('Y-m-d H:i:s');

        // 19:30 is after the Backend_Fallback_Hours end (18:00).
        $outOfBounds = $this->futureWeekdayAt('19:30');

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'scheduled_at' => $outOfBounds,
            'photographer_id' => $this->photographer->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_time');

        // Structured bound message (not a bare/generic error).
        $startTimeErrors = $response->json('errors.start_time');
        $this->assertNotEmpty($startTimeErrors);

        $message = $startTimeErrors[0];
        $this->assertStringContainsStringIgnoringCase('outside this window', $message);
        // The message references the canonical fallback window bounds.
        $this->assertStringContainsString('09:00', $message);
        $this->assertStringContainsString('18:00', $message);

        // The rejected time must NOT have been persisted.
        $shoot->refresh();
        $this->assertNotSame(
            $outOfBounds,
            $shoot->scheduled_at?->format('Y-m-d H:i:s'),
            'Out-of-hours time must not be persisted on rejection.'
        );
        $this->assertSame(
            $originalScheduledAt,
            $shoot->scheduled_at?->format('Y-m-d H:i:s'),
            'Original scheduled time must be preserved after a rejected update.'
        );
    }

    /**
     * An in-bounds reschedule (11:00, inside the 09:00–18:00 fallback window)
     * succeeds with HTTP 200 and persists the new time.
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function in_hours_update_succeeds_and_persists(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createScheduledShoot();

        // 11:00 is within the Backend_Fallback_Hours window (09:00–18:00).
        $inBounds = $this->futureWeekdayAt('11:00');

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'scheduled_at' => $inBounds,
            'photographer_id' => $this->photographer->id,
        ]);

        $response->assertOk()
            ->assertJsonMissingValidationErrors('start_time');

        $shoot->refresh();
        $this->assertSame(
            $inBounds,
            $shoot->scheduled_at?->format('Y-m-d H:i:s'),
            'In-hours time must be persisted on a successful update.'
        );
    }

    /**
     * Create a scheduled shoot directly (bypassing validation) at a far-future,
     * in-window baseline time (10:00) so it can be the reschedule target without
     * any pre-existing out-of-bounds state.
     */
    protected function createScheduledShoot(): Shoot
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'address' => '500 Out Of Hours Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'scheduled_at' => $this->futureWeekdayAt('10:00'),
        ]);

        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
        ]);

        return $shoot;
    }

    /**
     * A single far-future weekday (next Monday a few weeks out) at the given local
     * time, formatted as the canonical `Y-m-d H:i:s` the endpoint accepts. The
     * window bound depends only on the wall-clock time; using one date keeps the
     * baseline and the rescheduled time on the same day so the only variable is
     * whether the time is inside the 09:00–18:00 window.
     */
    protected function futureWeekdayAt(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return now()
            ->addWeeks(3)
            ->next(\Carbon\Carbon::MONDAY)
            ->setTime($hour, $minute, 0)
            ->format('Y-m-d H:i:s');
    }

    protected function bindSideEffectFakes(): void
    {
        $dropboxService = Mockery::mock(ShootMediaStorageService::class);
        $dropboxService->shouldIgnoreMissing();
        $dropboxService->shouldReceive('createShootFolders')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(ShootMediaStorageService::class, $dropboxService);

        $invoiceService = Mockery::mock(InvoiceService::class);
        $invoiceService->shouldIgnoreMissing();
        $invoiceService->shouldReceive('generateForShoot')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(InvoiceService::class, $invoiceService);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('captureShootSnapshot')->zeroOrMoreTimes()->andReturn([]);
        $mailService->shouldReceive('buildShootChangeSummary')->zeroOrMoreTimes()->andReturn([
            'summary' => 'Shoot details updated',
            'html' => '<p>Shoot details updated</p>',
        ]);
        $mailService->shouldReceive('sendShootUpdatedEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('sendShootScheduledEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('sendShootRemovedEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('generatePaymentLink')->zeroOrMoreTimes()->andReturn('https://example.test/payment');
        $this->app->instance(MailService::class, $mailService);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $shoot) => [
                'shoot' => $shoot,
                'shoot_id' => $shoot->id,
                'client' => $shoot->client,
                'photographer' => $shoot->photographer,
                'photographers' => $shoot->photographer ? [$shoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')->zeroOrMoreTimes()->andReturn([
            'trigger_type' => 'SHOOT_UPDATED',
            'active_rule_count' => 0,
            'run_count' => 0,
            'completed_run_count' => 0,
            'waiting_run_count' => 0,
            'failed_run_count' => 0,
            'handled' => false,
            'errors' => [],
            'email_sent_to' => [],
            'client_email_sent' => false,
            'photographer_email_sent' => false,
        ]);
        $automationService->shouldReceive('shouldUseFallback')->zeroOrMoreTimes()->andReturnTrue();
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);
    }
}
