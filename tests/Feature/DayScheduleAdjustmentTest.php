<?php

namespace Tests\Feature;

use App\Jobs\ProcessCreatedShootSideEffectsJob;
use App\Jobs\ProcessUpdatedShootSideEffectsJob;
use App\Jobs\SyncShootToGoogleCalendarJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Scheduling\ScheduleCommitGuard;
use App\Services\Scheduling\TravelLocationResolver;
use App\Services\Scheduling\TravelTimeEstimator;
use App\Services\Shoots\ShootManagementAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class DayScheduleAdjustmentTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    private User $admin;
    private User $client;
    private User $photographer;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'availability.hybrid_travel_enabled' => true,
            'availability.scheduling_lock_store' => 'array', 'availability.fallback_start_time' => '00:00',
            'availability.fallback_end_time' => '23:59']);
        Queue::fake(); Mail::fake(); Http::preventStrayRequests();
        $this->admin = User::factory()->admin()->create(['email_verification_required_at' => null]);
        $this->client = User::factory()->create(['role' => 'client', 'email_verification_required_at' => null]);
        $this->photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $this->service = Service::factory()->create(['name' => 'HDR Photos and Video', 'price' => 100,
            'photographer_required' => true, 'shoot_duration_minutes' => 135]);
        Sanctum::actingAs($this->admin);
        $locations = Mockery::mock(TravelLocationResolver::class);
        $locations->shouldReceive('reset')->andReturnNull();
        $locations->shouldReceive('forPayload')->andReturn(['verified' => true, 'building_key' => 'new']);
        $locations->shouldReceive('forShoot')->andReturn(['verified' => true, 'building_key' => 'old']);
        $locations->shouldReceive('persistedMetadata')->andReturn([]);
        $this->app->instance(TravelLocationResolver::class, $locations);
        $estimator = Mockery::mock(TravelTimeEstimator::class);
        $estimator->shouldReceive('reset')->andReturnNull();
        $estimator->shouldReceive('estimate')->andReturnUsing(function () {
            $this->assertSame(0, DB::transactionLevel());
            return ['source' => 'google_routes', 'required_minutes' => 35, 'review_required' => false,
                'reason_code' => null, 'drive_minutes' => 30, 'distance_miles' => 10.2, 'attribution' => 'Google Maps'];
        });
        $this->app->instance(TravelTimeEstimator::class, $estimator);
    }

    private function booked(string $day = '2026-10-09', bool $legacy = false): Shoot
    {
        $at = Carbon::parse($day.' 10:30', 'America/New_York');
        $stored = $legacy ? $at->copy()->shiftTimezone('UTC') : $at->copy()->utc();
        $shoot = Shoot::factory()->create(['client_id' => $this->client->id, 'photographer_id' => $this->photographer->id,
            'scheduled_at' => $stored, 'scheduled_date' => $day, 'time' => '10:30',
            'timezone' => $legacy ? null : 'America/New_York', 'address' => '2507 Baltimore Road',
            'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $shoot->services()->attach($this->service->id, ['duration_minutes' => 135, 'price' => 100, 'quantity' => 1,
            'photographer_id' => $this->photographer->id, 'scheduled_at' => $stored, 'workflow_status' => 'scheduled']);
        return $shoot;
    }

    private function payload(Shoot $neighbor, string $start = '12:00'): array
    {
        return ['client_id' => $this->client->id, 'photographer_id' => $this->photographer->id,
            'address' => '4421 Bradley Lane', 'city' => 'Chevy Chase', 'state' => 'MD', 'zip' => '20815',
            'scheduled_at' => '2026-10-09T'.$start.':00-04:00', 'timezone' => 'America/New_York',
            'services' => [['id' => $this->service->id, 'quantity' => 1, 'duration_minutes' => 150]],
            'notify_client' => false, 'notify_photographer' => true,
            'schedule_adjustments' => [['shoot_id' => $neighbor->id, 'photographer_id' => $this->photographer->id,
                'from_start' => '2026-10-09T10:30:00-04:00', 'scheduled_at' => '2026-10-09T09:00:00-04:00',
                'expected_edit_version' => app(ShootManagementAccess::class)->editVersion($neighbor->fresh())]]];
    }

    public function test_creates_and_moves_neighbor_atomically_with_separate_notifications_and_calendar_jobs(): void
    {
        $neighbor = $this->booked(); $payload = $this->payload($neighbor);
        $this->postJson('/api/photographer/availability/feasibility', $payload)->assertOk()->assertJsonPath('data.available', true);
        $id = $this->postJson('/api/shoots', $payload)->assertCreated()->json('data.id');
        $this->assertSame('09:00', substr($neighbor->fresh()->time, 0, 5));
        $this->assertSame('2026-10-09 13:00:00', $neighbor->fresh()->getRawOriginal('scheduled_at'));
        $this->assertSame('2026-10-09 13:00:00', $neighbor->serviceItems()->sole()->getRawOriginal('scheduled_at'));
        Queue::assertPushed(ProcessUpdatedShootSideEffectsJob::class, fn ($job) => $job->shootId === $neighbor->id && $job->notifyClient === false && $job->notifyPhotographer === true);
        Queue::assertPushed(ProcessCreatedShootSideEffectsJob::class, fn ($job) => $job->shootId === (int) $id && $job->notifyClient === false && $job->notifyPhotographer === true);
        Queue::assertPushed(SyncShootToGoogleCalendarJob::class, fn ($job) => $job->shootId === $neighbor->id);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_update_uses_same_batch_and_preview_confirmation_for_short_gap(string $role): void
    {
        $this->admin->update(['role' => $role]);
        $neighbor = $this->booked();
        $main = $this->booked('2026-10-08');
        $payload = $this->payload($neighbor, '11:30');
        $payload['shoot_id'] = $main->id;
        $payload['action_mode'] = 'update';
        $payload['expected_edit_version'] = app(ShootManagementAccess::class)->editVersion($main->fresh());
        $preview = $this->postJson('/api/photographer/availability/feasibility', $payload)->assertOk()->assertJsonPath('data.can_override', true)->json('data');
        $payload += ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_reason' => 'Photographer accepts the 15 minute travel gap.',
            'travel_override_confirmation_version' => $preview['confirmation_version']];
        $this->patchJson('/api/shoots/'.$main->id, $payload)->assertOk();
        $this->assertSame('11:30', substr($main->fresh()->time, 0, 5));
        $this->assertSame('09:00', substr($neighbor->fresh()->time, 0, 5));
    }

    public function test_failed_target_write_rolls_back_neighbor_and_outbound_jobs(): void
    {
        $neighbor = $this->booked(); $guard = app(ScheduleCommitGuard::class);
        $prepared = $guard->prepare($this->payload($neighbor), null, $this->admin);
        try {
            $guard->commit($prepared, fn () => throw new \RuntimeException('Target write failed'));
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) { $this->assertSame('Target write failed', $e->getMessage()); }
        $this->assertSame('10:30', substr($neighbor->fresh()->time, 0, 5));
        Queue::assertNothingPushed();
    }

    public function test_stale_or_other_date_neighbor_cannot_be_changed(): void
    {
        $neighbor = $this->booked(); $payload = $this->payload($neighbor);
        $neighbor->update(['address' => 'Changed address']);
        $this->postJson('/api/shoots', $payload)->assertConflict();
        $payload = $this->payload($neighbor);
        $payload['schedule_adjustments'][0]['scheduled_at'] = '2026-10-08T09:00:00-04:00';
        $this->postJson('/api/shoots', $payload)->assertUnprocessable();
        $this->assertSame('10:30', substr($neighbor->fresh()->time, 0, 5));
        Queue::assertNothingPushed();
    }

    public function test_legacy_floating_schedule_stays_floating(): void
    {
        $neighbor = $this->booked(legacy: true);
        $this->postJson('/api/shoots', $this->payload($neighbor))->assertCreated();
        $this->assertNull($neighbor->fresh()->timezone);
        $this->assertSame('2026-10-09 09:00:00', $neighbor->fresh()->getRawOriginal('scheduled_at'));
        $this->assertSame('2026-10-09 09:00:00', $neighbor->serviceItems()->sole()->getRawOriginal('scheduled_at'));
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_day_endpoint_filters_date_and_hides_other_client_booking_details(string $role): void
    {
        $this->admin->update(['role' => $role]);
        $today = $this->booked(); $this->booked('2026-10-08'); $this->booked('2026-10-10');
        $query = '/api/photographer/availability/day-schedule?photographer_id='.$this->photographer->id.'&date=2026-10-09&timezone=America%2FNew_York';
        $this->getJson($query)->assertOk()->assertJsonCount(1, 'data.bookings')->assertJsonPath('data.bookings.0.can_adjust', true);
        Sanctum::actingAs(User::factory()->create(['role' => 'client', 'email_verification_required_at' => null]));
        $this->getJson($query)->assertOk()->assertJsonPath('data.bookings.0.label', 'Booked')->assertJsonPath('data.bookings.0.shoot_id', null)->assertJsonPath('data.bookings.0.expected_edit_version', null)->assertJsonPath('data.bookings.0.can_adjust', false);
        $this->postJson('/api/photographer/availability/feasibility', $this->payload($today))->assertForbidden();
    }

    public function test_final_overlap_cannot_be_overridden(): void
    {
        $neighbor = $this->booked(); $payload = $this->payload($neighbor, '10:00');
        $payload += ['travel_override' => true, 'travel_override_confirmed' => true, 'travel_override_reason' => 'Please allow overlap.'];
        $this->postJson('/api/shoots', $payload)->assertUnprocessable();
        $this->assertSame('10:30', substr($neighbor->fresh()->time, 0, 5));
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_duration_only_reduction_saves_the_booked_line_and_new_shoot_together(string $role): void
    {
        $this->admin->update(['role' => $role]);
        $neighbor = $this->booked(); $payload = $this->payload($neighbor);
        $line = $neighbor->serviceItems()->sole();
        $payload['schedule_adjustments'][0]['scheduled_at'] = $payload['schedule_adjustments'][0]['from_start'];
        $payload['schedule_adjustments'][0]['service_durations'] = [['shoot_service_id' => $line->id, 'duration_minutes' => 45]];
        $this->postJson('/api/photographer/availability/feasibility', $payload)->assertOk()->assertJsonPath('data.available', true);
        $this->assertSame(135, $line->fresh()->duration_minutes, 'Preview must be read only.');
        $savedId = $this->postJson('/api/shoots', $payload)->assertCreated()->json('data.id');
        $this->assertSame(150, Shoot::findOrFail($savedId)->serviceItems()->sole()->duration_minutes);
        $this->assertSame(45, $line->fresh()->duration_minutes);
        $this->assertSame('10:30', substr($neighbor->fresh()->time, 0, 5));
        $this->assertSame(100.0, (float) $line->fresh()->price);
        $this->assertSame(135, $this->service->fresh()->shoot_duration_minutes);
        Queue::assertPushed(ProcessUpdatedShootSideEffectsJob::class, fn ($job) => str_contains($job->changesSummary, '135 min to 45 min'));
        Queue::assertPushed(ProcessUpdatedShootSideEffectsJob::class, fn ($job) => $job->shootId === $neighbor->id && $job->notifyClient === false && $job->notifyPhotographer === true);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_duration_increase_cannot_create_overlap_or_leave_partial_changes(string $role): void
    {
        $this->admin->update(['role' => $role]);
        $neighbor = $this->booked(); $payload = $this->payload($neighbor);
        $line = $neighbor->serviceItems()->sole();
        $payload['schedule_adjustments'][0]['scheduled_at'] = $payload['schedule_adjustments'][0]['from_start'];
        $payload['schedule_adjustments'][0]['service_durations'] = [['shoot_service_id' => $line->id, 'duration_minutes' => 150]];
        $this->postJson('/api/shoots', $payload)->assertUnprocessable();
        $this->assertSame(135, $line->fresh()->duration_minutes);
        $this->assertSame(1, Shoot::count()); Queue::assertNothingPushed();
    }

    public function test_invalid_duration_and_foreign_capture_line_are_rejected(): void
    {
        $neighbor = $this->booked(); $other = $this->booked('2026-10-08');
        $payload = $this->payload($neighbor); $line = $neighbor->serviceItems()->sole();
        foreach ([0, 4, 301, 22.5] as $minutes) {
            $payload['schedule_adjustments'][0]['service_durations'] = [['shoot_service_id' => $line->id, 'duration_minutes' => $minutes]];
            $this->postJson('/api/shoots', $payload)->assertUnprocessable();
        }
        $payload['schedule_adjustments'][0]['service_durations'] = [['shoot_service_id' => $other->serviceItems()->sole()->id, 'duration_minutes' => 30]];
        $this->postJson('/api/shoots', $payload)->assertUnprocessable();
        $this->assertSame(135, $line->fresh()->duration_minutes); Queue::assertNothingPushed();
    }

    public function test_multiple_visits_of_one_multi_unit_neighbor_keep_unselected_lines_unchanged(): void
    {
        $neighbor = $this->booked(); $first = $neighbor->serviceItems()->sole();
        $unit = $neighbor->units()->create(['client_key' => 'one', 'label' => 'One', 'kind' => 'unit', 'sort_order' => 0]);
        $first->update(['shoot_unit_id' => $unit->id]);
        $neighbor->forceFill(['units_revision' => 3])->save();
        $extra = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 60]);
        $neighbor->services()->attach($extra->id, ['shoot_unit_id' => $unit->id, 'duration_minutes' => 60, 'price' => 200,
            'photographer_id' => $this->photographer->id, 'scheduled_at' => '2026-10-09 19:30:00', 'workflow_status' => 'scheduled']);
        $second = $neighbor->serviceItems()->where('service_id', $extra->id)->sole();
        $payload = $this->payload($neighbor, '20:00');
        $payload['schedule_adjustments'][0]['scheduled_at'] = '2026-10-09T17:00:00-04:00';
        $payload['schedule_adjustments'][0]['service_durations'] = [['shoot_service_id' => $first->id, 'duration_minutes' => 90]];
        $payload['schedule_adjustments'][] = ['shoot_id' => $neighbor->id, 'photographer_id' => $this->photographer->id,
            'from_start' => '2026-10-09T15:30:00-04:00', 'scheduled_at' => '2026-10-09T15:30:00-04:00',
            'expected_edit_version' => app(ShootManagementAccess::class)->editVersion($neighbor->fresh()),
            'service_durations' => [['shoot_service_id' => $second->id, 'duration_minutes' => 30]]];
        $this->postJson('/api/photographer/availability/feasibility', $payload)->assertOk()->assertJsonPath('data.available', true)->assertJsonCount(3, 'data.visits');
        $this->postJson('/api/shoots', $payload)->assertCreated();
        $this->assertSame(90, $first->fresh()->duration_minutes);
        $this->assertSame(30, $second->fresh()->duration_minutes);
        $this->assertSame('2026-10-09 19:30:00', $second->fresh()->getRawOriginal('scheduled_at'));
        $this->assertSame(200.0, (float) $second->fresh()->price);
        $this->assertSame(4, $neighbor->fresh()->units_revision);
        $this->assertSame('15:30', substr($neighbor->fresh()->time, 0, 5), 'The earliest remaining visit owns the shoot summary.');
        Queue::assertPushed(ProcessUpdatedShootSideEffectsJob::class, 1);
    }

    public function test_stale_duration_confirmation_and_duplicate_visit_are_rejected(): void
    {
        $neighbor = $this->booked(); $payload = $this->payload($neighbor, '11:30');
        $preview = $this->postJson('/api/photographer/availability/feasibility', $payload)->assertOk()->json('data');
        $payload += ['travel_override' => true, 'travel_override_confirmed' => true, 'travel_override_reason' => 'Approved shorter travel gap.',
            'travel_override_confirmation_version' => $preview['confirmation_version']];
        $line = $neighbor->serviceItems()->sole();
        $payload['schedule_adjustments'][0]['service_durations'] = [['shoot_service_id' => $line->id, 'duration_minutes' => 140]];
        $this->postJson('/api/shoots', $payload)->assertUnprocessable();
        $payload['schedule_adjustments'][] = $payload['schedule_adjustments'][0];
        $this->postJson('/api/shoots', $payload)->assertUnprocessable();
        $this->assertSame(135, $line->fresh()->duration_minutes); Queue::assertNothingPushed();
    }

    public function test_failed_target_write_rolls_back_neighbor_duration(): void
    {
        $neighbor = $this->booked(); $payload = $this->payload($neighbor);
        $line = $neighbor->serviceItems()->sole();
        $payload['schedule_adjustments'][0]['service_durations'] = [['shoot_service_id' => $line->id, 'duration_minutes' => 90]];
        $guard = app(ScheduleCommitGuard::class); $prepared = $guard->prepare($payload, null, $this->admin);
        try {
            $guard->commit($prepared, fn () => throw new \RuntimeException('Target write failed'));
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) { $this->assertSame('Target write failed', $e->getMessage()); }
        $this->assertSame(135, $line->fresh()->duration_minutes);
        $this->assertSame('10:30', substr($neighbor->fresh()->time, 0, 5)); Queue::assertNothingPushed();
    }

    public function test_multi_unit_approval_and_neighbor_move_share_the_final_schedule_for_admin_and_rep(): void
    {
        foreach (['admin', 'salesRep'] as $role) {
            $actor = $role === 'admin' ? $this->admin : User::factory()->create(['role' => 'salesRep', 'email_verification_required_at' => null]);
            Sanctum::actingAs($actor);
            $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
            $this->photographer = $photographer;
            $neighbor = $this->booked();
            $target = $this->booked();
            $target->update(['status' => 'requested', 'workflow_status' => 'requested', 'rep_id' => $actor->id]);
            $target->forceFill(['units_revision' => 1])->save();
            $unit = $target->units()->create(['client_key' => 'unit-'.$target->id, 'label' => '1', 'kind' => 'unit', 'sqft' => 4512, 'sort_order' => 0]);
            $line = $target->serviceItems()->sole();
            $line->update(['shoot_unit_id' => $unit->id, 'client_key' => 'photos-'.$target->id, 'duration_minutes' => 75]);
            $floorplan = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 5]);
            $target->services()->attach($floorplan->id, ['shoot_unit_id' => $unit->id, 'client_key' => 'floorplan-'.$target->id,
                'duration_minutes' => 5, 'quantity' => 1, 'price' => 175, 'photographer_id' => $photographer->id,
                'scheduled_at' => '2026-10-09 15:45:00', 'workflow_status' => 'scheduled']);
            $payload = ['shoot_id' => $target->id, 'action_mode' => 'approve', 'expected_units_revision' => 1,
                'timezone' => 'America/New_York', 'scheduled_at' => '2026-10-09T14:30:00Z',
                'notify_client' => false, 'notify_photographer' => false,
                'service_lines' => $target->serviceItems()->get()->map(fn ($row) => ['shoot_service_id' => $row->id,
                    'client_key' => $row->client_key, 'shoot_unit_id' => $row->shoot_unit_id, 'service_id' => $row->service_id,
                    'quantity' => $row->quantity, 'duration_minutes' => $row->duration_minutes,
                    'scheduled_at' => $row->scheduled_at->toIso8601String(), 'photographer_id' => $row->photographer_id])->all(),
                'schedule_adjustments' => [['shoot_id' => $neighbor->id, 'photographer_id' => $photographer->id,
                    'from_start' => '2026-10-09T14:30:00Z', 'scheduled_at' => '2026-10-09T11:00:00Z',
                    'expected_edit_version' => app(ShootManagementAccess::class)->editVersion($neighbor->fresh())]]];
            $invalid = $payload;
            $invalid['schedule_adjustments'][0]['scheduled_at'] = '2026-10-09T14:00:00Z';
            $this->postJson('/api/shoots/'.$target->id.'/approve', $invalid)->assertUnprocessable();
            $this->assertSame('requested', $target->fresh()->status);
            $this->assertSame('10:30', substr($neighbor->fresh()->time, 0, 5));
            $this->postJson('/api/photographer/availability/feasibility', $payload)->assertOk()->assertJsonPath('data.available', true);
            $this->postJson('/api/shoots/'.$target->id.'/approve', $payload)->assertOk();
            $this->assertSame('scheduled', $target->fresh()->status, $role);
            $this->assertSame('07:00', substr($neighbor->fresh()->time, 0, 5), $role);
            $this->assertSame(['2026-10-09 14:30:00', '2026-10-09 15:45:00'], $target->serviceItems()->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal('scheduled_at'))->all());
            $this->assertSame([75, 5], $target->serviceItems()->orderBy('id')->pluck('duration_minutes')->all());
            $this->assertSame([100.0, 175.0], $target->serviceItems()->orderBy('id')->pluck('price')->map(fn ($price) => (float) $price)->all());
            Queue::assertPushed(ProcessUpdatedShootSideEffectsJob::class, fn ($job) => $job->shootId === $neighbor->id && $job->notifyClient === false && $job->notifyPhotographer === false);
        }
    }
}
