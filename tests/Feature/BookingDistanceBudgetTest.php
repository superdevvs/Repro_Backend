<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AddressLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class BookingDistanceBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.places_api_key' => 'test-key', 'services.nominatim.throttle_cache_store' => 'array',
            'services.nominatim.min_interval_milliseconds' => 0, 'availability.radius_enforcement' => false]);
        Http::preventStrayRequests();
        Cache::flush();
    }

    private function photographers(int $count = 2): array
    {
        return User::factory()->count($count)->photographer()->create([
            'address' => '15 Photographer Road', 'city' => 'Rockville', 'state' => 'MD', 'zip' => '20850',
            'metadata' => ['service_radius_miles' => 20],
        ])->pluck('id')->all();
    }

    private function payload(array $ids): array
    {
        return ['photographer_ids' => $ids, 'date' => '2026-10-02', 'time' => '13:30', 'duration_minutes' => 15,
            'shoot_address' => '100 Candidate Street', 'shoot_city' => 'Baltimore', 'shoot_state' => 'MD', 'shoot_zip' => '21201'];
    }

    public function test_optional_distance_does_not_call_disabled_providers_or_hide_eligible_photographers(): void
    {
        $ids = $this->photographers(3);
        Http::fake(['*' => Http::response(['status' => 'REQUEST_DENIED'], 403)]);
        $rows = $this->postJson('/api/photographer/availability/for-booking', $this->payload($ids))
            ->assertOk()->assertJsonCount(3, 'data')->json('data');
        foreach ($rows as $row) {
            $this->assertNull($row['distance']);
            $this->assertTrue($row['is_available_at_time']);
        }
        // Map pins may geocode addresses independently of optional road distances.
        // With radius enforcement off, no distance provider may be called.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'distancematrix')
            || str_contains($request->url(), 'directions'));
    }

    public function test_required_radius_distance_still_fails_closed_on_provider_failure(): void
    {
        config(['availability.radius_enforcement' => true]);
        $ids = $this->photographers();
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED'], 403),
            'nominatim.openstreetmap.org/*' => Http::response([], 503)]);
        $this->postJson('/api/photographer/availability/for-booking', $this->payload($ids))
            ->assertOk()->assertJsonCount(0, 'data');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'distancematrix'));
    }

    public function test_radius_lookups_share_one_absolute_deadline_across_photographers(): void
    {
        config(['availability.radius_enforcement' => true]);
        $ids = $this->photographers(3);
        $policies = [];
        $before = microtime(true);
        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->times(3)->andReturnUsing(function ($origin, $destination, $policy) use (&$policies) {
            $policies[] = $policy;

            return null;
        });
        $this->app->instance(AddressLookupService::class, $distance);
        $this->postJson('/api/photographer/availability/for-booking', $this->payload($ids))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertTrue($policies[0]['network_allowed']);
        $this->assertSame($policies[0], $policies[1]);
        $this->assertSame($policies[0], $policies[2]);
        $this->assertGreaterThanOrEqual($before + 3, $policies[0]['deadline']);
        $this->assertLessThanOrEqual(microtime(true) + 3, $policies[0]['deadline']);
    }

    public function test_display_coordinates_are_fast_and_do_not_pollute_the_required_distance_cache(): void
    {
        Http::fake();
        $origin = ['latitude' => 0, 'longitude' => 0];
        $destination = ['latitude' => 0, 'longitude' => 0];
        $distance = app(AddressLookupService::class)->getDistance($origin, $destination, ['network_allowed' => false]);
        $this->assertSame(0, $distance['distance_value']);
        $this->assertFalse(Cache::has('distance_v2_'.md5(json_encode($origin).json_encode($destination))));
        Http::assertNothingSent();
    }

    public function test_each_provider_request_is_limited_to_remaining_budget_and_expired_budget_sends_nothing(): void
    {
        $seen = [];
        Http::fake(function ($request, $options) use (&$seen) {
            $seen[] = $options;

            return str_contains($request->url(), 'distancematrix')
                ? Http::response(['status' => 'REQUEST_DENIED'], 403)
                : Http::response([], 503);
        });
        $service = app(AddressLookupService::class);
        $origin = ['address' => '20 Origin Road'];
        $destination = ['address' => '30 Destination Road'];
        $this->assertNull($service->getDistance($origin, $destination, ['network_allowed' => true, 'deadline' => microtime(true) + 0.5]));
        $this->assertNotEmpty($seen);
        foreach ($seen as $options) {
            $this->assertLessThanOrEqual(0.5, $options['timeout']);
            $this->assertLessThanOrEqual(0.5, $options['connect_timeout']);
        }
        $count = count($seen);
        $this->assertNull($service->getDistance($origin, $destination, ['network_allowed' => true, 'deadline' => microtime(true) - 1]));
        $this->assertCount($count, $seen);
    }
}
