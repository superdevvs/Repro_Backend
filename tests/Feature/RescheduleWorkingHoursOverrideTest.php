<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Scheduling\TravelLocationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class RescheduleWorkingHoursOverrideTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;
    private Shoot $shoot;
    private User $actor;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'availability.hybrid_travel_enabled' => true, 'availability.scheduling_lock_store' => 'array']);
        Queue::fake(); Mail::fake(); Http::preventStrayRequests();
        $this->actor = User::factory()->create(['role' => 'superadmin', 'email_verification_required_at' => null]);
        Sanctum::actingAs($this->actor);
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $client = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 35]);
        $photographer->setServiceCapabilities([(string) $service->id]);
        $photographer->save();
        $this->shoot = Shoot::factory()->create(['client_id' => $client->id, 'photographer_id' => $photographer->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'timezone' => 'America/New_York',
            'scheduled_at' => '2026-10-20 21:00:00', 'scheduled_date' => '2026-10-20', 'time' => '17:00']);
        $this->shoot->services()->attach($service->id, ['duration_minutes' => 35, 'price' => 100, 'quantity' => 1,
            'photographer_id' => $photographer->id, 'scheduled_at' => '2026-10-20 21:00:00', 'workflow_status' => 'scheduled']);
        PhotographerAvailability::create(['photographer_id' => $photographer->id, 'date' => '2026-10-20', 'day_of_week' => 'tuesday',
            'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available']);
        $location = Mockery::mock(TravelLocationResolver::class);
        $location->shouldReceive('reset')->andReturnNull();
        $location->shouldReceive('forPayload', 'forShoot')->andReturn(['verified' => true, 'building_key' => 'same']);
        $location->shouldReceive('persistedMetadata')->andReturn([]);
        $this->app->instance(TravelLocationResolver::class, $location);
    }
    private function preview(string $mode = 'reschedule', string $time = '16:30'): array
    {
        return $this->postJson('/api/photographer/availability/feasibility', ['shoot_id' => $this->shoot->id, 'action_mode' => $mode,
            'requested_date' => '2026-10-20', 'requested_time' => $time])->assertOk()->json('data');
    }
    private function flags(array $preview): array
    {
        return ['working_hours_override' => true, 'working_hours_override_confirmed' => true,
            'working_hours_override_reason' => 'Photographer approved the reschedule.',
            'working_hours_override_confirmation_version' => $preview['working_hours_confirmation_version']];
    }
    public function test_adjacent_hours_allow_actual_1630_to_1705_reschedule_without_override(): void
    {
        PhotographerAvailability::create(['photographer_id' => $this->shoot->photographer_id, 'date' => '2026-10-20', 'day_of_week' => 'tuesday',
            'start_time' => '17:00', 'end_time' => '18:00', 'status' => 'available']);
        $this->assertTrue($this->preview()['available']);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/reschedule', ['requested_date' => '2026-10-20', 'requested_time' => '16:30'])->assertCreated();
        $this->assertSame('2026-10-20 20:30:00', $this->shoot->fresh()->getRawOriginal('scheduled_at'));
        $this->assertSame(35, $this->shoot->serviceItems()->sole()->duration_minutes);
    }
    #[\PHPUnit\Framework\Attributes\TestWith(['superadmin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['representative'])]
    public function test_signed_staff_exception_saves_reschedule_and_audit_without_changing_hours(string $role): void
    {
        $this->actor->update(['role' => $role]);
        $preview = $this->preview(); $this->assertTrue($preview['can_override_working_hours']);
        $body = ['requested_date' => '2026-10-20', 'requested_time' => '16:30'];
        $this->postJson('/api/shoots/'.$this->shoot->id.'/reschedule', $body)->assertUnprocessable();
        $this->postJson('/api/shoots/'.$this->shoot->id.'/reschedule', $body + $this->flags($preview))->assertCreated();
        $this->assertSame('16:30', substr($this->shoot->fresh()->time, 0, 5));
        $this->assertSame(1, PhotographerAvailability::count());
        $this->assertSame(1, DB::table('user_activity_logs')->where('event_type', 'schedule.working_hours_override')->count());
    }
    #[\PHPUnit\Framework\Attributes\TestWith(['superadmin', []])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep', []])]
    #[\PHPUnit\Framework\Attributes\TestWith(['editor', ['sales_rep']])]
    public function test_update_edit_can_use_same_explicit_exception(string $role, array $secondary): void
    {
        $this->actor->update(['role' => $role, 'secondary_roles' => $secondary]);
        $body = ['scheduled_at' => '2026-10-20T20:30:00Z'];
        $preview = $this->postJson('/api/photographer/availability/feasibility', $body + ['shoot_id' => $this->shoot->id, 'action_mode' => 'update'])->assertOk()->json('data');
        $this->patchJson('/api/shoots/'.$this->shoot->id, $body + $this->flags($preview))->assertOk();
        $this->assertSame('2026-10-20 20:30:00', $this->shoot->fresh()->getRawOriginal('scheduled_at'));
    }
    public function test_changed_time_missing_reason_and_non_superadmin_cannot_reuse_exception(): void
    {
        $flags = $this->flags($this->preview()); $path = '/api/shoots/'.$this->shoot->id.'/reschedule';
        $this->postJson($path, ['requested_date' => '2026-10-20', 'requested_time' => '16:40'] + $flags)->assertUnprocessable();
        $flags['working_hours_override_reason'] = '';
        $this->postJson($path, ['requested_date' => '2026-10-20', 'requested_time' => '16:30'] + $flags)->assertUnprocessable();
        $this->actor->update(['role' => 'admin']);
        $flags['working_hours_override_reason'] = 'Attempted forged override';
        $this->postJson($path, ['requested_date' => '2026-10-20', 'requested_time' => '16:30'] + $flags)->assertForbidden();
        $this->assertSame('17:00', substr($this->shoot->fresh()->time, 0, 5));
    }
    public function test_real_gap_or_unavailable_block_remains_a_working_hours_warning(): void
    {
        PhotographerAvailability::create(['photographer_id' => $this->shoot->photographer_id, 'date' => '2026-10-20', 'day_of_week' => 'tuesday',
            'start_time' => '17:01', 'end_time' => '18:00', 'status' => 'available']);
        $this->assertContains('outside_working_hours', $this->preview()['reason_codes']);
        PhotographerAvailability::where('start_time', '17:01')->update(['start_time' => '17:00']);
        PhotographerAvailability::create(['photographer_id' => $this->shoot->photographer_id, 'date' => '2026-10-20', 'day_of_week' => 'tuesday',
            'start_time' => '16:45', 'end_time' => '16:46', 'status' => 'unavailable']);
        $this->assertContains('outside_working_hours', $this->preview()['reason_codes']);
    }
    #[\PHPUnit\Framework\Attributes\TestWith(['superadmin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_working_hours_confirmation_never_allows_capture_overlap(string $role): void
    {
        $this->actor->update(['role' => $role]);
        $flags = $this->flags($this->preview());
        $other = $this->shoot->replicate();
        $other->scheduled_at = '2026-10-20 20:45:00'; $other->time = '16:45'; $other->save();
        $line = $this->shoot->serviceItems()->sole();
        $other->services()->attach($line->service_id, ['duration_minutes' => 35, 'photographer_id' => $this->shoot->photographer_id,
            'scheduled_at' => '2026-10-20 20:45:00', 'workflow_status' => 'scheduled']);
        $preview = $this->preview();
        $this->assertFalse($preview['can_override_working_hours']);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/reschedule', ['requested_date' => '2026-10-20', 'requested_time' => '16:30'] + $flags)->assertUnprocessable();
        $this->assertSame('17:00', substr($this->shoot->fresh()->time, 0, 5));
    }
    #[\PHPUnit\Framework\Attributes\TestWith(['superadmin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_multi_unit_reschedule_uses_the_same_signed_exception(string $role): void
    {
        $this->actor->update(['role' => $role]);
        $unit = $this->shoot->units()->create(['client_key' => 'unit-one', 'label' => 'One', 'kind' => 'unit', 'sort_order' => 0]);
        $this->shoot->serviceItems()->sole()->update(['shoot_unit_id' => $unit->id, 'client_key' => 'photos']);
        $this->shoot->forceFill(['units_revision' => 1])->save();
        $flags = $this->flags($this->preview());
        $this->postJson('/api/shoots/'.$this->shoot->id.'/reschedule', ['requested_date' => '2026-10-20', 'requested_time' => '16:30', 'expected_units_revision' => 1] + $flags)->assertCreated();
        $this->assertSame('2026-10-20 20:30:00', $this->shoot->serviceItems()->sole()->getRawOriginal('scheduled_at'));
    }

    public function test_sales_rep_cannot_confirm_after_booking_permission_is_revoked(): void
    {
        $this->actor->update(['role' => 'salesRep']);
        $flags = $this->flags($this->preview());
        $this->actor->update(['permission_overrides' => ['allow' => [], 'deny' => ['shoots-update', 'shoots-manage']]]);
        $body = ['requested_date' => '2026-10-20', 'requested_time' => '16:30'];
        $this->postJson('/api/shoots/'.$this->shoot->id.'/reschedule', $body + $flags)->assertForbidden();
        $this->patchJson('/api/shoots/'.$this->shoot->id, ['scheduled_at' => '2026-10-20T20:30:00Z'] + $flags)->assertForbidden();
        $this->assertSame('17:00', substr($this->shoot->fresh()->time, 0, 5));
        $this->assertSame(0, DB::table('user_activity_logs')->where('event_type', 'schedule.working_hours_override')->count());
    }
}
