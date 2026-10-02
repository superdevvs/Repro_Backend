<?php

namespace Tests\Feature;

use App\Services\Scheduling\GoogleRoutesBudget;
use App\Services\Scheduling\GoogleRoutesProvider;
use App\Services\Scheduling\TravelTimeEstimator;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

class TravelTimeEstimatorTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-02 08:00:00', 'America/New_York'));
        config(['services.google_routes.key' => 'test-only-routes-key']);
        Http::preventStrayRequests();
    }

    private function location(string $address, float $latitude = 39, float $longitude = -77): array
    {
        return ['full_address' => $address.', Germantown, MD 20874, US', 'complete' => true,
            'latitude' => $latitude, 'longitude' => $longitude, 'verified' => true, 'precision' => 'exact',
            'building_key' => 'address:'.hash('sha256', $address)];
    }

    private function departure(): Carbon
    {
        return Carbon::parse('2026-10-02 09:00:00', 'America/New_York');
    }

    private function matrix(string $duration = '60s', float $distance = 100, array $overrides = []): array
    {
        return [array_replace(['originIndex' => 0, 'destinationIndex' => 0, 'status' => [],
            'condition' => 'ROUTE_EXISTS', 'duration' => $duration, 'distanceMeters' => $distance], $overrides)];
    }

    public function test_traffic_request_uses_utc_and_rounds_drive_plus_five_up_to_five_minutes(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response($this->matrix('601s', 8046.72))]);
        $result = app(TravelTimeEstimator::class)->estimate($this->location('1 First Street'), $this->location('2 Second Street'), $this->departure());
        $this->assertSame('google_routes', $result['source']);
        $this->assertSame(20, $result['required_minutes']);
        $this->assertEqualsWithDelta(601 / 60, $result['drive_minutes'], 0.0001);
        $this->assertSame(5.0, $result['distance_miles']);
        $this->assertFalse($result['review_required']);
        $this->assertSame('Google Maps', $result['attribution']);
        Http::assertSent(fn ($request) => $request['departureTime'] === '2026-10-02T13:00:00+00:00'
            && $request['routingPreference'] === 'TRAFFIC_AWARE' && $request['travelMode'] === 'DRIVE'
            && $request->hasHeader('X-Goog-FieldMask', 'originIndex,destinationIndex,status,condition,duration,distanceMeters,fallbackInfo')
            && $request['origins'][0]['waypoint']['address'] === '1 First Street, Germantown, MD 20874, US'
            && count($request['origins']) === 1 && count($request['destinations']) === 1);
        $this->assertSame(1, app(GoogleRoutesBudget::class)->status()['used_elements']);
    }

    public function test_request_scope_memoization_is_directional_departure_specific_and_resettable(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response($this->matrix())]);
        $a = $this->location('1 First Street');
        $b = $this->location('2 Second Street');
        $estimator = app(TravelTimeEstimator::class);
        $this->assertSame($estimator, app(TravelTimeEstimator::class));
        $this->assertSame(15, $estimator->estimate($a, $b, $this->departure())['required_minutes']);
        app(TravelTimeEstimator::class)->estimate($a, $b, $this->departure());
        Http::assertSentCount(1);
        $estimator->estimate($b, $a, $this->departure());
        $estimator->estimate($a, $b, $this->departure()->addMinutes(5));
        $estimator->reset();
        $estimator->estimate($a, $b, $this->departure());
        Http::assertSentCount(4);
        $this->assertSame(4, app(GoogleRoutesBudget::class)->status()['used_elements']);
    }

    public function test_verified_same_building_needs_no_provider_but_unverified_equal_keys_do_not_waive_buffer(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response($this->matrix('0s', 0))]);
        $a = $this->location('12800 Middlebrook Road');
        $this->assertSame('same_building', app(TravelTimeEstimator::class)->estimate($a, $a, $this->departure())['source']);
        Http::assertNothingSent();
        $a['verified'] = false;
        $this->assertTrue(app(TravelTimeEstimator::class)->estimate($a, $a, $this->departure())['review_required']);
        Http::assertNothingSent();
    }

    public function test_complete_unverified_address_requires_review_without_spending_provider_quota(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response($this->matrix('900s', 10000))]);
        $a = array_replace($this->location('1 First Street'), ['latitude' => null, 'longitude' => null, 'verified' => false, 'precision' => 'unknown']);
        $result = app(TravelTimeEstimator::class)->estimate($a, $this->location('2 Second Street'), $this->departure());
        $this->assertTrue($result['review_required']);
        $this->assertSame('location_unverified', $result['reason_code']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('scheduling_route_usage', 0);
    }

    public function test_staff_confirmed_complete_address_can_route_without_coordinates_but_cannot_use_mileage(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response($this->matrix('900s', 10000))]);
        $a = array_replace($this->location('1 First Street'), ['latitude' => null, 'longitude' => null, 'source' => 'staff_confirmed', 'precision' => 'unknown']);
        $this->assertSame(20, app(TravelTimeEstimator::class)->estimate($a, $this->location('2 Second Street'), $this->departure())['required_minutes']);
        config(['services.google_routes.key' => '']);
        $this->assertTrue(app(TravelTimeEstimator::class)->estimate($a, $this->location('2 Second Street'), $this->departure())['review_required']);
    }

    public function test_definitive_no_route_requires_review_even_with_exact_coordinates(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response([['originIndex' => 0, 'destinationIndex' => 0,
            'status' => ['code' => 5], 'condition' => 'ROUTE_NOT_FOUND']])]);
        $result = app(TravelTimeEstimator::class)->estimate($this->location('1 First Street'), $this->location('2 Second Street'), $this->departure());
        $this->assertNull($result['required_minutes']);
        $this->assertTrue($result['review_required']);
        $this->assertSame('route_not_found', $result['reason_code']);
    }

    public function test_array_shaped_google_error_uses_fallback_and_counts_one_nonrefundable_attempt(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response([['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']]], 403)]);
        $result = app(TravelTimeEstimator::class)->estimate($this->location('1 First Street'), $this->location('2 Second Street', 39.01), $this->departure());
        $this->assertSame('mileage_band', $result['source']);
        $this->assertSame(15, $result['required_minutes']);
        $this->assertSame('routes_http_error', $result['reason_code']);
        $this->assertNull($result['attribution']);
        $this->assertSame(1, app(GoogleRoutesBudget::class)->status()['used_elements']);
        app(TravelTimeEstimator::class)->estimate($this->location('1 First Street'), $this->location('2 Second Street', 39.01), $this->departure());
        Http::assertSentCount(1);
    }

    public function test_timeout_and_nontraffic_provider_fallback_use_mileage_without_retry(): void
    {
        Http::fake(fn () => throw new ConnectionException('test timeout'));
        $result = app(TravelTimeEstimator::class)->estimate($this->location('1 First Street'), $this->location('2 Second Street', 39.1), $this->departure());
        $this->assertSame(30, $result['required_minutes']);
        $this->assertSame('routes_unavailable', $result['reason_code']);
        $this->assertDatabaseCount('scheduling_route_usage', 1);
        app(TravelTimeEstimator::class)->reset();
        Http::swap(new Factory);
        Http::fake(['routes.googleapis.com/*' => Http::response($this->matrix(overrides: ['fallbackInfo' => ['routingMode' => 'FALLBACK_TRAFFIC_UNAWARE']]))]);
        $this->assertSame('routes_traffic_unavailable', app(TravelTimeEstimator::class)->estimate($this->location('1 First Street'), $this->location('2 Second Street', 39.1), $this->departure())['reason_code']);
        $this->assertDatabaseCount('scheduling_route_usage', 2);
    }

    public function test_matrix_indices_and_element_status_are_checked_independently_of_http_success(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::sequence()
            ->push($this->matrix(overrides: ['status' => ['code' => 14]]))
            ->push($this->matrix(overrides: ['destinationIndex' => 1]))
            ->push([])
            ->push([['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']]])
            ->push(array_merge($this->matrix(overrides: ['destinationIndex' => 1, 'status' => ['code' => 14]]), $this->matrix('600s', 5000)))
            ->push([['condition' => 'ROUTE_EXISTS', 'status' => [], 'duration' => '600s', 'distanceMeters' => 5000]])]);
        $a = $this->location('1 First Street');
        $b = $this->location('2 Second Street', 39.1);
        foreach (['routes_element_error', 'routes_missing_element', 'routes_missing_element', 'routes_missing_element'] as $reason) {
            app(TravelTimeEstimator::class)->reset();
            $result = app(TravelTimeEstimator::class)->estimate($a, $b, $this->departure());
            $this->assertSame('mileage_band', $result['source']);
            $this->assertSame($reason, $result['reason_code']);
        }
        foreach ([1, 2] as $_) {
            app(TravelTimeEstimator::class)->reset();
            $this->assertSame('google_routes', app(TravelTimeEstimator::class)->estimate($a, $b, $this->departure())['source']);
        }
        Http::assertSentCount(6);
    }

    public function test_mileage_bands_compare_unrounded_road_adjusted_distance_and_long_trips_require_review(): void
    {
        config(['services.google_routes.key' => '']);
        Http::fake();
        foreach ([[4.999, 15], [5.001, 30], [14.999, 30], [15.001, 45], [29.999, 45], [30.001, null]] as [$miles, $required]) {
            $latitude = rad2deg($miles / (3958.7613 * 1.3));
            $result = app(TravelTimeEstimator::class)->estimate($this->location('1 First Street', 0, 0), $this->location('2 Second Street', $latitude, 0), $this->departure());
            $this->assertSame($required, $result['required_minutes'], 'Band for '.$miles.' miles');
            $this->assertSame($required === null, $result['review_required']);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('scheduling_route_usage', 0);
    }

    public function test_untyped_coordinates_never_supply_mileage_fallback_and_incomplete_address_never_calls_provider(): void
    {
        config(['services.google_routes.key' => '']);
        Http::fake();
        $a = array_replace($this->location('1 First Street'), ['precision' => 'unknown', 'verified' => false]);
        $this->assertTrue(app(TravelTimeEstimator::class)->estimate($a, $this->location('2 Second Street'), $this->departure())['review_required']);
        $a['complete'] = false;
        $this->assertSame('location_incomplete', app(TravelTimeEstimator::class)->estimate($a, $this->location('2 Second Street'), $this->departure())['reason_code']);
        Http::assertNothingSent();
    }

    public function test_past_departure_open_transaction_and_exhausted_budget_never_issue_routes_call(): void
    {
        Http::fake();
        $a = $this->location('1 First Street');
        $b = $this->location('2 Second Street');
        $this->assertSame('past_departure', app(TravelTimeEstimator::class)->estimate($a, $b, now()->subMinute())['reason_code']);
        DB::transaction(fn () => $this->assertSame('route_inside_transaction', app(GoogleRoutesProvider::class)->route($a, $b, $this->departure())['reason_code']));
        config(['services.google_routes.limit_elements' => 1]);
        app(GoogleRoutesBudget::class)->reserve();
        $this->assertSame('routes_budget_exhausted', app(TravelTimeEstimator::class)->estimate($a, $b, $this->departure())['reason_code']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('scheduling_route_usage', 1);
    }
}
