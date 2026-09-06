<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\TourEvent;
use App\Models\User;
use App\Services\Shoots\Actions\ApproveShootAction;
use App\Services\Shoots\Actions\ScheduleShootAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class ShootAdjacentAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_unassigned_actors_cannot_read_activity_or_analytics(): void
    {
        $shoot = $this->shoot();
        foreach (['salesRep', 'sales_rep', 'rep', 'representative', 'editor', 'photographer', 'client', 'unknown_role'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson("/api/shoots/{$shoot->id}/activity-log")->assertForbidden();
            $this->getJson("/api/shoots/{$shoot->id}/tour-analytics")->assertForbidden();
        }
    }

    public function test_assigned_sales_aliases_owners_and_operational_staff_keep_read_access(): void
    {
        $shoot = $this->shoot();
        $shoot->activityLogs()->create([
            'user_id' => $shoot->client_id, 'action' => 'note_added',
            'description' => 'Assigned shoot activity', 'metadata' => [],
        ]);
        TourEvent::create([
            'shoot_id' => $shoot->id, 'event_type' => 'page_view', 'tour_type' => 'branded',
            'visitor_id' => 'test-visitor', 'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);

        foreach (['salesRep', 'sales_rep', 'rep', 'representative', 'admin', 'superadmin', 'editing_manager', 'client'] as $role) {
            $user = $role === 'client' ? $shoot->client : User::factory()->create(['role' => $role]);
            if (in_array($role, ['salesRep', 'sales_rep', 'rep', 'representative'], true)) {
                $shoot->update(['rep_id' => $user->id]);
            }
            Sanctum::actingAs($user);
            $this->getJson("/api/shoots/{$shoot->id}/activity-log")->assertOk()
                ->assertJsonPath('data.0.description', 'Assigned shoot activity');
            $this->getJson("/api/shoots/{$shoot->id}/tour-analytics")->assertOk()
                ->assertJsonPath('summary.total_views', 1);
        }
    }

    public function test_unassigned_contractors_cannot_reach_schedule_action_or_change_assignments(): void
    {
        $shoot = $this->shoot();
        $before = $shoot->fresh()->getAttributes();
        $this->mock(ScheduleShootAction::class, fn (MockInterface $mock) => $mock->shouldNotReceive('execute'));
        foreach (['editor', 'photographer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            Sanctum::actingAs($user);
            $this->postJson("/api/shoots/{$shoot->id}/schedule", [
                'scheduled_at' => now()->addDays(5)->toIso8601String(),
                'photographer_id' => $user->id,
            ])->assertForbidden();
        }
        $this->assertSame($before, $shoot->fresh()->getAttributes());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_assigned_contractors_and_admins_still_reach_the_schedule_action(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = $this->shoot(['editor_id' => $editor->id]);
        $servicePhotographer = User::factory()->create(['role' => 'photographer']);
        $shoot->services()->attach($shoot->service_id, ['photographer_id' => $servicePhotographer->id, 'price' => 100, 'quantity' => 1]);
        $actors = [$shoot->photographer, $servicePhotographer, $editor,
            User::factory()->create(['role' => 'admin']), User::factory()->create(['role' => 'superadmin'])];
        $actorIds = array_map(fn (User $user) => $user->id, $actors);
        $this->mock(ScheduleShootAction::class, function (MockInterface $mock) use ($shoot, $actorIds) {
            $mock->shouldReceive('execute')->times(count($actorIds))
                ->withArgs(fn ($request, Shoot $actual, User $actor) => $actual->id === $shoot->id && in_array($actor->id, $actorIds, true))
                ->andReturnUsing(fn ($request, Shoot $actual) => $actual);
        });
        foreach ($actors as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/shoots/{$shoot->id}/schedule", ['scheduled_at' => now()->addDays(5)->toIso8601String()])
                ->assertOk()->assertJsonPath('data.id', (string) $shoot->id);
        }
    }

    public function test_unassigned_reps_cannot_approve_even_when_client_relation_is_missing(): void
    {
        $shoot = $this->shoot(['status' => Shoot::STATUS_REQUESTED, 'workflow_status' => Shoot::STATUS_REQUESTED]);
        DB::table('users')->where('id', $shoot->client_id)->update(['deleted_at' => now()]);
        $before = $shoot->fresh()->getAttributes();
        $this->mock(ApproveShootAction::class, fn (MockInterface $mock) => $mock->shouldNotReceive('execute'));
        foreach (['salesRep', 'sales_rep', 'rep', 'representative'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson("/api/shoots/{$shoot->id}/approve")->assertForbidden();
        }
        $this->assertSame($before, $shoot->fresh()->getAttributes());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_approval_uses_shoot_assignment_for_every_sales_alias(): void
    {
        $this->mock(ApproveShootAction::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->times(4)
                ->withArgs(fn ($request, Shoot $shoot, User $user) => (string) $shoot->rep_id === (string) $user->id)
                ->andReturnUsing(fn ($request, Shoot $shoot) => $shoot);
        });
        foreach (['salesRep', 'sales_rep', 'rep', 'representative'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $shoot = $this->shoot(['rep_id' => $user->id, 'status' => Shoot::STATUS_REQUESTED, 'workflow_status' => Shoot::STATUS_REQUESTED]);
            Sanctum::actingAs($user);
            $this->postJson("/api/shoots/{$shoot->id}/approve")->assertOk()->assertJsonPath('data.id', (string) $shoot->id);
        }
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED,
        ], $attributes));
    }
}
