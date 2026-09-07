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
 * Backend out-of-hours rejection test (MANDATORY, tasks.md 3.4).
 *
 * Asserts that an out-of-bounds shoot update is rejected with HTTP 422 and a
 * structured `errors.start_time` bound message, while an in-bounds update
 * succeeds (2xx). Both paths exercise the REAL PhotographerAvailabilityService
 * so the backend-authoritative effective window (the single canonical
 * Backend_Fallback_Hours of 09:00–18:00 when no slots are configured) is
 * actually enforced — side effects are faked, availability is not.
 *
 * Validates: Requirements 2.2, 2.5
 */
class ShootUpdateOutOfHoursRejectionTest extends TestCase
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

        // Fake only the non-availability side effects. The real
        // PhotographerAvailabilityService is left bound so the configured-hours
        // bound is genuinely enforced on the update path.
        $this->bindSideEffectFakes();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Bounds Admin',
            'email' => 'bounds-admin@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'name' => 'Bounds Client',
            'email' => 'bounds-client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Bounds Photographer',
            'email' => 'bounds-photographer@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150.00,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_outside_effective_hours_is_rejected_with_structured_422(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createScheduledShoot();

        // 19:30 is after the Backend_Fallback_Hours end (18:00) => outside the
        // photographer's effective availability bounds.
        $outOfBounds = $this->futureDateAt('19:30');

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'scheduled_at' => $outOfBounds,
            'photographer_id' => $this->photographer->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_time');

        // The structured bound message references the photographer's available
        // hours, distinguishing it from a booking conflict.
        $this->assertNotEmpty($response->json('errors.start_time'));
        $this->assertStringContainsStringIgnoringCase(
            'available',
            (string) $response->json('errors.start_time.0')
        );

        // The out-of-bounds time must NOT have been persisted.
        $shoot->refresh();
        $this->assertNotSame(
            $outOfBounds,
            $shoot->scheduled_at?->format('Y-m-d H:i:s')
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_within_effective_hours_succeeds(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = $this->createScheduledShoot();

        // 11:00 is within the Backend_Fallback_Hours window (09:00–18:00).
        $inBounds = $this->futureDateAt('11:00');

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'scheduled_at' => $inBounds,
            'photographer_id' => $this->photographer->id,
        ]);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors('start_time');

        $shoot->refresh();
        $this->assertSame(
            $inBounds,
            $shoot->scheduled_at?->format('Y-m-d H:i:s')
        );
    }

    protected function createScheduledShoot(): Shoot
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'address' => '500 Bounds Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'scheduled_at' => $this->futureDateAt('10:00'),
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
     * A fixed future weekday date at the given local time, formatted as the
     * canonical `Y-m-d H:i:s` the update endpoint accepts.
     */
    protected function futureDateAt(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return now()
            ->addWeek()
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
