<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Models\AccountLink;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootEmailDelivery;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\PhotographerAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ShootMutationActionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected User $photographer;
    protected User $salesRep;
    protected Service $service;
    protected Service $secondService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindMutationSideEffectFakes();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Mutation Admin',
            'email' => 'mutation-admin@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'name' => 'Mutation Client',
            'email' => 'mutation-client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Mutation Photographer',
            'email' => 'mutation-photographer@test.com',
        ]);

        $this->salesRep = User::factory()->create([
            'role' => 'salesRep',
            'name' => 'Mutation Sales Rep',
            'email' => 'mutation-sales-rep@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150.00,
        ]);

        $this->secondService = Service::factory()->create([
            'name' => 'Floor Plan',
            'price' => 90.00,
        ]);
    }

    /** @test */
    public function client_request_creation_keeps_requested_flow_and_broadcasts_activity(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->client);

        $response = $this->postJson('/api/shoots', [
            'address' => '123 Client Request St',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'services' => [
                ['id' => $this->service->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', Shoot::STATUS_REQUESTED);

        $shoot = Shoot::query()->firstOrFail();

        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);
        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->workflow_status);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'shoot_requested',
            'user_id' => $this->client->id,
        ]);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'shoot_requested';
        });
    }

    /** @test */
    public function admin_booking_is_rejected_when_selected_client_has_no_primary_email(): void
    {
        Sanctum::actingAs($this->admin);

        $clientWithoutEmail = User::factory()->create([
            'role' => 'client',
            'name' => 'Client Without Email',
            'email' => ' ',
        ]);

        $response = $this->postJson('/api/shoots', [
            'client_id' => $clientWithoutEmail->id,
            'address' => '400 Missing Email Blvd',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $this->service->id, 'quantity' => 1],
            ],
            'scheduled_at' => now()->addDays(3)->setTime(11, 0)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('client_id');

        $this->assertSame(
            'Selected client must have a primary email before booking a shoot.',
            $response->json('errors.client_id.0')
        );
        $this->assertDatabaseCount('shoots', 0);
    }

    /** @test */
    public function client_self_booking_is_rejected_when_primary_email_is_missing(): void
    {
        $clientWithoutEmail = User::factory()->create([
            'role' => 'client',
            'name' => 'Self Booking Missing Email',
            'email' => '  ',
        ]);

        Sanctum::actingAs($clientWithoutEmail);

        $response = $this->postJson('/api/shoots', [
            'address' => '401 Missing Email Blvd',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $this->service->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('client_id');

        $this->assertSame(
            'Selected client must have a primary email before booking a shoot.',
            $response->json('errors.client_id.0')
        );
        $this->assertDatabaseCount('shoots', 0);
    }

    /** @test */
    public function admin_can_schedule_a_hold_shoot_after_refactor(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => 'hold_on',
            'workflow_status' => Shoot::STATUS_ON_HOLD,
            'base_quote' => 180,
            'tax_amount' => 10.80,
            'total_quote' => 190.80,
            'address' => '10 Hold Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);
        $this->attachPrimaryService($shoot);

        $scheduledAt = now()->addDays(3)->setTime(10, 30)->format('Y-m-d H:i:s');

        $response = $this->postJson("/api/shoots/{$shoot->id}/schedule", [
            'scheduled_at' => $scheduledAt,
            'photographer_id' => $this->photographer->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', (string) $shoot->id);

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->status);
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->workflow_status);
        $this->assertSame($scheduledAt, $shoot->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'shoot_resumed_from_hold',
            'user_id' => $this->admin->id,
        ]);
    }

    /** @test */
    public function scheduling_falls_back_when_scheduled_automation_did_not_send_client_email(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => 'hold_on',
            'workflow_status' => Shoot::STATUS_ON_HOLD,
            'address' => '12 Hold Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);
        $this->attachPrimaryService($shoot);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $targetShoot) => [
                'shoot' => $targetShoot,
                'shoot_id' => $targetShoot->id,
                'client' => $targetShoot->client,
                'photographer' => $targetShoot->photographer,
                'photographers' => $targetShoot->photographer ? [$targetShoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_SCHEDULED')
            ->andReturn([
                'trigger_type' => 'SHOOT_SCHEDULED',
                'active_rule_count' => 1,
                'run_count' => 1,
                'completed_run_count' => 1,
                'waiting_run_count' => 0,
                'failed_run_count' => 0,
                'handled' => true,
                'errors' => [],
                'email_sent_to' => ['ops@test.com'],
                'client_email_sent' => false,
                'photographer_email_sent' => false,
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_SCHEDULED', Mockery::type('array'))
            ->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $this->rebindMailService(function ($mailService) use ($shoot) {
            $mailService->shouldReceive('sendShootScheduledEmail')
                ->once()
                ->withArgs(function (User $recipient, Shoot $scheduledShoot, string $paymentLink, ?bool $notifyPhotographer = null) use ($shoot) {
                    return $recipient->is($this->client)
                        && $scheduledShoot->id === $shoot->id
                        && $paymentLink === 'https://example.test/payment'
                        && $notifyPhotographer === false;
                })
                ->andReturnTrue();
            $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->once()->andReturnTrue();
        });

        $response = $this->postJson("/api/shoots/{$shoot->id}/schedule", [
            'scheduled_at' => now()->addDays(3)->setTime(10, 30)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('shoot_email_deliveries', [
            'shoot_id' => $shoot->id,
            'recipient_user_id' => $this->client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SENT,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
        ]);
    }

    /** @test */
    public function scheduling_records_automation_client_confirmation_delivery_when_automation_sent_the_client_email(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => 'hold_on',
            'workflow_status' => Shoot::STATUS_ON_HOLD,
        ]);
        $this->attachPrimaryService($shoot);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $targetShoot) => [
                'shoot' => $targetShoot,
                'shoot_id' => $targetShoot->id,
                'client' => $targetShoot->client,
                'photographer' => $targetShoot->photographer,
                'photographers' => $targetShoot->photographer ? [$targetShoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_SCHEDULED')
            ->andReturn([
                'trigger_type' => 'SHOOT_SCHEDULED',
                'active_rule_count' => 1,
                'run_count' => 1,
                'completed_run_count' => 1,
                'waiting_run_count' => 0,
                'failed_run_count' => 0,
                'handled' => true,
                'errors' => [],
                'email_sent_to' => [$this->client->email, $this->photographer->email],
                'client_email_sent' => true,
                'photographer_email_sent' => true,
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_SCHEDULED', Mockery::type('array'))
            ->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $this->rebindMailService(function ($mailService) {
            $mailService->shouldReceive('sendShootScheduledEmail')->never();
            $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->never();
        });

        $response = $this->postJson("/api/shoots/{$shoot->id}/schedule", [
            'scheduled_at' => now()->addDays(3)->setTime(10, 30)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('shoot_email_deliveries', [
            'shoot_id' => $shoot->id,
            'recipient_user_id' => $this->client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SENT,
            'source' => ShootEmailDelivery::SOURCE_AUTOMATION,
        ]);
    }

    /** @test */
    public function scheduling_records_skipped_client_confirmation_delivery_when_client_email_is_missing(): void
    {
        Sanctum::actingAs($this->admin);

        $clientWithoutEmail = User::factory()->create([
            'role' => 'client',
            'email' => '  ',
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $clientWithoutEmail->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => 'hold_on',
            'workflow_status' => Shoot::STATUS_ON_HOLD,
        ]);
        $this->attachPrimaryService($shoot);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $targetShoot) => [
                'shoot' => $targetShoot,
                'shoot_id' => $targetShoot->id,
                'client' => $targetShoot->client,
                'photographer' => $targetShoot->photographer,
                'photographers' => $targetShoot->photographer ? [$targetShoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_SCHEDULED')
            ->andReturn($this->emptyAutomationDispatchSummary('SHOOT_SCHEDULED'));
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_SCHEDULED', Mockery::type('array'))
            ->andReturnTrue();
        $this->app->instance(AutomationService::class, $automationService);

        $this->rebindMailService(function ($mailService) {
            $mailService->shouldReceive('sendShootScheduledEmail')->never();
            $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->once()->andReturnTrue();
        });

        $response = $this->postJson("/api/shoots/{$shoot->id}/schedule", [
            'scheduled_at' => now()->addDays(3)->setTime(10, 30)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('shoot_email_deliveries', [
            'shoot_id' => $shoot->id,
            'recipient_user_id' => $clientWithoutEmail->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SKIPPED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_MISSING_EMAIL,
        ]);
    }

    /** @test */
    public function admin_can_approve_a_requested_shoot_after_refactor(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'address' => '22 Approval Ave',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);
        $this->attachPrimaryService($shoot);

        $scheduledAt = now()->addDays(5)->setTime(14, 0)->format('Y-m-d H:i:s');

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve", [
            'scheduled_at' => $scheduledAt,
            'photographer_id' => $this->photographer->id,
            'notes' => 'Approved for dispatch',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', (string) $shoot->id);

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->status);
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->workflow_status);
        $this->assertSame($this->photographer->id, $shoot->photographer_id);
        $this->assertNotNull($shoot->approved_at);

        $activity = DB::table('shoot_activity_logs')
            ->where('shoot_id', $shoot->id)
            ->where('action', 'shoot_approved')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $metadata = json_decode($activity->metadata ?? '[]', true);
        $this->assertSame('Approved for dispatch', $metadata['notes'] ?? null);
        $this->assertSame($this->admin->name, $metadata['by'] ?? null);
    }

    /** @test */
    public function admin_can_approve_a_requested_shoot_with_inline_edits_after_refactor(): void
    {
        Sanctum::actingAs($this->admin);

        $servicePhotographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Category Photographer',
            'email' => 'category-photographer@test.com',
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'address' => '10 Request Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'shoot_notes' => 'Original note',
        ]);
        $this->attachPrimaryService($shoot);

        $scheduledAt = now()->addDays(7)->setTime(11, 30)->format('Y-m-d H:i:s');

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve", [
            'address' => '900 Approval Way',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'scheduled_at' => $scheduledAt,
            'photographer_id' => $this->photographer->id,
            'services' => [
                ['id' => $this->secondService->id, 'quantity' => 1, 'price' => 90],
            ],
            'service_photographers' => [
                ['service_id' => $this->secondService->id, 'photographer_id' => $servicePhotographer->id],
            ],
            'shoot_notes' => 'Gate code is 1234',
            'company_notes' => 'Internal dispatch note',
            'photographer_notes' => 'Bring a drone if weather is clear',
            'editor_notes' => 'Prioritize twilight tones',
            'bedrooms' => 4,
            'bathrooms' => 3.5,
            'sqft' => 2450,
            'base_quote' => 90,
            'tax_amount' => 5.40,
            'total_quote' => 95.40,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', (string) $shoot->id);

        $shoot->refresh()->load('services');

        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->status);
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->workflow_status);
        $this->assertSame('900 Approval Way', $shoot->address);
        $this->assertSame('Washington', $shoot->city);
        $this->assertSame('DC', $shoot->state);
        $this->assertSame('20001', $shoot->zip);
        $this->assertSame($scheduledAt, $shoot->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertSame($this->photographer->id, $shoot->photographer_id);
        $this->assertSame('Gate code is 1234', $shoot->shoot_notes);
        $this->assertSame('Internal dispatch note', $shoot->company_notes);
        $this->assertSame('Bring a drone if weather is clear', $shoot->photographer_notes);
        $this->assertSame('Prioritize twilight tones', $shoot->editor_notes);
        $this->assertEquals(90.0, (float) $shoot->base_quote);
        $this->assertEquals(5.4, (float) $shoot->tax_amount);
        $this->assertEquals(95.4, (float) $shoot->total_quote);
        $this->assertCount(1, $shoot->services);
        $this->assertSame($this->secondService->id, $shoot->services->first()->id);
        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id,
            'service_id' => $this->secondService->id,
            'photographer_id' => $servicePhotographer->id,
        ]);

        $propertyDetails = is_array($shoot->property_details)
            ? $shoot->property_details
            : json_decode((string) $shoot->property_details, true);

        $this->assertSame(4, $propertyDetails['bedrooms'] ?? null);
        $this->assertSame(3.5, $propertyDetails['bathrooms'] ?? null);
        $this->assertSame(2450, $propertyDetails['sqft'] ?? null);
    }

    /** @test */
    public function admin_approving_a_requested_shoot_with_client_facing_edits_uses_the_modified_request_trigger(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'address' => '123 Original Request St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);
        $this->attachPrimaryService($shoot);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $targetShoot) => [
                'shoot' => $targetShoot,
                'shoot_id' => $targetShoot->id,
                'client' => $targetShoot->client,
                'photographer' => $targetShoot->photographer,
                'photographers' => $targetShoot->photographer ? [$targetShoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(function (string $triggerType, array $context) use ($shoot) {
                return $triggerType === 'SHOOT_REQUEST_MODIFIED'
                    && ($context['shoot_id'] ?? null) === $shoot->id
                    && ($context['request_modified'] ?? false) === true;
            })
            ->andReturnUsing(fn (string $triggerType) => $this->emptyAutomationDispatchSummary($triggerType));
        $automationService->shouldReceive('handleEvent')
            ->zeroOrMoreTimes()
            ->withArgs(function (string $triggerType) {
                return in_array($triggerType, ['SHOOT_BOOKED', 'SHOOT_SCHEDULED'], true);
            })
            ->andReturnUsing(fn (string $triggerType) => $this->emptyAutomationDispatchSummary($triggerType));
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve", [
            'scheduled_at' => now()->addDays(3)->setTime(12, 0)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
            'address' => '900 Modified Request Ave',
            'notify_client' => true,
            'notify_photographer' => false,
        ]);

        $response->assertOk();
    }

    /** @test */
    public function requested_shoot_approval_email_counts_as_client_confirmation_without_sending_scheduled_duplicate(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'address' => '123 Original Request St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);
        $this->attachPrimaryService($shoot);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $targetShoot) => [
                'shoot' => $targetShoot,
                'shoot_id' => $targetShoot->id,
                'client' => $targetShoot->client,
                'photographer' => $targetShoot->photographer,
                'photographers' => $targetShoot->photographer ? [$targetShoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_REQUEST_MODIFIED')
            ->andReturn(array_merge($this->emptyAutomationDispatchSummary('SHOOT_REQUEST_MODIFIED'), [
                'handled' => true,
                'client_email_sent' => true,
            ]));
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_SCHEDULED')
            ->andReturn($this->emptyAutomationDispatchSummary('SHOOT_SCHEDULED'));
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_SCHEDULED', Mockery::type('array'))
            ->andReturnTrue();
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $this->rebindMailService(function ($mailService) {
            $mailService->shouldReceive('sendShootScheduledEmail')->never();
            $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->once()->andReturnTrue();
        });

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve", [
            'scheduled_at' => now()->addDays(4)->setTime(9, 30)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
            'address' => '900 Modified Request Ave',
            'notify_client' => true,
            'notify_photographer' => true,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('shoot_email_deliveries', [
            'shoot_id' => $shoot->id,
            'recipient_user_id' => $this->client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SENT,
            'source' => ShootEmailDelivery::SOURCE_AUTOMATION,
        ]);
    }

    /** @test */
    public function admin_can_approve_a_requested_shoot_without_notifications_after_refactor(): void
    {
        Sanctum::actingAs($this->admin);

        $this->rebindMailService(function ($mailService) {
            $mailService->shouldReceive('sendShootUpdatedEmail')->never();
            $mailService->shouldReceive('sendShootScheduledEmail')->never();
        });

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve", [
            'scheduled_at' => now()->addDays(2)->setTime(13, 0)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
            'notify_client' => false,
            'notify_photographer' => false,
        ]);

        $response->assertOk();
        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->status);
        $this->assertSame($this->photographer->id, $shoot->photographer_id);
    }

    /** @test */
    public function admin_can_approve_a_requested_shoot_with_notifications_after_refactor(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
        ]);
        $this->attachPrimaryService($shoot);

        $this->rebindMailService(function ($mailService) use ($shoot) {
            $mailService->shouldReceive('sendShootScheduledEmail')
                ->once()
                ->withArgs(function (User $recipient, Shoot $approvedShoot, string $paymentLink, ?bool $notifyPhotographer = null) use ($shoot) {
                    return $recipient->is($this->client)
                        && $approvedShoot->id === $shoot->id
                        && $paymentLink === 'https://example.test/payment'
                        && $notifyPhotographer === false;
                })
                ->andReturnTrue();
            $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->once()->andReturnTrue();
        });

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve", [
            'scheduled_at' => now()->addDays(4)->setTime(9, 30)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
            'notify_client' => true,
            'notify_photographer' => true,
        ]);

        $response->assertOk();
        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->status);
        $this->assertSame($this->photographer->id, $shoot->photographer_id);
    }

    /** @test */
    public function approval_falls_back_when_scheduled_automation_is_handled_but_client_email_was_not_sent(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
        ]);
        $this->attachPrimaryService($shoot);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $targetShoot) => [
                'shoot' => $targetShoot,
                'shoot_id' => $targetShoot->id,
                'client' => $targetShoot->client,
                'photographer' => $targetShoot->photographer,
                'photographers' => $targetShoot->photographer ? [$targetShoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_REQUEST_APPROVED')
            ->andReturnUsing(fn (string $triggerType) => $this->emptyAutomationDispatchSummary($triggerType));
        // SHOOT_BOOKED is no longer fired on approval — only SHOOT_SCHEDULED is.
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_SCHEDULED')
            ->andReturn([
                'trigger_type' => 'SHOOT_SCHEDULED',
                'active_rule_count' => 1,
                'run_count' => 1,
                'completed_run_count' => 1,
                'waiting_run_count' => 0,
                'failed_run_count' => 0,
                'handled' => true,
                'errors' => [],
                'email_sent_to' => ['ops@test.com'],
                'client_email_sent' => false,
                'photographer_email_sent' => false,
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_SCHEDULED', Mockery::type('array'))
            ->andReturnFalse();
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $this->rebindMailService(function ($mailService) use ($shoot) {
            $mailService->shouldReceive('sendShootScheduledEmail')
                ->once()
                ->withArgs(function (User $recipient, Shoot $approvedShoot, string $paymentLink, ?bool $notifyPhotographer = null) use ($shoot) {
                    return $recipient->is($this->client)
                        && $approvedShoot->id === $shoot->id
                        && $paymentLink === 'https://example.test/payment'
                        && $notifyPhotographer === false;
                })
                ->andReturnTrue();
            $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->once()->andReturnTrue();
        });

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve", [
            'scheduled_at' => now()->addDays(4)->setTime(9, 30)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
            'notify_client' => true,
            'notify_photographer' => true,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('shoot_email_deliveries', [
            'shoot_id' => $shoot->id,
            'recipient_user_id' => $this->client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SENT,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
        ]);
    }

    /** @test */
    public function approval_records_failed_client_confirmation_delivery_when_fallback_send_fails(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
        ]);
        $this->attachPrimaryService($shoot);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $targetShoot) => [
                'shoot' => $targetShoot,
                'shoot_id' => $targetShoot->id,
                'client' => $targetShoot->client,
                'photographer' => $targetShoot->photographer,
                'photographers' => $targetShoot->photographer ? [$targetShoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_REQUEST_APPROVED')
            ->andReturnUsing(fn (string $triggerType) => $this->emptyAutomationDispatchSummary($triggerType));
        // SHOOT_BOOKED is no longer fired on approval — only SHOOT_SCHEDULED is.
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_SCHEDULED')
            ->andReturn($this->emptyAutomationDispatchSummary('SHOOT_SCHEDULED'));
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_SCHEDULED', Mockery::type('array'))
            ->andReturnFalse();
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $this->rebindMailService(function ($mailService) use ($shoot) {
            $mailService->shouldReceive('sendShootScheduledEmail')
                ->once()
                ->withArgs(function (User $recipient, Shoot $approvedShoot, string $paymentLink, ?bool $notifyPhotographer = null) use ($shoot) {
                    return $recipient->is($this->client)
                        && $approvedShoot->id === $shoot->id
                        && $paymentLink === 'https://example.test/payment'
                        && $notifyPhotographer === false;
                })
                ->andReturnFalse();
            $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->once()->andReturnTrue();
        });

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve", [
            'scheduled_at' => now()->addDays(4)->setTime(9, 30)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
            'notify_client' => true,
            'notify_photographer' => true,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('shoot_email_deliveries', [
            'shoot_id' => $shoot->id,
            'recipient_user_id' => $this->client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_FAILED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_PROVIDER_ERROR,
        ]);
    }

    /** @test */
    public function admin_created_scheduled_shoot_uses_booked_fallback_to_notify_client_and_photographer(): void
    {
        Sanctum::actingAs($this->admin);

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
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_BOOKED')
            ->andReturn([
                'trigger_type' => 'SHOOT_BOOKED',
                'active_rule_count' => 1,
                'run_count' => 1,
                'completed_run_count' => 0,
                'waiting_run_count' => 0,
                'failed_run_count' => 1,
                'handled' => false,
                'errors' => [
                    ['automation_id' => 2, 'message' => 'Failed to authenticate with Cakemail API'],
                ],
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_BOOKED', Mockery::type('array'))
            ->andReturnTrue();
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $this->rebindMailService(function ($mailService) {
            $mailService->shouldReceive('sendShootScheduledEmail')
                ->once()
                ->withArgs(function (User $recipient, Shoot $shoot, string $paymentLink, ?bool $notifyPhotographer = null) {
                    return $recipient->is($this->client)
                        && $shoot->client_id === $this->client->id
                        && $shoot->photographer_id === $this->photographer->id
                        && $paymentLink === 'https://example.test/payment'
                        && $notifyPhotographer === false;
                })
                ->andReturnTrue();
            $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->once()->andReturnTrue();
        });

        $response = $this->postJson('/api/shoots', [
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'address' => '88 Fallback Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $this->service->id, 'quantity' => 1],
            ],
            'scheduled_at' => now()->addDays(4)->setTime(12, 15)->format('Y-m-d H:i:s'),
        ]);

        $response->assertCreated();

        $shoot = Shoot::query()->latest('id')->firstOrFail();

        $this->assertDatabaseHas('shoot_email_deliveries', [
            'shoot_id' => $shoot->id,
            'recipient_user_id' => $this->client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SENT,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
        ]);
    }

    /** @test */
    public function admin_reassigning_a_photographer_uses_the_dedicated_photographer_change_email(): void
    {
        Sanctum::actingAs($this->admin);

        $replacementPhotographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Replacement Photographer',
            'email' => 'replacement-photographer@test.com',
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(2)->setTime(9, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '09:00',
        ]);
        $this->attachPrimaryService($shoot);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('captureShootSnapshot')->zeroOrMoreTimes()->andReturn([]);
        $mailService->shouldReceive('buildShootChangeSummary')->zeroOrMoreTimes()->andReturn([
            'summary' => 'Photographer updated',
            'html' => '<p>Photographer updated</p>',
        ]);
        $mailService->shouldReceive('sendShootScheduledEmail')->never();
        $mailService->shouldReceive('sendShootUpdatedEmail')
            ->once()
            ->withArgs(function (User $recipient, Shoot $updatedShoot, ?string $summary, ?bool $notifyClient, ?bool $notifyPhotographer) use ($shoot) {
                return $recipient->is($this->client)
                    && $updatedShoot->id === $shoot->id
                    && $summary === 'Photographer updated'
                    && $notifyClient === true
                    && $notifyPhotographer === false;
            })
            ->andReturnTrue();
        $mailService->shouldReceive('sendPhotographerChangedEmail')
            ->twice()
            ->withArgs(function (User $recipient, Shoot $updatedShoot, ?User $previousPhotographer, ?string $summary) use ($shoot, $replacementPhotographer) {
                return in_array($recipient->id, [$this->photographer->id, $replacementPhotographer->id], true)
                    && $updatedShoot->id === $shoot->id
                    && $previousPhotographer?->id === $this->photographer->id
                    && $summary === 'Photographer updated';
            })
            ->andReturnTrue();
        $mailService->shouldReceive('generatePaymentLink')->zeroOrMoreTimes()->andReturn('https://example.test/payment');
        $this->app->instance(MailService::class, $mailService);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'photographer_id' => $replacementPhotographer->id,
            'notify_client' => true,
            'notify_photographer' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Shoot updated');

        $shoot->refresh();

        $this->assertSame($replacementPhotographer->id, $shoot->photographer_id);
    }

    /** @test */
    public function shoot_update_falls_back_when_update_automation_did_not_send_client_email(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(2)->setTime(9, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '09:00',
        ]);
        $this->attachPrimaryService($shoot);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $targetShoot) => [
                'shoot' => $targetShoot,
                'shoot_id' => $targetShoot->id,
                'client' => $targetShoot->client,
                'photographer' => $targetShoot->photographer,
                'photographers' => $targetShoot->photographer ? [$targetShoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_UPDATED')
            ->andReturn([
                'trigger_type' => 'SHOOT_UPDATED',
                'active_rule_count' => 1,
                'run_count' => 1,
                'completed_run_count' => 1,
                'waiting_run_count' => 0,
                'failed_run_count' => 0,
                'handled' => true,
                'errors' => [],
                'email_sent_to' => ['ops@test.com'],
                'client_email_sent' => false,
                'photographer_email_sent' => false,
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_UPDATED', Mockery::type('array'))
            ->andReturnFalse();
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $this->rebindMailService(function ($mailService) use ($shoot) {
            $mailService->shouldReceive('sendShootUpdatedEmail')
                ->once()
                ->withArgs(function (User $recipient, Shoot $updatedShoot, ?string $summary, ?bool $notifyClient, ?bool $notifyPhotographer) use ($shoot) {
                    return $recipient->is($this->client)
                        && $updatedShoot->id === $shoot->id
                        && $summary === 'Shoot details updated'
                        && $notifyClient === true
                        && $notifyPhotographer === true;
                })
                ->andReturnTrue();
        });

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'address' => '510 Updated Ave',
            'notify_client' => true,
            'notify_photographer' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Shoot updated');
    }

    /** @test */
    public function admin_can_update_a_shoot_and_emit_the_dynamic_refresh_signal(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(2)->setTime(9, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '09:00',
            'address' => '50 Original St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 150,
            'tax_amount' => 9,
            'total_quote' => 159,
        ]);
        $this->attachPrimaryService($shoot);

        $updatedAt = now()->addDays(4)->setTime(13, 15)->format('Y-m-d H:i:s');

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'address' => '500 Updated Ave',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'scheduled_at' => $updatedAt,
            'services' => [
                ['id' => $this->secondService->id, 'quantity' => 2],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Shoot updated');

        $shoot->refresh()->load('services');

        $this->assertSame('500 Updated Ave', $shoot->address);
        $this->assertSame('Washington', $shoot->city);
        $this->assertSame('DC', $shoot->state);
        $this->assertSame($updatedAt, $shoot->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertCount(1, $shoot->services);
        $this->assertSame($this->secondService->id, $shoot->services->first()->id);

        $activity = DB::table('shoot_activity_logs')
            ->where('shoot_id', $shoot->id)
            ->where('action', 'shoot_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $metadata = json_decode($activity->metadata ?? '[]', true);
        $this->assertArrayHasKey('changes', $metadata);
        $this->assertArrayHasKey('address', $metadata['changes']);
        $this->assertArrayHasKey('services', $metadata['changes']);
        $this->assertArrayHasKey('base_quote', $metadata['changes']);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'shoot_updated';
        });
    }

    /** @test */
    public function shoots_table_supports_the_featured_flag_with_a_false_default(): void
    {
        $this->assertTrue(Schema::hasColumn('shoots', 'is_featured'));

        $shoot = Shoot::factory()->create();

        $this->assertFalse((bool) $shoot->fresh()->is_featured);
    }

    /** @test */
    public function admin_can_toggle_featured_on_a_shoot_and_receive_both_payload_keys(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'is_featured' => false,
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_featured' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.isFeatured', true);

        $this->assertTrue((bool) $shoot->fresh()->is_featured);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'featured_shoot_marked',
            'user_id' => $this->admin->id,
        ]);
    }

    /** @test */
    public function assigned_sales_rep_can_toggle_featured_on_a_shoot(): void
    {
        Sanctum::actingAs($this->salesRep);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'is_featured' => false,
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_featured' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.isFeatured', true);

        $this->assertTrue((bool) $shoot->fresh()->is_featured);
    }

    /** @test */
    public function assigned_photographer_can_toggle_featured_on_a_shoot(): void
    {
        Sanctum::actingAs($this->photographer);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'is_featured' => false,
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_featured' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.isFeatured', true);

        $this->assertTrue((bool) $shoot->fresh()->is_featured);
    }

    /** @test */
    public function client_cannot_toggle_featured_on_a_shoot(): void
    {
        Sanctum::actingAs($this->client);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'is_featured' => false,
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_featured' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse((bool) $shoot->fresh()->is_featured);
    }

    public function test_linked_client_owner_can_mark_shared_delivered_shoot_private_exclusive(): void
    {
        $ownerClient = User::factory()->create([
            'role' => 'client',
            'name' => 'Listing Owner Client',
            'email' => 'listing-owner-client@test.com',
        ]);

        AccountLink::create([
            'main_account_id' => $ownerClient->id,
            'linked_account_id' => $this->client->id,
            'shared_details' => ['shoots' => true],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $ownerClient->id,
        ]);

        Sanctum::actingAs($ownerClient);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'is_private_listing' => false,
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_private_listing' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_private_listing', true);

        $this->assertTrue((bool) $shoot->fresh()->is_private_listing);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'private_listing_marked',
            'user_id' => $ownerClient->id,
        ]);
    }

    /** @test */
    public function unassigned_photographer_cannot_toggle_featured_on_a_shoot(): void
    {
        $otherPhotographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Unassigned Photographer',
            'email' => 'unassigned-photographer@test.com',
        ]);

        Sanctum::actingAs($otherPhotographer);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'is_featured' => false,
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_featured' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse((bool) $shoot->fresh()->is_featured);
    }

    /** @test */
    public function unassigned_sales_rep_cannot_toggle_featured_on_a_shoot(): void
    {
        $otherSalesRep = User::factory()->create([
            'role' => 'salesRep',
            'name' => 'Unassigned Sales Rep',
            'email' => 'unassigned-sales-rep@test.com',
        ]);

        Sanctum::actingAs($otherSalesRep);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'is_featured' => false,
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_featured' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse((bool) $shoot->fresh()->is_featured);
    }

    /** @test */
    public function admin_can_delete_a_shoot_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'address' => '99 Delete Me Dr',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->deleteJson("/api/shoots/{$shoot->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Shoot deleted from the dashboard successfully');

        $this->assertDatabaseMissing('shoots', [
            'id' => $shoot->id,
        ]);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'shoot_deleted';
        });
    }

    protected function attachPrimaryService(Shoot $shoot): void
    {
        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
        ]);
    }

    protected function bindMutationSideEffectFakes(): void
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
        $automationService->shouldReceive('handleEvent')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $triggerType) => $this->emptyAutomationDispatchSummary($triggerType));
        $automationService->shouldReceive('shouldUseFallback')->zeroOrMoreTimes()->andReturnTrue();
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $availabilityService = Mockery::mock(PhotographerAvailabilityService::class);
        $availabilityService->shouldIgnoreMissing();
        $availabilityService->shouldReceive('isAvailable')->zeroOrMoreTimes()->andReturnTrue();
        $this->app->instance(PhotographerAvailabilityService::class, $availabilityService);
    }

    protected function rebindMailService(callable $configure): void
    {
        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('captureShootSnapshot')->zeroOrMoreTimes()->andReturn([]);
        $mailService->shouldReceive('buildShootChangeSummary')->zeroOrMoreTimes()->andReturn([
            'summary' => 'Shoot details updated',
            'html' => '<p>Shoot details updated</p>',
        ]);
        $mailService->shouldReceive('sendShootRemovedEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('generatePaymentLink')->zeroOrMoreTimes()->andReturn('https://example.test/payment');

        $configure($mailService);

        $this->app->instance(MailService::class, $mailService);
    }

    protected function emptyAutomationDispatchSummary(string $triggerType): array
    {
        return [
            'trigger_type' => $triggerType,
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
        ];
    }
}
