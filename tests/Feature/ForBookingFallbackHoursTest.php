<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Book Shoot /for-booking must apply Backend_Fallback_Hours when a photographer
 * has no configured available windows — matching assertWithinAvailabilityBounds.
 */
class ForBookingFallbackHoursTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-10-02',
            'time' => '13:30',
            'duration_minutes' => 60,
            'shoot_address' => '100 Main St',
            'shoot_city' => 'Newark',
            'shoot_state' => 'NJ',
            'shoot_zip' => '07102',
        ], $overrides);
    }

    public function test_for_booking_uses_fallback_hours_when_photographer_has_no_schedule(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep', 'account_status' => 'active']);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'account_status' => 'active',
            'name' => 'No Schedule Photographer',
            'address' => '100 Main St',
            'city' => 'Newark',
            'state' => 'NJ',
            'zip' => '07102',
        ]);

        $this->assertSame(0, PhotographerAvailability::where('photographer_id', $photographer->id)->count());

        $row = $this->actingAs($rep)
            ->postJson('/api/photographer/availability/for-booking', $this->payload([
                'photographer_ids' => [$photographer->id],
            ]))
            ->assertOk()
            ->json('data.0');

        $this->assertNotNull($row);
        $this->assertTrue($row['is_available_at_time']);
        $this->assertTrue($row['has_availability']);
        $this->assertSame('09:00', $row['net_available_slots'][0]['start_time'] ?? null);
        $this->assertSame('18:00', $row['net_available_slots'][0]['end_time'] ?? null);
    }

    public function test_for_booking_fallback_rejects_time_outside_fallback_window(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep', 'account_status' => 'active']);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'account_status' => 'active',
            'address' => '100 Main St',
            'city' => 'Newark',
            'state' => 'NJ',
            'zip' => '07102',
        ]);

        $row = $this->actingAs($rep)
            ->postJson('/api/photographer/availability/for-booking', $this->payload([
                'time' => '18:30',
                'photographer_ids' => [$photographer->id],
            ]))
            ->assertOk()
            ->json('data.0');

        $this->assertNotNull($row);
        $this->assertFalse($row['is_available_at_time']);
    }
}
