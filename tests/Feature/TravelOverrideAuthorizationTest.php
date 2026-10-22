<?php

namespace Tests\Feature;

use App\Exceptions\PublicApiResponseException;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\TravelScheduleAccess;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class TravelOverrideAuthorizationTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    public function test_booking_manager_can_edit_another_rep_shoot_and_shared_review_uses_the_guard(): void
    {
        config(['availability.hybrid_travel_enabled' => true]);
        Queue::fake();
        $rep = User::factory()->create(['role' => 'salesRep', 'email_verification_required_at' => null]);
        $shoot = Shoot::factory()->create(['rep_id' => User::factory()->create(['role' => 'salesRep'])->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'timezone' => 'America/New_York',
            'scheduled_at' => '2026-10-15 14:00:00', 'scheduled_date' => '2026-10-15', 'time' => '10:00']);
        $service = Service::factory()->create(['photographer_required' => true]);
        $photographer = $shoot->photographer;
        $photographer->setServiceCapabilities([(string) $service->id]);
        $photographer->save();
        $shoot->services()->attach($service->id, ['photographer_id' => $shoot->photographer_id,
            'scheduled_at' => '2026-10-15 14:00:00', 'duration_minutes' => 15, 'workflow_status' => 'scheduled']);
        $flags = ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_confirmation_version' => str_repeat('a', 64), 'travel_override_reason' => 'Reviewed by the request reviewer'];
        $access = app(TravelScheduleAccess::class);
        $this->assertTrue($access->canOverride(['action_mode' => 'update'], $shoot, $rep));
        Sanctum::actingAs($rep);
        $this->patchJson('/api/shoots/'.$shoot->id, $flags + ['scheduled_at' => '2026-10-16T14:00:00Z',
            'action_mode' => 'reschedule'])->assertOk();
        $this->assertSame('2026-10-16 14:00:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
        $pending = ShootRescheduleRequest::create(['shoot_id' => $shoot->id, 'requested_by' => $shoot->client_id,
            'requested_date' => '2026-10-16', 'requested_time' => '10:00', 'status' => 'pending']);
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldReceive('evaluate')->once()->andReturnUsing(function ($payload, $target, $actor) use ($access) {
            $this->assertSame('reschedule', $payload['action_mode']);
            $this->assertTrue($payload['travel_override_confirmed']);
            $this->assertSame(str_repeat('a', 64), $payload['travel_override_confirmation_version']);
            $this->assertTrue($access->canOverride($payload, $target, $actor));
            return ['enabled' => true];
        });
        $engine->shouldReceive('assertResult')->once()->andThrow(new PublicApiResponseException(response()->json(['message' => 'Authorized review reached the travel guard'], 422)));
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $this->patchJson('/api/shoots/reschedule-requests/'.$pending->id, $flags + ['status' => 'approved'])
            ->assertUnprocessable()->assertJsonPath('message', 'Authorized review reached the travel guard');
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_rep_assignment_request_hold_and_import_boundaries_preserve_role_aliases(): void
    {
        $rep = User::factory()->create(['role' => 'sales_rep']);
        $shoot = Shoot::factory()->create(['rep_id' => $rep->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $access = app(TravelScheduleAccess::class);
        $this->assertTrue($access->canOverride(['action_mode' => 'create'], null, $rep));
        $this->assertTrue($access->canOverride(['action_mode' => 'update'], $shoot, $rep));
        $shoot->rep_id = null;
        $this->assertTrue($access->canOverride(['action_mode' => 'update'], $shoot, $rep));
        $shoot->status = $shoot->workflow_status = 'requested';
        $this->assertTrue($access->canOverride(['action_mode' => 'approve'], $shoot, $rep));
        $shoot->status = $shoot->workflow_status = 'on_hold';
        $this->assertTrue($access->canOverride(['action_mode' => 'schedule'], $shoot, $rep));
        $shoot->status = $shoot->workflow_status = Shoot::STATUS_IMPORT_DRAFT;
        $this->assertFalse($access->canOverride(['action_mode' => 'reschedule'], $shoot, $rep));
    }

    public function test_secondary_sales_role_uses_booking_permissions_and_respects_explicit_denials(): void
    {
        $rep = User::factory()->create(['role' => 'editor', 'secondary_roles' => ['sales_rep']]);
        $shoot = Shoot::factory()->create(['rep_id' => $rep->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $access = app(TravelScheduleAccess::class);
        $this->assertTrue($access->canOverride(['action_mode' => 'create'], null, $rep));
        $this->assertTrue($access->canOverride(['action_mode' => 'update'], $shoot, $rep));
        $this->assertTrue($access->canOverride(['action_mode' => 'reschedule'], $shoot, $rep));
        $this->assertTrue($access->canOverride(['action_mode' => 'alternate'], $shoot, $rep));
        $shoot->status = $shoot->workflow_status = Shoot::STATUS_IMPORT_DRAFT;
        $this->assertFalse($access->canOverride(['action_mode' => 'reschedule'], $shoot, $rep));
        $shoot->status = $shoot->workflow_status = 'scheduled';
        $rep->permission_overrides = ['allow' => [], 'deny' => ['book-shoot-create', 'shoots-update', 'shoots-manage']];
        $this->assertFalse($access->canOverride(['action_mode' => 'create'], null, $rep));
        $this->assertFalse($access->canOverride(['action_mode' => 'update'], $shoot, $rep));
        $this->assertFalse($access->canOverride(['action_mode' => 'reschedule'], $shoot, $rep));
    }

    public function test_authorized_rep_cannot_forge_a_travel_warning_confirmation(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot = Shoot::factory()->create(['status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $this->expectException(PublicApiResponseException::class);
        app(ScheduleFeasibilityService::class)->assertResult(['enabled' => true, 'available' => false, 'can_override' => true], [
            'action_mode' => 'update', 'travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_confirmation_version' => str_repeat('a', 64), 'travel_override_reason' => 'Reviewed this travel warning',
        ], $shoot, $rep);
    }
}
