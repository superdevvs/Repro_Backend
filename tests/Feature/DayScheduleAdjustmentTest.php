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

    public function test_update_uses_same_batch_and_preview_confirmation_for_short_gap(): void
    {
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

    public function test_day_endpoint_filters_date_and_hides_other_client_booking_details(): void
    {
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
}
