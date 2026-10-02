<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Scheduling\GoogleRoutesProvider;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\SchedulingBufferSettings;
use App\Services\Scheduling\TravelTimeEstimator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SchedulingBufferSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/scheduling/buffer-settings';

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function input(array $changes = []): array
    {
        $policy = app(SchedulingBufferSettings::class);

        return array_replace($policy->current(true), ['version' => $policy->version()], $changes);
    }

    private function save(array $changes): void
    {
        $this->putJson(self::URL, $this->input($changes))->assertOk();
    }

    public function test_defaults_preserve_deployment_policy_and_never_expose_credentials_or_spend_routes(): void
    {
        $this->admin();
        config(['availability.hybrid_travel_enabled' => true, 'services.google_routes.key' => 'private-test-key']);
        $response = $this->getJson(self::URL)->assertOk()->assertJsonPath('data.settings.mode', 'google')
            ->assertJsonPath('data.google.key_configured', true)->assertJsonPath('data.google.budget.budget_usd', 100);
        $this->assertStringNotContainsString('private-test-key', $response->getContent());
        $this->assertDatabaseMissing('settings', ['key' => SchedulingBufferSettings::KEY]);
        $this->assertDatabaseCount('scheduling_route_usage', 0);
        config(['availability.hybrid_travel_enabled' => false]);
        $this->getJson(self::URL)->assertJsonPath('data.settings.mode', 'fixed');
        $this->assertFalse(app(SchedulingBufferSettings::class)->enabled());
    }

    public function test_only_admins_can_read_or_change_policy_and_generic_settings_cannot_bypass_validation(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        foreach (['client', 'salesRep', 'photographer', 'editing_manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson(self::URL)->assertForbidden();
            $this->putJson(self::URL, $this->input())->assertForbidden();
        }
        $this->admin();
        $this->postJson('/api/admin/settings', ['key' => SchedulingBufferSettings::KEY, 'type' => 'json', 'value' => ['mode' => 'bad']])->assertForbidden();
    }

    public function test_validation_stale_saves_and_audit_with_appointments_untouched(): void
    {
        $this->admin();
        $shoot = Shoot::factory()->create();
        $before = $shoot->fresh()->getRawOriginal();
        $this->putJson(self::URL, $this->input(['near_minutes' => 22, 'mode' => 'invalid']))->assertUnprocessable()
            ->assertJsonValidationErrors(['mode', 'near_minutes']);
        $this->putJson(self::URL, $this->input(['near_minutes' => 60, 'medium_minutes' => 30]))->assertUnprocessable();
        $stale = $this->input(['mode' => 'mileage']);
        $this->save(['mode' => 'fixed', 'fixed_minutes' => 30]);
        $this->putJson(self::URL, $stale)->assertConflict();
        $this->assertTrue(app(SchedulingBufferSettings::class)->enabled());
        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        $this->assertDatabaseHas('user_activity_logs', ['event_type' => 'scheduling.buffer_settings_updated']);
    }

    public function test_fixed_and_mileage_modes_skip_google_and_preserve_verified_buildings(): void
    {
        $this->admin();
        $provider = Mockery::mock(GoogleRoutesProvider::class);
        $provider->shouldNotReceive('route');
        $estimator = new TravelTimeEstimator($provider);
        $a = $this->location('a', 39);
        $b = $this->location('b', 39.1);
        $this->save(['mode' => 'fixed', 'fixed_minutes' => 35]);
        $this->assertSame(35, $estimator->estimate([], [], now())['required_minutes']);
        $this->assertSame(0, $estimator->estimate($a, $a, now())['required_minutes']);
        $this->save(['mode' => 'mileage', 'near_minutes' => 20, 'medium_minutes' => 40, 'far_minutes' => 60]);
        $this->assertSame(40, $estimator->estimate($a, $b, now())['required_minutes']);
        $this->assertTrue($estimator->estimate([], $b, now())['review_required']);
        $this->assertTrue($estimator->estimate($a, $this->location('far', 40), now())['review_required']);
        $this->assertDatabaseCount('scheduling_route_usage', 0);
    }

    public function test_google_allowance_minimum_and_review_fallback_are_applied(): void
    {
        $this->admin();
        $this->save(['mode' => 'google', 'minimum_minutes' => 20, 'allowance_minutes' => 10, 'fallback' => 'review']);
        $provider = Mockery::mock(GoogleRoutesProvider::class);
        $provider->shouldReceive('route')->andReturn(
            ['status' => 'ok', 'duration_seconds' => 1081, 'distance_meters' => 1000],
            ['status' => 'ok', 'duration_seconds' => 60, 'distance_meters' => 100],
            ['status' => 'unavailable', 'reason_code' => 'routes_unavailable'],
            ['status' => 'no_route']);
        $estimator = new TravelTimeEstimator($provider);
        $a = $this->location('a', 39);
        $b = $this->location('b', 39.01);
        $this->assertSame(30, $estimator->estimate($a, $b, now())['required_minutes']);
        $this->assertSame(20, $estimator->estimate($a, $b, now())['required_minutes']);
        $this->assertTrue($estimator->estimate($a, $b, now())['review_required']);
        $this->assertSame('route_not_found', $estimator->estimate($a, $b, now())['reason_code']);
    }

    public function test_saved_policy_changes_real_feasibility_and_invalidates_schedule_fingerprint(): void
    {
        $this->admin();
        config(['app.timezone' => 'UTC', 'availability.fallback_start_time' => '00:00', 'availability.fallback_end_time' => '23:59']);
        $photographer = User::factory()->photographer()->create(['timezone' => 'UTC']);
        $service = Service::factory()->create(['shoot_duration_minutes' => 30]);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id,
            'scheduled_at' => Carbon::parse('2026-11-02 08:00:00', 'UTC'), 'timezone' => 'UTC',
            'scheduled_date' => '2026-11-02', 'time' => '08:00', 'status' => Shoot::STATUS_SCHEDULED]);
        $shoot->services()->attach($service->id, ['duration_minutes' => 30, 'scheduled_at' => $shoot->scheduled_at,
            'photographer_id' => $photographer->id, 'workflow_status' => 'scheduled']);
        $this->save(['mode' => 'fixed', 'fixed_minutes' => 15]);
        $payload = ['action_mode' => 'create', 'photographer_id' => $photographer->id, 'timezone' => 'UTC',
            'scheduled_at' => '2026-11-02T08:45:00Z', 'services' => [['id' => $service->id, 'duration_minutes' => 30]]];
        $engine = app(ScheduleFeasibilityService::class);
        $first = $engine->evaluate($payload, null, auth()->user());
        $this->assertSame('available', $first['status']);
        $this->save(['mode' => 'fixed', 'fixed_minutes' => 30]);
        $second = $engine->evaluate($payload, null, auth()->user());
        $this->assertNotSame($first['schedule_version'], $second['schedule_version']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame(30, $second['transitions'][0]['required_minutes']);
        $this->assertSame(15.0, (float) $second['transitions'][0]['available_minutes']);
    }

    private function location(string $key, float $latitude): array
    {
        return ['verified' => true, 'building_key' => $key, 'full_address' => $key, 'complete' => true,
            'latitude' => $latitude, 'longitude' => -77, 'precision' => 'exact'];
    }
}
