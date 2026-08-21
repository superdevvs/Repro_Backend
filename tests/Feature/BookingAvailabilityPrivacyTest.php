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
    public function authenticated_client_gets_only_city_state_fallback(): void
    {
        $this->createPreviousShoot();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));

        $data = $this->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Arlington, VA', $data['service_area_label']);
        $this->assertArrayNotHasKey('previous_shoot_id', $data);
        $this->assertStringNotContainsString('Private Home', (string) $data['service_area_label']);
        $this->assertStringNotContainsString('22201', (string) $data['service_area_label']);
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
