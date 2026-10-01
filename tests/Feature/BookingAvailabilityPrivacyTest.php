<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\ServiceArea;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AddressLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class BookingAvailabilityPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photographer = User::factory()->photographer()->create([
            'name' => 'Privacy Photographer',
            'address' => '99 Private Home Lane',
            'city' => 'Arlington',
            'state' => 'VA',
            'zip' => '22201',
        ]);

        PhotographerAvailability::create([
            'photographer_id' => $this->photographer->id,
            'date' => '2026-09-15',
            'day_of_week' => 'tuesday',
            'start_time' => '08:00',
            'end_time' => '18:00',
            'status' => 'available',
        ]);

        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->andReturn(['distance_value' => 1609.34]);
        $this->app->instance(AddressLookupService::class, $distance);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anonymous_booking_payload_uses_public_area_and_omits_booking_identifiers(): void
    {
        $area = ServiceArea::create([
            'kind' => ServiceArea::KIND_AREA,
            'value' => 'nova',
            'label' => 'Northern Virginia',
        ]);
        $this->photographer->serviceAreas()->attach($area->id);
        $previousShoot = $this->createPreviousShoot();

        $data = $this->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Northern Virginia', $data['service_area_label']);
        $this->assertSame('previous_shoot', $data['distance_from']);
        $this->assertEquals(1.0, $data['distance']);
        $this->assertArrayNotHasKey('previous_shoot_id', $data);
        $this->assertArrayNotHasKey('shoot_id', $data['booked_slots'][0]);
        $this->assertArrayNotHasKey('address', $data['booked_slots'][0]);
        $this->assertArrayNotHasKey('client_name', $data['booked_slots'][0]);
        $this->assertArrayNotHasKey('services', $data['booked_slots'][0]);
        $this->assertArrayNotHasKey('city', $data['booked_slots'][0]);
        $this->assertArrayNotHasKey('state', $data['booked_slots'][0]);
        $this->assertArrayNotHasKey('zip', $data['booked_slots'][0]);
        $this->assertNotSame($previousShoot->id, $data['previous_shoot_id'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function authenticated_client_receives_distance_without_location(): void
    {
        $area = ServiceArea::create([
            'kind' => ServiceArea::KIND_AREA,
            'value' => 'nova',
            'label' => 'Northern Virginia',
        ]);
        $this->photographer->serviceAreas()->attach($area->id);
        $this->createPreviousShoot();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));

        $data = $this->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()
            ->json('data.0');

        $encoded = json_encode($data);
        $this->assertNull($data['service_area_label']);
        $this->assertEquals(1.0, $data['distance']);
        $this->assertArrayNotHasKey('previous_shoot_id', $data);
        $this->assertStringNotContainsString('Private Home', (string) $encoded);
        $this->assertStringNotContainsString('Arlington', (string) $encoded);
        $this->assertStringNotContainsString('22201', (string) $encoded);
        $this->assertStringNotContainsString('Northern Virginia', (string) $encoded);
        $this->assertArrayNotHasKey('client_name', $data['booked_slots'][0]);
        $this->assertArrayNotHasKey('services', $data['booked_slots'][0]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anonymous_booking_payload_never_uses_home_profile_fallback(): void
    {
        $data = $this->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()
            ->json('data.0');

        $this->assertNull($data['service_area_label']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function privileged_staff_retains_internal_booking_context(): void
    {
        $previousShoot = $this->createPreviousShoot();
        Sanctum::actingAs(User::factory()->admin()->create());

        $data = $this->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()
            ->json('data.0');

        $this->assertSame($previousShoot->id, $data['previous_shoot_id']);
        $this->assertSame($previousShoot->id, $data['booked_slots'][0]['shoot_id']);
        $this->assertSame('12 Previous Client Street', $data['booked_slots'][0]['address']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function real_staff_bearer_token_resolves_on_the_public_booking_route(): void
    {
        $shoot = $this->createPreviousShoot();
        $shoot->client->update(['name' => 'Bearer Overlay Client']);
        $service = Service::factory()->create(['name' => 'Exterior photos']);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'duration_minutes' => 60]);
        $admin = User::factory()->superAdmin()->create();

        $slot = $this->withToken($admin->createToken('staff-browser')->plainTextToken)
            ->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()->json('data.0.booked_slots.0');

        $this->assertSame($shoot->id, $slot['shoot_id']);
        $this->assertSame('Bearer Overlay Client', $slot['client_name']);
        $this->assertSame('12 Previous Client Street', $slot['address']);
        $this->assertSame('Exterior photos', $slot['services'][0]['name']);
        $this->assertSame('09:00', $slot['start_time']);
        $this->assertSame('10:00', $slot['end_time']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function real_client_bearer_token_keeps_other_booking_details_private(): void
    {
        $this->createPreviousShoot();
        $client = User::factory()->create(['role' => 'client']);
        $data = $this->withToken($client->createToken('client-browser')->plainTextToken)
            ->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()->json('data.0');

        $this->assertNull($data['service_area_label']);
        $this->assertPrivateBookedSlot($data['booked_slots'][0]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function invalid_bearer_token_still_gets_only_the_public_booking_payload(): void
    {
        $this->createPreviousShoot();
        $slot = $this->withToken('999999|invalid-token')
            ->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()->json('data.0.booked_slots.0');

        $this->assertPrivateBookedSlot($slot);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function real_bearer_token_cannot_bypass_an_inactive_account_gate(): void
    {
        $admin = User::factory()->superAdmin()->create(['account_status' => 'inactive']);
        $this->withToken($admin->createToken('stale-browser')->plainTextToken)
            ->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertUnauthorized();
        $this->assertSame(0, $admin->tokens()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function staff_impersonating_a_client_receives_only_the_client_booking_payload(): void
    {
        $this->createPreviousShoot();
        $admin = User::factory()->superAdmin()->create();
        $client = User::factory()->create(['role' => 'client']);
        $slot = $this->withToken($admin->createToken('admin-browser')->plainTextToken)
            ->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()->json('data.0.booked_slots.0');

        $this->assertPrivateBookedSlot($slot);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function impersonation_cannot_bypass_an_inactive_original_staff_account(): void
    {
        $admin = User::factory()->superAdmin()->create(['account_status' => 'inactive']);
        $client = User::factory()->create(['role' => 'client']);
        $this->withToken($admin->createToken('stale-admin-browser')->plainTextToken)
            ->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertUnauthorized();
        $this->assertSame(0, $admin->tokens()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function impersonation_cannot_bypass_an_inactive_target_account(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $client = User::factory()->create(['role' => 'client', 'account_status' => 'inactive']);
        $this->withToken($admin->createToken('admin-browser')->plainTextToken)
            ->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertUnauthorized();
        $this->assertSame(1, $admin->tokens()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function optional_bearer_auth_enforces_email_verification_for_the_original_actor(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $this->artisan('auth:start-email-verification-pilot', ['--apply' => true])->assertSuccessful();
        $admin = User::factory()->superAdmin()->unverified()->create();
        $token = $admin->createToken('unverified-browser')->plainTextToken;
        $this->travel(14)->days();

        $this->withToken($token)->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertForbidden()->assertJsonPath('code', 'email_verification_required');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function optional_bearer_auth_enforces_email_verification_for_an_impersonated_target(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->artisan('auth:start-email-verification-pilot', ['--apply' => true])->assertSuccessful();
        $client = User::factory()->unverified()->create();
        $this->travel(14)->days();

        $this->withToken($admin->createToken('admin-browser')->plainTextToken)
            ->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertForbidden()->assertJsonPath('code', 'email_verification_required');
    }

    private function assertPrivateBookedSlot(array $slot): void
    {
        foreach (['shoot_id', 'client_name', 'address', 'city', 'state', 'zip', 'services'] as $field) {
            $this->assertArrayNotHasKey($field, $slot);
        }
        $this->assertSame('09:00', $slot['start_time']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function booked_overlay_details_match_the_saved_service_window_in_both_staff_apis(): void
    {
        config(['availability.default_shoot_duration_minutes' => 60]);
        $shoot = $this->createPreviousShoot();
        $shoot->client->update(['name' => 'Overlay Client']);
        $service = Service::factory()->create(['name' => 'Exterior photos']);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'duration_minutes' => 90]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $booking = $this->postJson('/api/photographer/availability/for-booking', $this->payload())->assertOk()->json('data.0.booked_slots.0');
        $check = collect($this->postJson('/api/photographer/availability/check', ['photographer_id' => $this->photographer->id, 'date' => '2026-09-15'])->assertOk()->json('data'))
            ->firstWhere('status', 'booked');
        foreach ([$booking, $check] as $slot) {
            $this->assertSame('Overlay Client', $slot['client_name']);
            $this->assertSame('12 Previous Client Street', $slot['address']);
            $this->assertSame('Exterior photos', $slot['services'][0]['name']);
            $this->assertSame('09:00', $slot['start_time']);
            $this->assertSame('10:30', $slot['end_time']);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function availability_suggestion_requires_the_entire_selected_duration_to_fit(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        foreach ([[30, true], [60, true], [90, false]] as [$minutes, $expected]) {
            $data = $this->postJson('/api/photographer/availability/for-booking', [
                ...$this->payload(), 'time' => '17:00', 'duration_minutes' => $minutes,
            ])->assertOk()->json('data.0');
            $this->assertSame($expected, $data['is_available_at_time']);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function separately_assigned_service_visit_exposes_details_only_to_staff(): void
    {
        $shoot = $this->createPreviousShoot();
        $shoot->update(['photographer_id' => User::factory()->photographer()->create()->id]);
        $service = Service::factory()->create(['name' => 'Drone photos']);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'duration_minutes' => 30, 'scheduled_at' => '2026-09-15 11:00:00', 'photographer_id' => $this->photographer->id, 'workflow_status' => 'scheduled']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $slot = $this->postJson('/api/photographer/availability/for-booking', $this->payload())->assertOk()->json('data.0.booked_slots.0');
        $this->assertSame('Drone photos', $slot['services'][0]['name']);
        $this->assertSame('11:00', $slot['start_time']);
        $this->assertSame('11:30', $slot['end_time']);
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $slot = $this->postJson('/api/photographer/availability/for-booking', $this->payload())->assertOk()->json('data.0.booked_slots.0');
        foreach (['shoot_id', 'client_name', 'address', 'services'] as $field) $this->assertArrayNotHasKey($field, $slot);
        $this->assertSame('11:30', $slot['end_time']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function home_origin_coordinates_are_forwarded_for_distance_without_being_exposed(): void
    {
        $this->photographer->update(['metadata' => ['latitude' => 38.88, 'longitude' => -77.10]]);
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->once()->withArgs(function ($origin, $destination) {
            return $origin['address'] === '99 Private Home Lane'
                && $origin['latitude'] === 38.88 && $origin['longitude'] === -77.10
                && $destination['latitude'] === 38.85 && $destination['longitude'] === -77.30;
        })->andReturn(['distance_value' => 1609.34]);
        $this->app->instance(AddressLookupService::class, $distance);

        $data = $this->postJson('/api/photographer/availability/for-booking', [
            ...$this->payload(), 'shoot_latitude' => 38.85, 'shoot_longitude' => -77.30,
        ])->assertOk()->json('data.0');

        $this->assertSame('home', $data['distance_from']);
        $this->assertEquals(1.0, $data['distance']);
        $this->assertStringNotContainsString('latitude', json_encode($data));
        $this->assertStringNotContainsString('Private Home', json_encode($data));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function previous_shoot_distance_uses_its_coordinates_instead_of_home_coordinates(): void
    {
        $this->photographer->update(['metadata' => ['latitude' => 10.0, 'longitude' => 10.0]]);
        $this->createPreviousShoot()->update(['latitude' => 38.81, 'longitude' => -77.06]);
        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->once()->withArgs(function ($origin, $destination) {
            return $origin['address'] === '12 Previous Client Street'
                && $origin['latitude'] === 38.81 && $origin['longitude'] === -77.06
                && $destination['latitude'] === 38.81 && $destination['longitude'] === -77.06;
        })->andReturnNull();
        $this->app->instance(AddressLookupService::class, $distance);

        $data = $this->postJson('/api/photographer/availability/for-booking', [
            ...$this->payload(), 'time' => '13:00', 'shoot_latitude' => 38.81, 'shoot_longitude' => -77.06,
        ])->assertOk()->json('data.0');

        $this->assertSame('previous_shoot', $data['distance_from']);
        $this->assertEquals(0.0, $data['distance']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function previous_shoot_without_coordinates_never_falls_back_to_home_coordinates(): void
    {
        $this->photographer->update(['metadata' => ['latitude' => 38.85, 'longitude' => -77.30]]);
        $this->createPreviousShoot()->update(['latitude' => null, 'longitude' => null]);
        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->once()->withArgs(function ($origin) {
            return $origin['latitude'] === null && $origin['longitude'] === null;
        })->andReturnNull();
        $this->app->instance(AddressLookupService::class, $distance);

        $data = $this->postJson('/api/photographer/availability/for-booking', [
            ...$this->payload(), 'shoot_latitude' => 38.85, 'shoot_longitude' => -77.30,
        ])->assertOk()->json('data.0');

        $this->assertSame('previous_shoot', $data['distance_from']);
        $this->assertNull($data['distance']);
    }

    private function createPreviousShoot(): Shoot
    {
        return Shoot::factory()->create([
            'photographer_id' => $this->photographer->id,
            'address' => '12 Previous Client Street',
            'city' => 'Alexandria',
            'state' => 'VA',
            'zip' => '22314',
            'scheduled_date' => '2026-09-15',
            'scheduled_at' => '2026-09-15 09:00:00',
            'time' => '09:00',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
    }

    private function payload(): array
    {
        return [
            'date' => '2026-09-15',
            'time' => '1:00 PM',
            'shoot_address' => '500 Booking Avenue',
            'shoot_city' => 'Fairfax',
            'shoot_state' => 'VA',
            'shoot_zip' => '22030',
            'photographer_ids' => [$this->photographer->id],
        ];
    }
}
