<?php

namespace Tests\Feature;

use App\Http\Resources\ShootResource;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\VisitPlanBuilder;
use App\Services\Scheduling\WriteSchedulePlan;
use App\Services\Shoots\ShootDurationResolver;
use App\Services\Shoots\ShootPublicAssetsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HybridSchedulingPreviewParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unchanged_legacy_local_clock_preview_matches_persisted_visit_instant(): void
    {
        config(['app.timezone' => 'UTC']);
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $service = Service::factory()->create(['shoot_duration_minutes' => 15]);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id, 'timezone' => null,
            'scheduled_at' => '2026-10-02 09:00:00', 'status' => 'scheduled']);
        $shoot->services()->attach($service->id, ['duration_minutes' => 15, 'scheduled_at' => '2026-10-02 09:00:00',
            'photographer_id' => $photographer->id, 'workflow_status' => 'scheduled']);
        $booked = app(ShootDurationResolver::class)->windowsForShoot($shoot);
        $preview = app(VisitPlanBuilder::class)->build(['action_mode' => 'update'], $shoot);
        $this->assertSame('2026-10-02T13:00:00+00:00', $booked[0]['start']->copy()->utc()->toIso8601String());
        $this->assertSame($booked[0]['start']->copy()->utc()->toIso8601String(), $preview[0]['start']);
    }

    public function test_new_unzoned_preview_matches_normal_write_plan_photographer_local_clock(): void
    {
        config(['app.timezone' => 'UTC']);
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $service = Service::factory()->create(['shoot_duration_minutes' => 15]);
        $payload = ['photographer_id' => $photographer->id, 'scheduled_at' => '2026-10-02 09:00:00',
            'services' => [['id' => $service->id, 'duration_minutes' => 15]], 'action_mode' => 'create'];
        $write = app(WriteSchedulePlan::class)->services($payload, $payload['services'],
            Carbon::parse($payload['scheduled_at'], 'UTC'), $photographer->id, null, 'create');
        $builder = app(VisitPlanBuilder::class);
        $committed = $builder->build($write);
        $preview = $builder->build($payload);
        $this->assertSame('2026-10-02T13:00:00+00:00', $committed[0]['start']);
        $this->assertSame($committed[0]['start'], $preview[0]['start']);
    }

    public function test_unzoned_alternatives_preserve_photographer_local_working_hours(): void
    {
        config(['app.timezone' => 'UTC', 'availability.hybrid_travel_enabled' => true,
            'availability.fallback_start_time' => '09:00', 'availability.fallback_end_time' => '18:00']);
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create(['shoot_duration_minutes' => 15]);
        $result = app(ScheduleFeasibilityService::class)->evaluate([
            'photographer_id' => $photographer->id, 'scheduled_at' => '2026-10-02 17:00:00',
            'services' => [['id' => $service->id, 'duration_minutes' => 15]],
        ], null, $admin, true);
        $this->assertTrue($result['available']);
        $this->assertNotEmpty($result['alternatives']);
        $this->assertSame('America/New_York', $result['alternatives'][0]['shifted_visits'][0]['timezone']);
    }

    public function test_shoot_resource_omits_internal_signed_location_but_preserves_property_details(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $metadata = ['verified' => true, 'building_key' => 'private-building', 'signature' => 'private-signature'];
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'property_details' => [
            'sqft' => 1200, 'bedrooms' => 2, 'source' => 'property_lookup', 'schedule_location' => $metadata,
        ]]);
        $request = Request::create('/api/shoots/'.$shoot->id);
        $request->setUserResolver(fn () => $client);
        $payload = (new ShootResource($shoot->fresh()))->resolve($request);
        $this->assertSame(['sqft' => 1200, 'bedrooms' => 2, 'source' => 'property_lookup'], $payload['property_details']);
        Sanctum::actingAs($client);
        $shown = $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->json('data');
        $this->assertSame($payload['property_details'], $shown['property_details']);
        $tour = app(ShootPublicAssetsService::class)->buildPublicTourPropertyDetails($shoot->fresh());
        $this->assertArrayNotHasKey('schedule_location', $tour);
        $this->assertSame(1200, $tour['sqft']);
        $fresh = $shoot->fresh();
        $this->assertArrayNotHasKey('schedule_location', $fresh->toArray()['property_details']);
        $this->assertArrayNotHasKey('schedule_location', json_decode($fresh->toJson(), true)['property_details']);
        $this->assertSame($metadata, $fresh->property_details['schedule_location']);
        $this->assertSame($metadata, $shoot->fresh()->property_details['schedule_location']);
    }
}
