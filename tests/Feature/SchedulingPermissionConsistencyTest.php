<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootManagementAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchedulingPermissionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake(); Notification::fake(); Queue::fake();
    }

    private function actor(string $role, array $secondary = [], array $deny = []): User
    {
        return User::factory()->create(['role' => $role, 'secondary_roles' => $secondary,
            'email_verification_required_at' => null,
            'permission_overrides' => ['allow' => [], 'deny' => $deny]]);
    }

    private function shoot(?User $client = null): Shoot
    {
        $shoot = Shoot::factory()->create(['client_id' => ($client ?? $this->actor('client'))->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'timezone' => 'UTC',
            'payment_status' => 'unpaid',
            'scheduled_at' => '2031-10-09 10:00:00', 'scheduled_date' => '2031-10-09', 'time' => '10:00 AM',
            'photographer_id' => $this->actor('photographer')->id]);
        $service = Service::factory()->noIntake()->create(['photographer_required' => false]);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'duration_minutes' => 0,
            'scheduled_at' => '2031-10-09 10:00:00', 'photographer_id' => $shoot->photographer_id]);

        return $shoot->fresh();
    }

    private function pending(Shoot $shoot): ShootRescheduleRequest
    {
        return ShootRescheduleRequest::create(['shoot_id' => $shoot->id, 'requested_by' => $shoot->client_id,
            'original_date' => '2031-10-09', 'original_time' => '10:00 AM',
            'requested_date' => '2031-10-10', 'requested_time' => '11:00 AM', 'status' => 'pending'])->fresh();
    }

    public static function officeRoles(): array
    {
        return [['admin', []], ['editing_manager', []], ['salesRep', []],
            ['admin', ['sales_rep']], ['editor', ['admin', 'sales-rep']], ['editor', ['admin']], ['photographer', ['editing-manager']]];
    }

    #[DataProvider('officeRoles')]
    public function test_update_denial_blocks_every_appointment_write_without_side_effects(string $role, array $secondary): void
    {
        $actor = $this->actor($role, $secondary, ['shoots-update']);
        $shoot = $this->shoot();
        $record = $this->pending($shoot);
        $other = $this->actor('photographer');
        $line = $shoot->serviceItems()->sole();
        $before = $shoot->getRawOriginal();
        $lineBefore = $line->getRawOriginal();
        $requestBefore = $record->getRawOriginal();
        Sanctum::actingAs($actor);

        $this->patchJson("/api/shoots/{$shoot->id}", ['photographer_id' => $other->id])->assertForbidden();
        $this->patchJson("/api/shoots/{$shoot->id}", ['city' => 'Denied property change'])->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/schedule", ['scheduled_at' => '2031-10-10T11:00:00Z'])->assertForbidden();
        $assignment = ['service_id' => $line->service_id, 'photographer_id' => $other->id];
        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", $assignment)->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographers", ['assignments' => [$assignment]])->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/reschedule", ['requested_date' => '2031-10-10', 'requested_time' => '11:00 AM'])->assertForbidden();
        $this->patchJson("/api/shoots/reschedule-requests/{$record->id}", ['status' => 'approved'])->assertForbidden();

        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        $this->assertSame($lineBefore, $line->fresh()->getRawOriginal());
        $this->assertSame($requestBefore, $record->fresh()->getRawOriginal());
        $this->assertDatabaseCount('shoot_reschedule_requests', 1);
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_update_denial_covers_alternate_schedule_classification_and_neighbor_moves(): void
    {
        $actor = $this->actor('admin', [], ['shoots-update']);
        $shoot = $this->shoot();
        $before = $shoot->getRawOriginal();
        Sanctum::actingAs($actor);
        foreach ([['alternate_scheduled_date' => '2031-10-10'], ['shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST],
            ['schedule_adjustments' => []], ['units' => []], ['total_quote' => 1]] as $payload) {
            $this->patchJson("/api/shoots/{$shoot->id}", $payload)->assertForbidden();
        }
        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    #[DataProvider('officeRoles')]
    public function test_requested_approval_requires_edit_and_manage_permissions(string $role, array $secondary): void
    {
        $shoot = $this->shoot();
        $shoot->update(['status' => 'requested', 'workflow_status' => 'requested']);
        $before = $shoot->refresh()->getRawOriginal();
        foreach (['shoots-update', 'shoots-manage'] as $denied) {
            Sanctum::actingAs($this->actor($role, $secondary, [$denied]));
            $this->postJson("/api/shoots/{$shoot->id}/approve", ['notify_client' => false, 'notify_photographer' => false])->assertForbidden();
            $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        }
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    #[DataProvider('officeRoles')]
    public function test_manage_denial_blocks_review_and_schedule_without_removing_edit_rights(string $role, array $secondary): void
    {
        $actor = $this->actor($role, $secondary, ['shoots-manage']);
        $shoot = $this->shoot();
        $record = $this->pending($shoot);
        $before = $shoot->getRawOriginal();
        Sanctum::actingAs($actor);
        $access = app(ShootAuthorizationSupport::class);
        $this->assertTrue($access->canEditShootAppointment($shoot, $actor));
        $this->assertFalse($access->canReviewShootRequests($actor));
        $this->assertFalse($access->canApproveReschedule($shoot, $actor));
        $this->assertFalse($access->canRequestReschedule($shoot, $actor));
        $this->getJson('/api/shoots/pending-reschedules')->assertForbidden();
        $this->patchJson("/api/shoots/reschedule-requests/{$record->id}", ['status' => 'rejected'])->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/reschedule", ['requested_date' => '2031-10-10'])->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/schedule", ['scheduled_at' => '2031-10-10T11:00:00Z'])->assertForbidden();
        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        $this->assertTrue($record->fresh()->isPending());
        Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_reviewer_with_update_denied_can_reject_but_queue_and_detail_disable_approval(): void
    {
        $actor = $this->actor('admin', [], ['shoots-update']);
        $shoot = $this->shoot();
        $record = $this->pending($shoot);
        $before = $shoot->getRawOriginal();
        Sanctum::actingAs($actor);
        $this->getJson('/api/shoots/pending-reschedules?status=pending')->assertOk()
            ->assertJsonPath('data.0.canReviewRequests', true)->assertJsonPath('data.0.canApproveReschedule', false);
        $this->getJson("/api/shoots/{$shoot->id}")->assertOk()
            ->assertJsonPath('data.canEditAppointment', false)->assertJsonPath('data.canReassignPhotographer', false)
            ->assertJsonPath('data.canApproveReschedule', false)->assertJsonPath('data.canRequestReschedule', false);
        $this->patchJson("/api/shoots/reschedule-requests/{$record->id}", ['status' => 'rejected'])->assertOk();
        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        $this->assertSame('rejected', $record->fresh()->status);
        $this->assertNull($record->fresh()->applied_at);
    }

    public function test_client_with_denied_secondary_office_role_cannot_fall_back_to_a_pending_request(): void
    {
        $actor = $this->actor('client', ['salesRep'], ['shoots-update']);
        $shoot = $this->shoot($actor);
        Sanctum::actingAs($actor);
        $this->postJson("/api/shoots/{$shoot->id}/reschedule", ['requested_date' => '2031-10-10'])->assertForbidden();
        $this->assertDatabaseCount('shoot_reschedule_requests', 0);
    }

    public function test_queue_capabilities_keep_workflow_and_historical_booking_locks(): void
    {
        $rep = $this->actor('salesRep');
        $delivered = $this->shoot();
        $delivered->update(['workflow_status' => 'delivered']);
        $historical = $this->shoot();
        $historical->update(['external_booking_payload' => ['legacy_migration' => ['historical_payments_only' => true]]]);
        $this->pending($delivered);
        $this->pending($historical);
        Sanctum::actingAs($rep);
        $rows = $this->getJson('/api/shoots/pending-reschedules?status=pending')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row['canReviewRequests']);
            $this->assertFalse($row['canApproveReschedule']);
        }
    }

    public function test_secondary_office_role_grants_booking_edits_without_production_payment_or_status_writes(): void
    {
        $actor = $this->actor('editor', ['admin']);
        $shoot = $this->shoot();
        Sanctum::actingAs($actor);
        foreach ([['editor_id' => $actor->id], ['status' => 'delivered'], ['workflow_status' => 'delivered'],
            ['payment_status' => 'paid']] as $payload) {
            $this->patchJson("/api/shoots/{$shoot->id}", $payload)->assertForbidden();
        }
        $this->patchJson("/api/shoots/{$shoot->id}", ['city' => 'Allowed booking correction',
            'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertSame('Allowed booking correction', $shoot->fresh()->city);
        $this->assertSame('scheduled', $shoot->fresh()->status);
        $this->assertNotSame('paid', $shoot->fresh()->payment_status);
        $this->assertNotSame($actor->id, $shoot->fresh()->editor_id);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_clients_and_assigned_contractors_keep_request_only_access(): void
    {
        $client = $this->actor('client');
        $shoot = $this->shoot($client);
        $before = $shoot->getRawOriginal();
        foreach ([$client, $shoot->photographer] as $actor) {
            Sanctum::actingAs($actor);
            $this->assertTrue(app(ShootAuthorizationSupport::class)->canRequestReschedule($shoot, $actor));
            $this->postJson("/api/shoots/{$shoot->id}/reschedule", ['requested_date' => '2031-10-10'])->assertCreated()
                ->assertJsonPath('applied', false)->assertJsonPath('data.status', 'pending');
            $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        }
        Sanctum::actingAs($this->actor('photographer'));
        $this->postJson("/api/shoots/{$shoot->id}/reschedule", ['requested_date' => '2031-10-10'])->assertForbidden();
    }

    public function test_contractor_time_only_exception_is_assignment_scoped_and_respects_explicit_denials(): void
    {
        $shoot = $this->shoot();
        $actor = $shoot->photographer;
        $access = app(ShootAuthorizationSupport::class);
        $this->assertTrue($access->canScheduleShoot($shoot, $actor));
        $this->assertFalse($access->canEditShootAppointment($shoot, $actor));
        $this->assertFalse($access->canScheduleShoot($shoot, $this->actor('photographer')));
        foreach (['shoots-update', 'shoots-manage'] as $denied) {
            $actor->update(['permission_overrides' => ['deny' => [$denied], 'allow' => []]]);
            $this->assertFalse($access->canScheduleShoot($shoot, $actor));
        }
    }

    public function test_privileged_secondary_roles_are_not_demoted_by_sales_and_superadmin_stays_locked(): void
    {
        $shoot = $this->shoot();
        $shoot->status = $shoot->workflow_status = 'delivered';
        $management = app(ShootManagementAccess::class);
        foreach ([$this->actor('admin', ['salesRep']), $this->actor('editor', ['admin', 'sales_rep'])] as $actor) {
            $this->assertFalse($management->isRestrictedSalesRep($actor));
            $this->assertTrue($management->canEditAppointment($shoot, $actor));
        }
        $rep = $this->actor('salesRep');
        $this->assertFalse(app(ShootAuthorizationSupport::class)->canApproveReschedule($shoot, $rep));
        $admin = $this->actor('superadmin', [], ['shoots-update', 'shoots-manage']);
        $this->assertTrue(app(ShootAuthorizationSupport::class)->canApproveReschedule($shoot, $admin));
    }

    public function test_capability_projection_reads_the_role_map_once(): void
    {
        $actor = $this->actor('admin');
        $shoot = $this->shoot();
        // Initialize defaults before measuring the read-only projection.
        app(ShootManagementAccess::class)->can($actor);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $capabilities = app(ShootAuthorizationSupport::class)->schedulingCapabilities($shoot, $actor);
            $reads = collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with(strtolower($query['query']), 'select')
                && in_array('permissions.role_map.v1', $query['bindings'], true));
            $this->assertCount(1, $reads);
            $this->assertTrue($capabilities['canEditAppointment']);
            $this->assertTrue($capabilities['canApproveReschedule']);
        } finally {
            DB::disableQueryLog();
        }
    }
}
