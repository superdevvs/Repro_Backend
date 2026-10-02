<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\TravelLocationResolver;
use App\Services\Scheduling\TravelTimeEstimator;
use App\Services\Shoots\ShootAuthorizationSupport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class HybridTravelNeighborContextTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private Service $capture;

    private array $estimate;

    protected function setUp(): void
    {
        parent::setUp();
        config(['availability.hybrid_travel_enabled' => true, 'app.timezone' => 'UTC',
            'availability.fallback_start_time' => '00:00', 'availability.fallback_end_time' => '23:59']);
        $this->photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $this->capture = Service::factory()->create(['name' => '15 Exterior Photos', 'shoot_duration_minutes' => 15]);
        $locations = Mockery::mock(TravelLocationResolver::class);
        $locations->shouldReceive('reset')->andReturnNull();
        $locations->shouldReceive('forPayload', 'forShoot')->andReturn(['verified' => true, 'building_key' => 'test']);
        $this->app->instance(TravelLocationResolver::class, $locations);
        $this->estimate = ['source' => 'google_routes', 'required_minutes' => 25, 'drive_minutes' => 18,
            'distance_miles' => 8, 'reason_code' => 'traffic_aware_route', 'review_required' => false, 'attribution' => 'Google Maps'];
        $estimator = Mockery::mock(TravelTimeEstimator::class);
        $estimator->shouldReceive('reset')->andReturnNull();
        $estimator->shouldReceive('estimate')->andReturnUsing(fn () => $this->estimate);
        $this->app->instance(TravelTimeEstimator::class, $estimator);
    }

    private function payload(): array
    {
        return ['action_mode' => 'create', 'photographer_id' => $this->photographer->id,
            'scheduled_at' => '2026-10-02T09:00:00-04:00', 'timezone' => 'America/New_York',
            'address' => '20 Candidate Road', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21205',
            'services' => [['id' => $this->capture->id, 'duration_minutes' => 15]]];
    }

    private function booked(string $clock, ?User $client = null): Shoot
    {
        $start = Carbon::parse('2026-10-02 '.$clock, 'America/New_York')->utc();
        $shoot = Shoot::factory()->create(['photographer_id' => $this->photographer->id,
            'client_id' => $client?->id ?? User::factory()->create(['role' => 'client'])->id,
            'scheduled_at' => $start, 'timezone' => 'America/New_York', 'status' => 'scheduled',
            'address' => 'CONFIDENTIAL NEIGHBOR ADDRESS', 'notes' => 'PRIVATE ACCESS NOTES']);
        $shoot->services()->attach($this->capture->id, ['photographer_id' => $this->photographer->id,
            'scheduled_at' => $start, 'duration_minutes' => 15, 'workflow_status' => 'scheduled']);

        return $shoot;
    }

    private function publicResult(User $actor): array
    {
        $service = app(ScheduleFeasibilityService::class);

        return $service->publicResult($service->evaluate($this->payload(), null, $actor));
    }

    public function test_admin_gets_only_the_relevant_neighbor_window_services_and_no_private_property_fields(): void
    {
        $before = $this->booked('08:30');
        $after = $this->booked('09:30');
        $unrelated = Service::factory()->create(['name' => 'Separate Earlier Video', 'shoot_duration_minutes' => 15]);
        $before->services()->attach($unrelated->id, ['photographer_id' => $this->photographer->id,
            'scheduled_at' => '2026-10-02 11:00:00', 'duration_minutes' => 15, 'workflow_status' => 'scheduled']);
        $result = $this->publicResult(User::factory()->admin()->create());
        $incoming = collect($result['transitions'])->firstWhere('direction', 'incoming');
        $outgoing = collect($result['transitions'])->firstWhere('direction', 'outgoing');
        $this->assertSame($before->id, $incoming['neighbor']['shoot_id']);
        $this->assertSame('2026-10-02T12:30:00+00:00', $incoming['neighbor']['scheduled_at']);
        $this->assertSame('2026-10-02T12:45:00+00:00', $incoming['neighbor']['end_at']);
        $this->assertSame([['id' => $this->capture->id, 'name' => $this->capture->name]], $incoming['neighbor']['services']);
        $this->assertSame($after->id, $outgoing['neighbor']['shoot_id']);
        $this->assertTrue($incoming['neighbor']['can_view_details']);
        $this->assertSame(['id' => $this->photographer->id, 'name' => $this->photographer->name], $incoming['neighbor']['photographer']);
        $this->assertSame(18, $incoming['drive_minutes']);
        $this->assertStringNotContainsString('CONFIDENTIAL', json_encode($result));
        $this->assertStringNotContainsString('PRIVATE ACCESS', json_encode($result));
        $this->assertStringNotContainsString('Separate Earlier Video', json_encode($result));
    }

    public function test_rep_with_existing_neighbor_access_gets_context_and_fallback_remains_honestly_labeled(): void
    {
        $neighbor = $this->booked('08:30');
        $this->estimate = ['source' => 'mileage_band', 'required_minutes' => 30, 'drive_minutes' => null,
            'distance_miles' => 8, 'reason_code' => 'routes_not_configured', 'review_required' => false, 'attribution' => null];
        $result = $this->publicResult(User::factory()->create(['role' => 'salesRep']));
        $edge = $result['transitions'][0];
        $this->assertSame($neighbor->id, $edge['neighbor']['shoot_id']);
        $this->assertSame('mileage_band', $edge['source']);
        $this->assertNull($edge['drive_minutes']);
        $this->assertNull($edge['attribution']);
    }

    public function test_legacy_neighbor_without_shoot_timezone_uses_photographer_local_clock(): void
    {
        $neighbor = $this->booked('08:30');
        $neighbor->update(['timezone' => null, 'scheduled_at' => '2026-10-02 08:30:00']);
        $neighbor->serviceItems()->update(['scheduled_at' => '2026-10-02 08:30:00']);
        $result = $this->publicResult(User::factory()->admin()->create());
        $context = $result['transitions'][0]['neighbor'];
        $this->assertSame('2026-10-02T12:30:00+00:00', $context['scheduled_at']);
        $this->assertSame('2026-10-02T12:45:00+00:00', $context['end_at']);
        $this->assertSame('America/New_York', $context['timezone']);
    }

    public function test_client_does_not_receive_neighbor_context_even_for_own_booking(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $this->booked('08:30', $client);
        Sanctum::actingAs($client);
        $response = $this->postJson('/api/photographer/availability/feasibility', $this->payload())->assertOk();
        $edge = $response->json('data.transitions.0');
        $this->assertArrayNotHasKey('neighbor', $edge);
        $this->assertArrayNotHasKey('from_visit_id', $edge);
        $this->assertStringNotContainsString('CONFIDENTIAL', $response->getContent());
        $this->assertStringNotContainsString($this->capture->name, $response->getContent());
        $this->assertStringNotContainsString($this->photographer->name, $response->getContent());
    }

    public function test_rep_without_permission_for_the_specific_neighbor_receives_no_context(): void
    {
        $neighbor = $this->booked('08:30');
        $authorization = Mockery::mock(ShootAuthorizationSupport::class)->makePartial();
        $authorization->shouldReceive('canViewShootDetails')->withArgs(fn ($shoot, $actor) => $shoot->id === $neighbor->id)->andReturnFalse();
        $this->app->instance(ShootAuthorizationSupport::class, $authorization);
        $result = $this->publicResult(User::factory()->create(['role' => 'rep']));
        $this->assertArrayNotHasKey('neighbor', $result['transitions'][0]);
    }

    public function test_hard_capture_overlap_still_has_no_travel_override_or_neighbor_details(): void
    {
        $this->booked('09:00');
        $result = $this->publicResult(User::factory()->admin()->create());
        $this->assertContains('capture_overlap', $result['reason_codes']);
        $this->assertFalse($result['can_override']);
        $this->assertSame([], $result['transitions']);
    }
}
