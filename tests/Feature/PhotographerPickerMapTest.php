<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AddressLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class PhotographerPickerMapTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photographer = User::factory()->photographer()->create([
            'name' => 'Map Photographer',
            'address' => '1 Home Base Rd',
            'city' => 'Arlington',
            'state' => 'VA',
            'zip' => '22201',
            'metadata' => ['latitude' => 38.89, 'longitude' => -77.08],
        ]);
        $this->admin = User::factory()->admin()->create();

        PhotographerAvailability::create([
            'photographer_id' => $this->photographer->id,
            'date' => '2026-09-15',
            'day_of_week' => 'tuesday',
            'start_time' => '08:00',
            'end_time' => '18:00',
            'status' => 'available',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function privileged_for_booking_includes_map_last_next_drive_and_risk(): void
    {
        $last = Shoot::factory()->create([
            'photographer_id' => $this->photographer->id,
            'address' => '12 Previous Client Street',
            'city' => 'Alexandria',
            'state' => 'VA',
            'zip' => '22314',
            'latitude' => 38.81,
            'longitude' => -77.06,
            'scheduled_date' => '2026-09-15',
            'scheduled_at' => '2026-09-15 09:00:00',
            'time' => '09:00',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $next = Shoot::factory()->create([
            'photographer_id' => $this->photographer->id,
            'address' => '88 Later Lane',
            'city' => 'Arlington',
            'state' => 'VA',
            'zip' => '22201',
            'latitude' => 38.88,
            'longitude' => -77.10,
            'scheduled_date' => '2026-09-15',
            'scheduled_at' => '2026-09-15 15:00:00',
            'time' => '15:00',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->andReturnUsing(function (array $origin, array $destination) {
            // last→job uses previous street; job→next uses later lane as destination address
            $destAddress = (string) ($destination['address'] ?? '');
            if (str_contains($destAddress, 'Later')) {
                return [
                    'distance_value' => 1609.34 * 5,
                    'duration_value' => 22 * 60,
                    'source' => 'google_distance_matrix',
                    'is_estimate' => false,
                ];
            }

            return [
                'distance_value' => 1609.34 * 12.4,
                'duration_value' => 18 * 60,
                'source' => 'google_distance_matrix',
                'is_estimate' => false,
            ];
        });
        $this->app->instance(AddressLookupService::class, $distance);

        Sanctum::actingAs($this->admin);
        $payload = $this->postJson('/api/photographer/availability/for-booking', $this->payload([
            'time' => '13:00',
            'duration_minutes' => 60,
            'shoot_latitude' => 38.8462,
            'shoot_longitude' => -77.3064,
        ]))->assertOk()->json();

        $row = $payload['data'][0];
        $this->assertEquals(12.4, $row['miles_to_job']);
        $this->assertEquals(12.4, $row['distance']);
        $this->assertIsArray($row['map']);
        $this->assertSame(['lat' => 38.89, 'lng' => -77.08], $row['map']['home']);
        $this->assertSame(['lat' => 38.8462, 'lng' => -77.3064], $row['map']['job']);
        $this->assertSame($last->id, $row['map']['last_shoot']['shoot_id']);
        $this->assertSame(38.81, $row['map']['last_shoot']['lat']);
        $this->assertNotEmpty($row['map']['last_shoot']['ends_at']);
        $this->assertSame($next->id, $row['map']['next_shoot']['shoot_id']);
        $this->assertSame(38.88, $row['map']['next_shoot']['lat']);
        $this->assertSame(18, $row['map']['drive_minutes']['last_to_job']);
        $this->assertSame(22, $row['map']['drive_minutes']['job_to_next']);
        $this->assertFalse($row['map']['drive_minutes']['is_estimate']);
        $this->assertSame('google_distance_matrix', $row['map']['drive_minutes']['source']);
        $this->assertContains($row['map']['travel_risk']['last_to_job'], ['early', 'tight', 'late']);
        $this->assertContains($row['map']['travel_risk']['job_to_next'], ['early', 'tight', 'late']);
        $this->assertSame(38.8462, $payload['job']['lat']);
        $this->assertSame(-77.3064, $payload['job']['lng']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anonymous_for_booking_keeps_map_null_and_still_lists_photographers(): void
    {
        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->andReturn([
            'distance_value' => 1609.34,
            'duration_value' => 600,
            'source' => 'estimate',
            'is_estimate' => true,
        ]);
        $this->app->instance(AddressLookupService::class, $distance);

        $payload = $this->postJson('/api/photographer/availability/for-booking', $this->payload([
            'shoot_latitude' => 38.85,
            'shoot_longitude' => -77.30,
        ]))->assertOk()->json();

        $row = $payload['data'][0];
        $this->assertNull($row['map']);
        $this->assertEquals(1.0, $row['miles_to_job']);
        $this->assertSame(38.85, $payload['job']['lat']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function missing_coords_fail_open_with_null_map_pins(): void
    {
        $this->photographer->update(['metadata' => []]);
        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->andReturnNull();
        $this->app->instance(AddressLookupService::class, $distance);

        Sanctum::actingAs($this->admin);
        $row = $this->postJson('/api/photographer/availability/for-booking', $this->payload())
            ->assertOk()
            ->json('data.0');

        $this->assertIsArray($row['map']);
        $this->assertNull($row['map']['home']);
        $this->assertNull($row['map']['job']);
        $this->assertNull($row['map']['last_shoot']);
        $this->assertNull($row['map']['next_shoot']);
        $this->assertSame($this->photographer->id, $row['id']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-09-15',
            'time' => '1:00 PM',
            'duration_minutes' => 60,
            'shoot_address' => '500 Booking Avenue',
            'shoot_city' => 'Fairfax',
            'shoot_state' => 'VA',
            'shoot_zip' => '22030',
            'photographer_ids' => [$this->photographer->id],
        ], $overrides);
    }
}
