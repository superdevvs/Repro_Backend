<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\PhotographerAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
            ->assertJsonPath('message', 'Shoot deleted successfully');

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
            ]
        );
        $automationService->shouldReceive('handleEvent')->zeroOrMoreTimes()->andReturnNull();
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $availabilityService = Mockery::mock(PhotographerAvailabilityService::class);
        $availabilityService->shouldIgnoreMissing();
        $availabilityService->shouldReceive('isAvailable')->zeroOrMoreTimes()->andReturnTrue();
        $this->app->instance(PhotographerAvailabilityService::class, $availabilityService);
    }
}
