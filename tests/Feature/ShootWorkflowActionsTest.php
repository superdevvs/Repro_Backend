<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Http\Controllers\API\ShootWorkflowController;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\BrightMlsService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\Actions\ApproveCancellationAction;
use App\Services\Shoots\Actions\RequestCancellationAction;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ShootWorkflowActionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected User $photographer;
    protected User $editor;
    protected User $editingManager;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindWorkflowSideEffectFakes();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Workflow Admin',
            'email' => 'workflow-admin@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'name' => 'Workflow Client',
            'email' => 'workflow-client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Workflow Photographer',
            'email' => 'workflow-photographer@test.com',
        ]);

        $this->editor = User::factory()->create([
            'role' => 'editor',
            'name' => 'Workflow Editor',
            'email' => 'workflow-editor@test.com',
        ]);

        $this->editingManager = User::factory()->create([
            'role' => 'editing_manager',
            'name' => 'Workflow Manager',
            'email' => 'workflow-manager@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'Workflow Photos',
            'price' => 180.00,
        ]);
    }

    /** @test */
    public function admin_can_put_a_scheduled_shoot_on_hold_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->admin);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/put-on-hold", [
            'reason' => 'Weather delay',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Shoot has been placed on hold.');

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_ON_HOLD, $shoot->status);
        $this->assertSame(Shoot::STATUS_ON_HOLD, $shoot->workflow_status);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'shoot_put_on_hold',
            'user_id' => $this->admin->id,
        ]);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'shoot_put_on_hold';
        });
    }

    /** @test */
    public function client_can_request_cancellation_and_emit_a_refresh_worthy_activity(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->client);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/request-cancellation", [
            'reason' => 'Seller postponed listing',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Cancellation request submitted. Pending approval.');

        $shoot->refresh();

        $this->assertNotNull($shoot->cancellation_requested_at);
        $this->assertSame($this->client->id, $shoot->cancellation_requested_by);
        $this->assertSame('Seller postponed listing', $shoot->cancellation_reason);

        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'cancellation_requested',
            'user_id' => $this->client->id,
        ]);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'cancellation_requested';
        });
    }

    /** @test */
    public function admin_can_approve_cancellation_request_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->admin);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'cancellation_requested_at' => now()->subHour(),
            'cancellation_requested_by' => $this->client->id,
            'cancellation_reason' => 'Client request',
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/approve-cancellation");

        $response->assertOk()
            ->assertJsonPath('message', 'Cancellation request approved. Shoot has been cancelled.');

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_CANCELLED, $shoot->status);
        $this->assertNull($shoot->cancellation_requested_at);
        $this->assertNull($shoot->cancellation_requested_by);

        $activity = DB::table('shoot_activity_logs')
            ->where('shoot_id', $shoot->id)
            ->where('action', 'shoot_cancelled')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $metadata = json_decode($activity->metadata ?? '[]', true);
        $this->assertSame('Client request', $metadata['reason'] ?? null);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'shoot_cancelled';
        });
    }

    /** @test */
    public function cancellation_request_side_effects_notify_client_and_photographer(): void
    {
        $requestedRecipientIds = [];
        $this->rebindWorkflowSupportMailService(function ($mailService) use (&$requestedRecipientIds): void {
            $mailService->shouldReceive('sendShootCancellationRequestedEmail')
                ->twice()
                ->andReturnUsing(function (User $recipient, Shoot $shoot) use (&$requestedRecipientIds) {
                    $requestedRecipientIds[] = (int) $recipient->id;
                    $this->assertSame((int) $this->client->id, (int) $shoot->client_id);
                    $this->assertSame((int) $this->photographer->id, (int) $shoot->photographer_id);

                    return true;
                });
        });

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'cancellation_reason' => 'Seller postponed listing',
        ]);

        $this->app->make(ShootWorkflowTransitionSupportService::class)
            ->sendCancellationRequestSideEffects($shoot, $this->client);

        sort($requestedRecipientIds);
        $this->assertSame(
            [(int) $this->client->id, (int) $this->photographer->id],
            $requestedRecipientIds
        );
    }

    /** @test */
    public function cancellation_completion_side_effects_notify_client_and_photographer(): void
    {
        $cancelledRecipientIds = [];
        $this->rebindWorkflowSupportMailService(function ($mailService) use (&$cancelledRecipientIds): void {
            $mailService->shouldReceive('sendShootCancelledEmail')
                ->twice()
                ->andReturnUsing(function (User $recipient, Shoot $shoot) use (&$cancelledRecipientIds) {
                    $cancelledRecipientIds[] = (int) $recipient->id;
                    $this->assertSame((int) $this->client->id, (int) $shoot->client_id);
                    $this->assertSame((int) $this->photographer->id, (int) $shoot->photographer_id);

                    return true;
                });
        });

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_CANCELLED,
            'workflow_status' => Shoot::STATUS_CANCELLED,
            'cancellation_reason' => 'Client request',
        ]);

        $this->app->make(ShootWorkflowTransitionSupportService::class)
            ->sendCancellationSideEffects($shoot, $this->admin);

        sort($cancelledRecipientIds);
        $this->assertSame(
            [(int) $this->client->id, (int) $this->photographer->id],
            $cancelledRecipientIds
        );
    }

    /** @test */
    public function admin_can_reject_cancellation_request_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->admin);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'cancellation_requested_at' => now()->subHour(),
            'cancellation_requested_by' => $this->client->id,
            'cancellation_reason' => 'Client request',
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/reject-cancellation", [
            'reason' => 'Scheduling still active',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Cancellation request rejected.');

        $shoot->refresh();

        $this->assertNull($shoot->cancellation_requested_at);
        $this->assertNull($shoot->cancellation_requested_by);
        $this->assertNull($shoot->cancellation_reason);

        $activity = DB::table('shoot_activity_logs')
            ->where('shoot_id', $shoot->id)
            ->where('action', 'cancellation_rejected')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $metadata = json_decode($activity->metadata ?? '[]', true);
        $this->assertSame('Scheduling still active', $metadata['rejection_reason'] ?? null);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'cancellation_rejected';
        });
    }

    /** @test */
    public function client_can_request_hold_and_admin_can_approve_it_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->client);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $requestResponse = $this->postJson("/api/shoots/{$shoot->id}/request-hold", [
            'reason' => 'Waiting for staging',
        ]);

        $requestResponse->assertOk()
            ->assertJsonPath('message', 'Hold request submitted. Pending approval.');

        $shoot->refresh();
        $this->assertNotNull($shoot->hold_requested_at);
        $this->assertSame('Waiting for staging', $shoot->hold_reason);

        Sanctum::actingAs($this->admin);

        $approveResponse = $this->postJson("/api/shoots/{$shoot->id}/approve-hold");

        $approveResponse->assertOk()
            ->assertJsonPath('message', 'Hold request approved. Shoot has been placed on hold.');

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_ON_HOLD, $shoot->status);
        $this->assertSame(Shoot::STATUS_ON_HOLD, $shoot->workflow_status);
        $this->assertNull($shoot->hold_requested_at);
        $this->assertNull($shoot->hold_requested_by);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && in_array($event->activityType, ['hold_requested', 'hold_approved'], true);
        });
    }

    /** @test */
    public function admin_can_reject_hold_request_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->admin);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'hold_requested_at' => now()->subHour(),
            'hold_requested_by' => $this->client->id,
            'hold_reason' => 'Pending staging',
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/reject-hold", [
            'reason' => 'Shooter already dispatched',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Hold request rejected.');

        $shoot->refresh();

        $this->assertNull($shoot->hold_requested_at);
        $this->assertNull($shoot->hold_requested_by);
        $this->assertNull($shoot->hold_reason);

        $activity = DB::table('shoot_activity_logs')
            ->where('shoot_id', $shoot->id)
            ->where('action', 'hold_rejected')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $metadata = json_decode($activity->metadata ?? '[]', true);
        $this->assertSame('Shooter already dispatched', $metadata['rejection_reason'] ?? null);
    }

    /** @test */
    public function editing_manager_can_start_editing_and_submit_for_review_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->editingManager);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
            'editor_id' => null,
        ]);

        $startResponse = $this->postJson("/api/shoots/{$shoot->id}/start-editing");

        $startResponse->assertOk()
            ->assertJsonPath('message', 'Shoot moved to editing.');

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_EDITING, $shoot->status);
        $this->assertSame(Shoot::STATUS_EDITING, $shoot->workflow_status);
        $this->assertSame($this->editor->id, $shoot->editor_id);

        $reviewResponse = $this->postJson("/api/shoots/{$shoot->id}/ready-for-review");

        $reviewResponse->assertOk()
            ->assertJsonPath('message', 'Shoot marked as ready for review.');

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_READY, $shoot->status);
        $this->assertSame(Shoot::STATUS_READY, $shoot->workflow_status);
        $this->assertNotNull($shoot->editing_completed_at);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && in_array($event->activityType, ['shoot_editing_started', 'shoot_submitted_for_review'], true);
        });
    }

    /** @test */
    public function start_editing_auto_assigns_lane_specific_editor_without_sqlite_json_length(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->editingManager);

        $this->service->category()->update(['name' => 'Photos']);

        $this->editor->update([
            'metadata' => ['editing_capabilities' => ['photo']],
        ]);

        User::factory()->create([
            'role' => 'editor',
            'name' => 'Workflow Video Editor',
            'email' => 'workflow-video-editor@test.com',
            'metadata' => ['editing_capabilities' => ['video']],
        ]);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
            'editor_id' => null,
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/start-editing");

        $response->assertOk()
            ->assertJsonPath('message', 'Shoot moved to editing.');

        $shoot->refresh();

        $this->assertSame($this->editor->id, $shoot->editor_id);
        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id,
            'service_id' => $this->service->id,
            'editor_id' => $this->editor->id,
        ]);
    }

    /** @test */
    public function editing_manager_can_complete_a_ready_shoot_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->editingManager);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'editor_id' => $this->editor->id,
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/complete");

        $response->assertOk()
            ->assertJsonPath('message', 'Shoot has been completed and delivered.');

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_DELIVERED, $shoot->status);
        $this->assertSame(Shoot::STATUS_DELIVERED, $shoot->workflow_status);
        $this->assertNotNull($shoot->completed_at);

        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'shoot_completed',
            'user_id' => $this->editingManager->id,
        ]);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && in_array($event->activityType, ['shoot_completed', 'shoot_delivered'], true);
        });
    }

    /** @test */
    public function admin_can_decline_requested_shoot_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->admin);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/decline", [
            'reason' => 'Out of coverage area',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Shoot request has been declined.');

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_DECLINED, $shoot->status);
        $this->assertSame(Shoot::STATUS_DECLINED, $shoot->workflow_status);
        $this->assertSame('Out of coverage area', $shoot->declined_reason);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'shoot_declined';
        });
    }

    /** @test */
    public function editing_manager_can_assign_editor_after_refactor(): void
    {
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->editingManager);

        $shoot = $this->makeShoot([
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
            'editor_id' => null,
        ]);

        $response = $this->postJson("/api/shoots/{$shoot->id}/assign-editor", [
            'editor_id' => $this->editor->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Editor assigned successfully');

        $shoot->refresh();

        $this->assertSame($this->editor->id, $shoot->editor_id);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'editor_assigned',
            'user_id' => $this->editingManager->id,
        ]);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'editor_assigned';
        });
    }

    protected function makeShoot(array $overrides = []): Shoot
    {
        $shoot = Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'address' => '77 Workflow Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 180,
            'tax_amount' => 10.80,
            'total_quote' => 190.80,
            'payment_status' => 'unpaid',
        ], $overrides));

        $shoot->services()->attach($this->service->id, [
            'price' => 180,
            'quantity' => 1,
            'photographer_pay' => 55,
            'photographer_id' => $this->photographer->id,
        ]);

        return $shoot;
    }

    protected function bindWorkflowSideEffectFakes(): void
    {
        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('sendShootCancellationRequestedEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('sendShootCancelledEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('sendShootRemovedEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('sendShootReadyEmail')->zeroOrMoreTimes()->andReturnTrue();
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
        $this->app->instance(AutomationService::class, $automationService);

        $brightMlsService = Mockery::mock(BrightMlsService::class);
        $brightMlsService->shouldIgnoreMissing();
        $brightMlsService->shouldReceive('isAutoPublishAvailable')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(BrightMlsService::class, $brightMlsService);
    }

    protected function rebindWorkflowSupportMailService(callable $configure): void
    {
        $this->app->forgetInstance(MailService::class);
        $this->app->forgetInstance(ShootWorkflowTransitionSupportService::class);
        $this->app->forgetInstance(RequestCancellationAction::class);
        $this->app->forgetInstance(ApproveCancellationAction::class);
        $this->app->forgetInstance(ShootWorkflowController::class);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('sendShootRemovedEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('sendShootReadyEmail')->zeroOrMoreTimes()->andReturnTrue();

        $configure($mailService);

        $this->app->instance(MailService::class, $mailService);
    }
}
