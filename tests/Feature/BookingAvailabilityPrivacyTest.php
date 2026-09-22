<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\ServiceArea;
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
