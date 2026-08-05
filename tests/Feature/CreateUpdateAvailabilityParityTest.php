<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Create/update availability-validation parity test (MANDATORY, tasks.md 3.3).
 *
 * Feature: booking-scheduling-fixes, Property 9
 *
 * Property 9 — Create and update enforce identical backend-authoritative bounds:
 * For any photographer, date, and candidate time, the acceptance decision of the
 * create path equals the acceptance decision of the update path, both derived
 * from the backend-computed effective window. A time outside that window is
 * rejected with a structured 422 error keyed on `start_time`, while an in-bounds
 * time is accepted; no frontend-only value can authorize a booking.
 *
 * Both paths exercise the REAL PhotographerAvailabilityService so the single
 * shared bounds method (assertWithinAvailabilityBounds) is actually enforced.
 * With no configured availability slots, the single canonical Backend_Fallback_Hours
 * (config/availability.php fallback_start_time/fallback_end_time = 09:00/18:00)
 * is the authoritative window. Only the non-availability side effects are faked.
 *
 * Validates: Requirements 2.1, 2.2, 2.3, 2.5, 3.4, 12.1, 12.2
 */
class CreateUpdateAvailabilityParityTest extends TestCase
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
        // bound is genuinely enforced on BOTH the create and update paths.
        $this->bindSideEffectFakes();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Parity Admin',
            'email' => 'parity-admin@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'name' => 'Parity Client',
            'email' => 'parity-client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Parity Photographer',
            'email' => 'parity-photographer@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150.00,
        ]);
    }

    /**
     * Candidate wall-clock start times and their expected acceptance against the
     * single canonical Backend_Fallback_Hours window (09:00–18:00) for a
     * photographer with no configured slots. Used as a multi-case / property-style
     * sweep so parity is checked across the whole input space, not a single point.
     *
     * @return array<int, array{0: string, 1: bool}>
     */
    protected function candidateTimes(): array
    {
        return [
            // [time, expectedAccept]
            ['06:00', false], // before fallback start
            ['07:30', false], // before fallback start
            ['08:59', false], // just before fallback start
            ['09:00', true],  // at fallback start (fully contained)
            ['11:00', true],  // mid-window
            ['14:30', true],  // mid-window
            ['17:00', true],  // start within window (end spills past, start rule applies)
            ['18:30', false], // after fallback end
            ['20:00', false], // after fallback end
        ];
    }

    /**
     * Property 9: for every candidate photographer/date/time, the create-path and
     * update-path acceptance decisions are identical and match the backend-computed
     * effective window. Out-of-window => rejected; in-window => accepted.
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function create_and_update_reach_identical_decisions_for_each_time(): void
    {
        foreach ($this->candidateTimes() as $index => [$time, $expectedAccept]) {
            // A date unique to this candidate so create-path conflict checks never
            // interfere across candidates; create and update share THIS date+time.
            $scheduledAt = $this->candidateDateAt($time, $index + 1);

            $createAccepted = $this->attemptCreate($scheduledAt);
            $updateAccepted = $this->attemptUpdate($scheduledAt, $index);

            // Core parity assertion: both paths agree for the same photographer/date/time.
            $this->assertSame(
                $createAccepted,
                $updateAccepted,
                "Create and update disagreed for {$scheduledAt} (create=" .
                ($createAccepted ? 'accept' : 'reject') . ', update=' .
                ($updateAccepted ? 'accept' : 'reject') . ')'
            );

            // And both agree with the backend-authoritative window expectation.
            $this->assertSame(
                $expectedAccept,
                $createAccepted,
                "Unexpected decision for {$scheduledAt}; expected " .
                ($expectedAccept ? 'accept' : 'reject')
            );
        }
    }

    /**
     * An out-of-window time is rejected by BOTH paths with a structured 422 keyed
     * on `start_time` (not a bare boolean / generic error).
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function out_of_window_time_is_rejected_by_both_paths_with_structured_422(): void
    {
        Sanctum::actingAs($this->admin);

        // 19:30 is after the Backend_Fallback_Hours end (18:00).
        $outOfBounds = $this->candidateDateAt('19:30', 20);

        $createResponse = $this->postJson('/api/shoots', $this->createPayload($outOfBounds));
        $createResponse->assertStatus(422)
            ->assertJsonValidationErrors('start_time');
        $this->assertNotEmpty($createResponse->json('errors.start_time'));

        $shoot = $this->createScheduledShoot(21);
        $updateResponse = $this->patchJson("/api/shoots/{$shoot->id}", [
            'scheduled_at' => $outOfBounds,
            'photographer_id' => $this->photographer->id,
        ]);
        $updateResponse->assertStatus(422)
            ->assertJsonValidationErrors('start_time');
        $this->assertNotEmpty($updateResponse->json('errors.start_time'));

        // The rejected time must not have been persisted on the update target.
        $shoot->refresh();
        $this->assertNotSame($outOfBounds, $shoot->scheduled_at?->format('Y-m-d H:i:s'));
    }

    /**
     * An in-window time is accepted by BOTH paths and persisted on the update path.
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function in_window_time_is_accepted_by_both_paths(): void
    {
        Sanctum::actingAs($this->admin);

        // 11:00 is within the Backend_Fallback_Hours window (09:00–18:00).
        $inBounds = $this->candidateDateAt('11:00', 22);

        $createResponse = $this->postJson('/api/shoots', $this->createPayload($inBounds));
        $createResponse->assertCreated()
            ->assertJsonMissingValidationErrors('start_time');

        $shoot = $this->createScheduledShoot(23);
        $updateResponse = $this->patchJson("/api/shoots/{$shoot->id}", [
            'scheduled_at' => $inBounds,
            'photographer_id' => $this->photographer->id,
        ]);
        $updateResponse->assertOk()
            ->assertJsonMissingValidationErrors('start_time');

        $shoot->refresh();
        $this->assertSame($inBounds, $shoot->scheduled_at?->format('Y-m-d H:i:s'));
    }

    /**
     * Attempt the create path as an admin booking. Returns true when the shoot is
     * created (in-window) and false when rejected with a structured `start_time`
     * 422 (out-of-window). Any other failure fails the test loudly.
     */
    protected function attemptCreate(string $scheduledAt): bool
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/shoots', $this->createPayload($scheduledAt));

        if ($response->getStatusCode() === 201) {
            return true;
        }

        // A rejection must be the structured bound error keyed on start_time.
        $response->assertStatus(422)->assertJsonValidationErrors('start_time');

        return false;
    }

    /**
     * Attempt the update path as an admin reschedule of an existing scheduled shoot
     * (created on a far date so it never conflicts with the create-path shoot).
     * Returns true on success (in-window) and false on a structured `start_time`
     * 422 (out-of-window).
     */
    protected function attemptUpdate(string $scheduledAt, int $index): bool
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createScheduledShoot(100 + $index);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'scheduled_at' => $scheduledAt,
            'photographer_id' => $this->photographer->id,
        ]);

        if ($response->getStatusCode() === 200) {
            return true;
        }

        $response->assertStatus(422)->assertJsonValidationErrors('start_time');

        return false;
    }

    /**
     * Build the admin-create payload for a given canonical scheduled_at.
     *
     * @return array<string, mixed>
     */
    protected function createPayload(string $scheduledAt): array
    {
        return [
            'client_id' => $this->client->id,
            'address' => '500 Parity Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $this->service->id, 'quantity' => 1],
            ],
            'photographer_id' => $this->photographer->id,
            'scheduled_at' => $scheduledAt,
        ];
    }

    /**
     * Create a scheduled shoot directly (bypassing validation) on a far-future
     * date so it can be rescheduled by the update path without any cross-candidate
     * booking conflict. The baseline 10:00 time is always in-window.
     */
    protected function createScheduledShoot(int $weekOffset): Shoot
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'address' => '500 Parity Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'scheduled_at' => $this->candidateDateAt('10:00', 200 + $weekOffset),
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
     * A distinct future weekday date at the given local time, formatted as the
     * canonical `Y-m-d H:i:s` the endpoints accept. The window bound depends only
     * on the wall-clock time, so a unique date per candidate isolates conflicts
     * while preserving the same time-of-day decision.
     */
    protected function candidateDateAt(string $time, int $weekOffset): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return now()
            ->addWeeks(max(1, $weekOffset))
            ->next(\Carbon\Carbon::MONDAY)
            ->setTime($hour, $minute, 0)
            ->format('Y-m-d H:i:s');
    }

    protected function bindSideEffectFakes(): void
    {
        $dropboxService = Mockery::mock(DropboxWorkflowService::class);
        $dropboxService->shouldIgnoreMissing();
        $dropboxService->shouldReceive('createShootFolders')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(DropboxWorkflowService::class, $dropboxService);

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
