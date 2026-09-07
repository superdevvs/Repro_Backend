<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Models\AccountLink;
use App\Models\Payment;
use App\Models\PaymentServiceAllocation;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootEmailDelivery;
use App\Models\ShootFile;
use App\Models\ShootMediaAlbum;
use App\Models\ShootUploadAttempt;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\CubiCasaService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\PhotographerAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

        $payload = [
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
            'admin_adjusted_total_quote' => 95.40,
        ];

        $confirmation = $this->postJson("/api/shoots/{$shoot->id}/approve", $payload);
        $confirmation->assertStatus(409)
            ->assertJsonPath('code', 'service_detach_confirmation_required')
            ->assertJsonPath('impact.removed_services.0.name', $this->service->name);

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve", array_merge($payload, [
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ]));

        $response->assertOk()
            ->assertJsonPath('data.id', (string) $shoot->id);

        $shoot->refresh()->load('services');

        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->status);
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->workflow_status);
        $this->assertSame('900 Approval Way', $shoot->address);
        $this->assertSame('Washington', $shoot->city);
        $this->assertSame('DC', $shoot->state);
        $this->assertSame('20001', $shoot->zip);
        $detachActivity = DB::table('shoot_activity_logs')
            ->where('shoot_id', $shoot->id)
            ->where('action', 'shoot_services_detached')
            ->latest('id')
            ->first();
        $this->assertNotNull($detachActivity);
        $detachMetadata = json_decode($detachActivity->metadata ?? '[]', true);
        $this->assertSame(
            $this->service->name,
            data_get($detachMetadata, 'service_detach_impact.removed_services.0.name')
        );
        $this->assertSame($scheduledAt, $shoot->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertSame($this->photographer->id, $shoot->photographer_id);
        $this->assertSame('Gate code is 1234', $shoot->shoot_notes);
        $this->assertSame('Internal dispatch note', $shoot->company_notes);
        $this->assertSame('Bring a drone if weather is clear', $shoot->photographer_notes);
        $this->assertSame('Prioritize twilight tones', $shoot->editor_notes);
        $this->assertEquals(95.4, (float) $shoot->total_quote);
        $this->assertEqualsWithDelta(95.4, (float) $shoot->base_quote + (float) $shoot->tax_amount, 0.01);
        $this->assertNull($shoot->discount_type);
        $this->assertEquals(0.0, (float) $shoot->discount_amount);
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

        $payload = [
            'address' => '500 Updated Ave',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'scheduled_at' => $updatedAt,
            'services' => [
                ['id' => $this->secondService->id, 'quantity' => 2],
            ],
        ];

        $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", $payload);
        $confirmation->assertStatus(409)
            ->assertJsonPath('code', 'service_detach_confirmation_required');

        $response = $this->patchJson("/api/shoots/{$shoot->id}", array_merge($payload, [
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ]));

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

    #[\PHPUnit\Framework\Attributes\Test]
    public function shoots_table_supports_the_featured_flag_with_a_false_default(): void
    {
        $this->assertTrue(Schema::hasColumn('shoots', 'is_featured'));

        $shoot = Shoot::factory()->create();

        $this->assertFalse((bool) $shoot->fresh()->is_featured);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function assigned_sales_rep_can_request_featured_approval_on_a_shoot(): void
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
            ->assertJsonPath('data.is_featured', false)
            ->assertJsonPath('data.isFeatured', false)
            ->assertJsonPath('data.featured_pending', true)
            ->assertJsonPath('data.featured_status', 'pending');

        $shoot->refresh();
        $this->assertFalse((bool) $shoot->is_featured);
        $this->assertNotNull($shoot->featured_requested_at);
        $this->assertSame($this->salesRep->id, $shoot->featured_requested_by);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function assigned_photographer_can_request_featured_approval_on_a_shoot(): void
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
            ->assertJsonPath('data.is_featured', false)
            ->assertJsonPath('data.isFeatured', false)
            ->assertJsonPath('data.featured_pending', true)
            ->assertJsonPath('data.featured_status', 'pending');

        $shoot->refresh();
        $this->assertFalse((bool) $shoot->is_featured);
        $this->assertNotNull($shoot->featured_requested_at);
        $this->assertSame($this->photographer->id, $shoot->featured_requested_by);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    public function test_client_owner_can_submit_access_info_but_not_other_property_details(): void
    {
        Sanctum::actingAs($this->client);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'property_details' => [
                'price' => '$500,000',
                'mls_id' => 'MLS-KEEP',
            ],
        ]);
        $this->attachPrimaryService($shoot);

        // Access-info-only update is allowed for the owning client.
        $this->patchJson("/api/shoots/{$shoot->id}", [
            'property_details' => [
                'presenceOption' => 'lockbox',
                'lockboxCode' => '4821',
                'lockboxLocation' => 'On the front gate',
            ],
        ])->assertOk();

        $details = $shoot->fresh()->property_details;
        $this->assertSame('lockbox', $details['presenceOption'] ?? null);
        $this->assertSame('4821', $details['lockboxCode'] ?? null);
        $this->assertSame('On the front gate', $details['lockboxLocation'] ?? null);
        // Existing metadata must be preserved (merge, not overwrite).
        $this->assertSame('$500,000', $details['price'] ?? null);
        $this->assertSame('MLS-KEEP', $details['mls_id'] ?? null);

        // Attempting to change a non-access property_details key is forbidden.
        $this->patchJson("/api/shoots/{$shoot->id}", [
            'property_details' => [
                'price' => '$1',
            ],
        ])->assertForbidden();

        $this->assertSame('$500,000', $shoot->fresh()->property_details['price'] ?? null);
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

    public function test_admin_can_hide_and_unhide_private_exclusive_listing(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'is_private_listing' => true,
            'is_listing_hidden' => false,
        ]);
        $this->attachPrimaryService($shoot);

        $hideResponse = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_listing_hidden' => true,
        ]);

        $hideResponse->assertOk()
            ->assertJsonPath('data.is_listing_hidden', true)
            ->assertJsonPath('data.isListingHidden', true);
        $this->assertTrue((bool) $shoot->fresh()->is_listing_hidden);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'listing_hidden',
            'user_id' => $this->admin->id,
        ]);

        $unhideResponse = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_listing_hidden' => false,
        ]);

        $unhideResponse->assertOk()
            ->assertJsonPath('data.is_listing_hidden', false)
            ->assertJsonPath('data.isListingHidden', false);
        $this->assertFalse((bool) $shoot->fresh()->is_listing_hidden);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'listing_unhidden',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_client_cannot_hide_private_exclusive_listing(): void
    {
        Sanctum::actingAs($this->client);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'is_private_listing' => true,
            'is_listing_hidden' => false,
        ]);
        $this->attachPrimaryService($shoot);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'is_listing_hidden' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse((bool) $shoot->fresh()->is_listing_hidden);
    }

    public function test_hidden_private_exclusive_listings_require_admin_include_hidden_filter(): void
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'is_private_listing' => true,
            'is_listing_hidden' => true,
        ]);
        $this->attachPrimaryService($shoot);

        Sanctum::actingAs($this->client);
        $clientResponse = $this->getJson('/api/shoots?tab=delivered&private_listing=1&include_hidden=1&no_cache=1');
        $clientResponse->assertOk();
        $this->assertFalse(collect($clientResponse->json('data') ?? [])->contains('id', $shoot->id));

        Sanctum::actingAs($this->admin);
        $adminDefaultResponse = $this->getJson('/api/shoots?tab=delivered&private_listing=1&no_cache=1');
        $adminDefaultResponse->assertOk();
        $this->assertFalse(collect($adminDefaultResponse->json('data') ?? [])->contains('id', $shoot->id));

        $adminIncludeHiddenResponse = $this->getJson('/api/shoots?tab=delivered&private_listing=1&include_hidden=1&no_cache=1');
        $adminIncludeHiddenResponse->assertOk();
        $this->assertTrue(collect($adminIncludeHiddenResponse->json('data') ?? [])->contains('id', $shoot->id));
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_remove_every_service_after_confirming_the_impact(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'base_quote' => 240,
            'tax_amount' => 14.40,
            'total_quote' => 254.40,
        ]);
        $this->attachPrimaryService($shoot);
        $shoot->services()->attach($this->secondService->id, [
            'price' => 90,
            'quantity' => 1,
        ]);

        $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
        ])->assertStatus(409)
            ->assertJsonPath('code', 'service_detach_confirmation_required')
            ->assertJsonPath('impact.leaves_no_services', true)
            ->assertJsonCount(2, 'impact.removed_services')
            ->assertJsonPath('impact.current_total', 254.4)
            ->assertJsonPath('impact.new_total', 0);

        $this->assertTrue($shoot->fresh()->services()->whereKey($this->service->id)->exists());

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.canRemoveAllServices', true)
            ->assertJsonPath('data.can_remove_all_services', true)
            ->assertJsonPath('data.overpaymentAmount', 0);

        $shoot->refresh();
        $this->assertNull($shoot->service_id);
        $this->assertCount(0, $shoot->services()->get());
        $this->assertSame(Shoot::PRODUCT_STATUS_NO_PRODUCT, $shoot->product_status);
        $this->assertEquals(0.0, (float) $shoot->base_quote);
        $this->assertEquals(0.0, (float) $shoot->discount_amount);
        $this->assertEquals(0.0, (float) $shoot->tax_amount);
        $this->assertEquals(0.0, (float) $shoot->total_quote);
        $this->assertSame('paid', $shoot->payment_status);
        $this->assertTrue((bool) $shoot->bypass_paywall);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'shoot_services_detached',
            'user_id' => $this->admin->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function removed_service_media_and_upload_history_are_preserved_and_detached(): void
    {
        Sanctum::actingAs($this->admin);

        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
        ]);
        $this->attachPrimaryService($shoot);
        $item = $shoot->serviceItems()->firstOrFail();
        $item->forceFill([
            'editor_id' => $editor->id,
            'workflow_status' => 'in_progress',
            'delivery_status' => 'ready',
        ])->save();

        $album = ShootMediaAlbum::query()->create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $item->id,
            'photographer_id' => $this->photographer->id,
            'source' => ShootMediaAlbum::SOURCE_LOCAL,
            'folder_path' => 'shoots/test/album',
        ]);
        $file = ShootFile::query()->create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $item->id,
            'album_id' => $album->id,
            'filename' => 'retained.jpg',
            'stored_filename' => 'retained-1.jpg',
            'path' => 'shoots/test/retained-1.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 100,
            'uploaded_by' => $this->photographer->id,
        ]);
        $attempt = ShootUploadAttempt::query()->create([
            'shoot_id' => $shoot->id,
            'actor_id' => $this->photographer->id,
            'idempotency_key' => 'detach-history-'.$shoot->id,
            'request_fingerprint' => str_repeat('a', 64),
            'upload_type' => 'raw',
            'shoot_service_id' => $item->id,
            'status' => ShootUploadAttempt::STATUS_COMPLETED,
            'correlation_id' => (string) Str::uuid(),
        ]);

        $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
        ])->assertStatus(409)
            ->assertJsonPath('impact.files_detached', 1)
            ->assertJsonPath('impact.albums_detached', 1)
            ->assertJsonPath('impact.upload_attempts_detached', 1)
            ->assertJsonPath('impact.assignments_removed', 1)
            ->assertJsonPath('impact.progress_rows_removed', 1);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ])->assertOk();

        $this->assertDatabaseHas('shoot_files', [
            'id' => $file->id,
            'shoot_id' => $shoot->id,
            'shoot_service_id' => null,
        ]);
        $this->assertDatabaseHas('shoot_media_albums', [
            'id' => $album->id,
            'shoot_id' => $shoot->id,
            'shoot_service_id' => null,
        ]);
        $this->assertDatabaseHas('shoot_upload_attempts', [
            'id' => $attempt->id,
            'shoot_id' => $shoot->id,
            'shoot_service_id' => null,
        ]);
        $this->assertDatabaseMissing('shoot_service', ['id' => $item->id]);

        $activity = DB::table('shoot_activity_logs')
            ->where('shoot_id', $shoot->id)
            ->where('action', 'shoot_updated')
            ->latest('id')
            ->first();
        $metadata = json_decode($activity?->metadata ?? '[]', true);
        $this->assertSame('HDR Photos', data_get($metadata, 'service_detach_impact.removed_services.0.name'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_remove_all_services_from_every_pre_delivery_status(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([
            Shoot::STATUS_REQUESTED,
            Shoot::STATUS_SCHEDULED,
            'booked',
            Shoot::STATUS_ON_HOLD,
            Shoot::STATUS_UPLOADED,
            'completed',
            Shoot::STATUS_EDITING,
            Shoot::STATUS_REVIEW,
            Shoot::STATUS_READY,
        ] as $status) {
            $shoot = Shoot::factory()->create([
                'client_id' => $this->client->id,
                'service_id' => $this->service->id,
                'status' => $status,
                'workflow_status' => $status,
            ]);
            $this->attachPrimaryService($shoot);

            $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
                'services' => [],
            ])->assertStatus(409);

            $this->patchJson("/api/shoots/{$shoot->id}", [
                'services' => [],
                'confirm_service_detach' => true,
                'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
            ])->assertOk();

            $this->assertSame(0, $shoot->fresh()->serviceItems()->count(), "Failed for status [{$status}].");
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function superadmin_can_remove_products_from_a_pre_delivery_shoot_after_confirmation(): void
    {
        $superadmin = User::factory()->create([
            'role' => 'superadmin',
            'email' => 'internal-shoot-superadmin@test.com',
        ]);
        Sanctum::actingAs($superadmin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $this->attachPrimaryService($shoot);

        $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
            'shoot_type' => Shoot::SHOOT_TYPE_SAMPLE_UPLOAD,
            'services' => [],
        ])->assertStatus(409);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'shoot_type' => Shoot::SHOOT_TYPE_SAMPLE_UPLOAD,
            'services' => [],
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ])->assertOk();

        $shoot->refresh();
        $this->assertSame(Shoot::SHOOT_TYPE_SAMPLE_UPLOAD, $shoot->shoot_type);
        $this->assertSame(Shoot::PRODUCT_STATUS_NO_PRODUCT, $shoot->product_status);
        $this->assertCount(0, $shoot->services()->get());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editing_manager_cannot_save_a_shoot_without_services(): void
    {
        $editingManager = User::factory()->create([
            'role' => 'editing_manager',
            'email' => 'service-removal-editing-manager@test.com',
        ]);
        Sanctum::actingAs($editingManager);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
        ]);
        $this->attachPrimaryService($shoot);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('services');

        $this->assertTrue($shoot->fresh()->services()->whereKey($this->service->id)->exists());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function clients_and_sales_representatives_cannot_save_zero_services(): void
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'rep_id' => $this->salesRep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $this->attachPrimaryService($shoot);

        foreach ([$this->client, $this->salesRep] as $actor) {
            Sanctum::actingAs($actor);
            $response = $this->patchJson("/api/shoots/{$shoot->id}", ['services' => []]);
            $this->assertContains($response->status(), [403, 422]);
            $this->assertTrue($shoot->fresh()->serviceItems()->exists());
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_remove_services_from_a_terminal_shoot(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([Shoot::STATUS_DELIVERED, Shoot::STATUS_CANCELLED, 'canceled', 'declined'] as $status) {
            $shoot = Shoot::factory()->create([
                'client_id' => $this->client->id,
                'service_id' => $this->service->id,
                'status' => $status,
                'workflow_status' => $status,
            ]);
            $this->attachPrimaryService($shoot);

            $this->patchJson("/api/shoots/{$shoot->id}", [
                'services' => [],
            ])->assertUnprocessable();

            $this->assertTrue($shoot->fresh()->services()->whereKey($this->service->id)->exists());
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function service_removal_confirmation_token_is_rejected_after_the_shoot_changes(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $this->attachPrimaryService($shoot);

        $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
        ])->assertStatus(409);
        $oldToken = $confirmation->json('confirmation_token');

        DB::table('shoots')->where('id', $shoot->id)->update([
            'updated_at' => now()->addMinute(),
        ]);

        $freshConfirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $oldToken,
        ]);

        $freshConfirmation->assertStatus(409)
            ->assertJsonPath('code', 'service_detach_confirmation_required');
        $this->assertNotSame($oldToken, $freshConfirmation->json('confirmation_token'));
        $this->assertTrue($shoot->fresh()->services()->whereKey($this->service->id)->exists());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function service_changes_reject_independent_client_pricing_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $this->attachPrimaryService($shoot);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [
                ['id' => $this->service->id, 'price' => 150, 'quantity' => 1],
            ],
            'base_quote' => 1,
            'tax_amount' => 0,
            'total_quote' => 1,
        ])->assertUnprocessable();

        $this->assertEquals(150.0, (float) $shoot->fresh()->services()->first()->pivot->price);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function duplicate_service_rows_are_rejected_before_pricing(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'base_quote' => 150,
            'total_quote' => 159,
        ]);
        $this->attachPrimaryService($shoot);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [
                ['id' => $this->service->id],
                ['id' => $this->service->id],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('services.1.id');

        $this->assertCount(1, $shoot->fresh()->serviceItems);
        $this->assertEquals(159.0, (float) $shoot->fresh()->total_quote);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function removing_all_services_preserves_payments_and_exposes_refund_credit_due(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'base_quote' => 150,
            'tax_amount' => 0,
            'total_quote' => 150,
        ]);
        $this->attachPrimaryService($shoot);
        $item = $shoot->serviceItems()->firstOrFail();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount' => 100,
            'status' => Payment::STATUS_COMPLETED,
        ]);
        PaymentServiceAllocation::query()->create([
            'payment_id' => $payment->id,
            'shoot_service_id' => $item->id,
            'amount' => 100,
        ]);

        $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
        ]);
        $confirmation->assertStatus(409)
            ->assertJsonPath('impact.payment_allocations_released', 100)
            ->assertJsonPath('impact.refund_credit_due', 100);

        $response = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.overpaymentAmount', 100)
            ->assertJsonPath('data.overpayment_amount', 100);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 100]);
        $this->assertDatabaseMissing('payment_service_allocations', ['payment_id' => $payment->id]);
        $this->assertSame('paid', $shoot->fresh()->payment_status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function payment_allocations_are_preserved_then_redistributed_deterministically(): void
    {
        Sanctum::actingAs($this->admin);

        $replacement = Service::factory()->create(['name' => 'Video Tour', 'price' => 200]);
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'base_quote' => 240,
            'tax_amount' => 0,
            'total_quote' => 240,
        ]);
        $this->attachPrimaryService($shoot);
        $shoot->services()->attach($this->secondService->id, ['price' => 90, 'quantity' => 1]);
        $items = $shoot->serviceItems()->orderBy('id')->get()->keyBy('service_id');
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount' => 150,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);
        PaymentServiceAllocation::query()->insert([
            [
                'payment_id' => $payment->id,
                'shoot_service_id' => $items[$this->service->id]->id,
                'amount' => 80,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'payment_id' => $payment->id,
                'shoot_service_id' => $items[$this->secondService->id]->id,
                'amount' => 70,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $target = [
            ['id' => $this->secondService->id],
            ['id' => $replacement->id],
        ];
        $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => $target,
        ])->assertStatus(409)
            ->assertJsonPath('impact.payment_allocations_released', 80);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => $target,
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ])->assertOk();

        $freshItems = $shoot->fresh()->serviceItems()->get()->keyBy('service_id');
        $this->assertDatabaseHas('payment_service_allocations', [
            'payment_id' => $payment->id,
            'shoot_service_id' => $freshItems[$this->secondService->id]->id,
            'amount' => 90,
        ]);
        $this->assertDatabaseHas('payment_service_allocations', [
            'payment_id' => $payment->id,
            'shoot_service_id' => $freshItems[$replacement->id]->id,
            'amount' => 60,
        ]);
        $this->assertSame(
            150.0,
            (float) PaymentServiceAllocation::query()->where('payment_id', $payment->id)->sum('amount')
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function retained_lines_keep_booked_price_and_quantity_while_new_lines_use_catalogue_price(): void
    {
        Sanctum::actingAs($this->admin);

        $newService = Service::factory()->create(['name' => 'Drone Add-on', 'price' => 55]);
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'state' => 'AK',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot->services()->attach($this->service->id, [
            'price' => 123,
            'quantity' => 2,
            'photographer_id' => $this->photographer->id,
            'editor_id' => $editor->id,
            'scheduled_at' => '2026-10-15 13:30:00',
            'workflow_status' => 'in_progress',
            'delivery_status' => 'ready',
            'is_deliverable' => false,
            'force_unlock_delivery' => true,
            'unlock_reason' => 'Retained exception',
            'unlocked_by' => $this->admin->id,
        ]);
        $shoot->services()->attach($this->secondService->id, ['price' => 80, 'quantity' => 3]);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [
                ['id' => $this->service->id],
                ['id' => $this->secondService->id],
                ['id' => $newService->id],
            ],
        ])->assertOk();

        $lines = $shoot->fresh()->serviceItems()->get()->keyBy('service_id');
        $this->assertEquals(123.0, (float) $lines[$this->service->id]->price);
        $this->assertSame(2, (int) $lines[$this->service->id]->quantity);
        $this->assertSame($this->photographer->id, (int) $lines[$this->service->id]->photographer_id);
        $this->assertSame($editor->id, (int) $lines[$this->service->id]->editor_id);
        $this->assertSame('2026-10-15 13:30:00', $lines[$this->service->id]->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertSame('in_progress', $lines[$this->service->id]->workflow_status);
        $this->assertSame('ready', $lines[$this->service->id]->delivery_status);
        $this->assertFalse((bool) $lines[$this->service->id]->is_deliverable);
        $this->assertTrue((bool) $lines[$this->service->id]->force_unlock_delivery);
        $this->assertSame('Retained exception', $lines[$this->service->id]->unlock_reason);
        $this->assertSame($this->admin->id, (int) $lines[$this->service->id]->unlocked_by);
        $this->assertEquals(80.0, (float) $lines[$this->secondService->id]->price);
        $this->assertSame(3, (int) $lines[$this->secondService->id]->quantity);
        $this->assertEquals(55.0, (float) $lines[$newService->id]->price);
        $this->assertSame(1, (int) $lines[$newService->id]->quantity);
        $this->assertEquals(541.0, (float) $shoot->fresh()->base_quote);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function later_client_discount_changes_do_not_reprice_an_undiscounted_booking(): void
    {
        Sanctum::actingAs($this->admin);

        $this->client->forceFill([
            'client_discount_type' => 'percent',
            'client_discount_value' => 50,
        ])->save();
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'state' => 'AK',
            'discount_type' => null,
            'discount_value' => null,
            'discount_amount' => 0,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $this->attachPrimaryService($shoot);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [
                ['id' => $this->service->id, 'quantity' => 2],
            ],
        ])->assertOk();

        $shoot->refresh();
        $this->assertNull($shoot->discount_type);
        $this->assertNull($shoot->discount_value);
        $this->assertEquals(0.0, (float) $shoot->discount_amount);
        $this->assertEquals(300.0, (float) $shoot->base_quote);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function invoice_sync_failure_rolls_back_the_entire_service_edit(): void
    {
        $invoiceService = Mockery::mock(InvoiceService::class);
        $invoiceService->shouldIgnoreMissing();
        $invoiceService->shouldReceive('refreshClientInvoicesForShoot')
            ->once()
            ->andThrow(new \RuntimeException('invoice sync failed'));
        $this->app->instance(InvoiceService::class, $invoiceService);
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'state' => 'AK',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'base_quote' => 150,
            'tax_amount' => 0,
            'total_quote' => 150,
        ]);
        $this->attachPrimaryService($shoot);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [
                ['id' => $this->service->id, 'quantity' => 2],
            ],
        ])->assertStatus(500);

        $shoot->refresh();
        $this->assertEquals(150.0, (float) $shoot->base_quote);
        $this->assertEquals(150.0, (float) $shoot->total_quote);
        $this->assertSame(1, (int) $shoot->serviceItems()->firstOrFail()->quantity);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_add_a_service_back_after_saving_an_empty_shoot(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'state' => 'MD',
        ]);
        $this->attachPrimaryService($shoot);

        $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
        ])->assertStatus(409);
        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ])->assertOk();

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [
                ['id' => $this->secondService->id, 'quantity' => 1],
            ],
        ])->assertOk();

        $shoot->refresh();
        $this->assertSame($this->secondService->id, $shoot->service_id);
        $this->assertCount(1, $shoot->services()->get());
        $this->assertEquals(90.0, (float) $shoot->base_quote);
        $this->assertGreaterThanOrEqual(90.0, (float) $shoot->total_quote);
        $this->assertEqualsWithDelta(
            (float) $shoot->total_quote,
            (float) $shoot->base_quote + (float) $shoot->tax_amount,
            0.01
        );
        $this->assertSame(Shoot::PRODUCT_STATUS_HAS_PRODUCT, $shoot->product_status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cubicasa_provider_failure_does_not_fail_shoot_approval(): void
    {
        $cubicasa = Mockery::mock(CubiCasaService::class);
        $cubicasa->shouldReceive('hasCredentials')->once()->andReturnTrue();
        $cubicasa->shouldReceive('createOrder')->once()->andThrow(new \RuntimeException('temporary provider outage'));
        $this->app->instance(CubiCasaService::class, $cubicasa);

        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'service_id' => $this->secondService->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
        ]);
        $shoot->services()->attach($this->secondService->id, [
            'price' => 90,
            'quantity' => 1,
        ]);

        $this->postJson("/api/shoots/{$shoot->id}/approve", [
            'scheduled_at' => now()->addWeek()->setTime(10, 0)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
        ])->assertOk();

        $shoot->refresh();
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->status);
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->workflow_status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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
