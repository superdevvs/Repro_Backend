<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use App\Services\Scheduling\TravelLocationResolver;
use App\Services\Scheduling\TravelTimeEstimator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class TravelOverrideActionPreviewParityTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    public function test_existing_booking_action_previews_match_the_actual_write_warning(): void
    {
        config(['availability.hybrid_travel_enabled' => true, 'availability.scheduling_lock_store' => 'array']);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $availability = Mockery::mock(PhotographerAvailabilityService::class)->makePartial();
        $availability->shouldReceive('assertWithinAvailabilityBounds')->andReturnNull();
        $this->app->instance(PhotographerAvailabilityService::class, $availability);
        $location = Mockery::mock(TravelLocationResolver::class);
        $location->shouldReceive('reset')->andReturnNull();
        $location->shouldReceive('persistedMetadata')->andReturn([]);
        $location->shouldReceive('forPayload')->andReturnUsing(fn ($payload, $shoot = null) => [
            'verified' => true, 'address_hash' => $payload['address'] ?? $shoot?->address,
            'building_key' => $payload['address'] ?? $shoot?->address,
        ]);
        $location->shouldReceive('forShoot')->andReturnUsing(fn ($shoot) => ['verified' => true,
            'address_hash' => $shoot->address, 'building_key' => $shoot->address]);
        $this->app->instance(TravelLocationResolver::class, $location);
        $travel = Mockery::mock(TravelTimeEstimator::class);
        $travel->shouldReceive('reset')->andReturnNull();
        $travel->shouldReceive('estimate')->andReturn(['source' => 'google_routes', 'required_minutes' => 25,
            'review_required' => false, 'reason_code' => 'traffic_aware_route', 'drive_minutes' => 18,
            'distance_miles' => 8, 'attribution' => 'Google Maps']);
        $this->app->instance(TravelTimeEstimator::class, $travel);
        $admin = User::factory()->admin()->create(['email_verification_required_at' => null]);
        Sanctum::actingAs($admin);
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $service = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 15]);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id, 'status' => 'scheduled',
            'workflow_status' => 'scheduled', 'scheduled_at' => '2026-10-02 14:00:00',
            'scheduled_date' => '2026-10-02', 'time' => '10:00', 'timezone' => 'America/New_York',
            'alternate_scheduled_date' => '2026-10-02', 'alternate_time' => '09:00',
            'alternate_scheduled_at' => '2026-10-02 13:00:00']);
        $shoot->services()->attach($service->id, ['duration_minutes' => 15, 'quantity' => 1, 'price' => 100,
            'photographer_id' => $photographer->id, 'scheduled_at' => '2026-10-02 14:00:00', 'workflow_status' => 'scheduled']);
        $neighbor = Shoot::factory()->create(['photographer_id' => $photographer->id, 'status' => 'scheduled',
            'workflow_status' => 'scheduled', 'scheduled_at' => '2026-10-02 12:30:00', 'timezone' => 'America/New_York']);
        $neighbor->services()->attach($service->id, ['duration_minutes' => 15, 'photographer_id' => $photographer->id,
            'scheduled_at' => '2026-10-02 12:30:00', 'workflow_status' => 'scheduled']);
        $pending = ShootRescheduleRequest::create(['shoot_id' => $shoot->id, 'requested_by' => $shoot->client_id,
            'requested_date' => '2026-10-02', 'requested_time' => '09:00', 'status' => 'pending']);
        $at = ['scheduled_at' => '2026-10-02T09:00:00-04:00'];
        $confirmation = ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_reason' => 'Staff reviewed the available travel time'];
        $cases = [
            ['update', 'patch', "/api/shoots/{$shoot->id}", ['services' => [['id' => $service->id, 'duration_minutes' => 15] + $at]]],
            ['approve', 'post', "/api/shoots/{$shoot->id}/approve", $at],
            ['approve', 'post', "/api/shoots/{$shoot->id}/approve", ['scheduled_at' => '2026-10-02T09:00:00', 'timezone' => 'America/New_York']],
            ['schedule', 'post', "/api/shoots/{$shoot->id}/schedule", $at],
            ['assign', 'post', "/api/shoots/{$shoot->id}/assign-service-photographer", ['service_id' => $service->id, 'photographer_id' => $photographer->id]],
            ['reschedule', 'post', "/api/shoots/{$shoot->id}/reschedule", ['requested_date' => '2026-10-02', 'requested_time' => '09:00']],
            ['alternate', 'post', "/api/shoots/{$shoot->id}/apply-alternate-date", ['scope' => 'all_services']],
            ['reschedule', 'patch', "/api/shoots/reschedule-requests/{$pending->id}", ['status' => 'approved']],
        ];
        $failures = [];
        foreach ($cases as [$mode, $method, $url, $payload]) {
            $shoot->refresh();
            $status = $mode === 'approve' ? 'requested' : ($mode === 'schedule' ? 'on_hold' : 'scheduled');
            $storedAt = $mode === 'assign' ? '2026-10-02 13:00:00' : '2026-10-02 14:00:00';
            $shoot->forceFill(['status' => $status, 'workflow_status' => $status, 'scheduled_at' => $storedAt])->saveQuietly();
            $shoot->serviceItems()->update(['scheduled_at' => $storedAt]);
            $preview = $this->postJson('/api/photographer/availability/feasibility', [
                'scheduled_at' => $payload['scheduled_at'] ?? $at['scheduled_at'],
                'shoot_id' => $shoot->id, 'action_mode' => $mode, 'timezone' => 'America/New_York',
            ])->assertOk()->json('data');
            $response = $this->{$method.'Json'}($url, $payload + $confirmation);
            if ($response->status() !== 422) {
                $failures[] = ['mode' => $mode, 'status' => $response->status(),
                    'saved_at' => $shoot->fresh()->scheduled_at->toIso8601String(), 'errors' => $response->json('errors')];

                continue;
            }
            $fresh = $response->assertUnprocessable()
                ->assertJsonValidationErrors('travel_override_confirmation_version')->json('feasibility');
            $this->assertNotEmpty($preview['confirmation_version'], $mode);
            $this->assertSame($preview['visits'], $fresh['visits'], $mode.' visits');
            $this->assertSame($preview['confirmation_version'], $fresh['confirmation_version'], $mode.' warning');
            $this->assertSame($storedAt, $shoot->fresh()->scheduled_at->format('Y-m-d H:i:s'), $mode.' must not write');
        }
        $this->assertSame([], $failures, 'Every write path must check the exact warning shown in its preview.');
    }
}
