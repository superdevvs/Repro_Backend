<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesSchedulingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_can_read_roster_and_calendars_without_an_existing_shoot(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $photographer = User::factory()->photographer()->create();
        $secondary = User::factory()->create(['role' => 'editor', 'secondary_roles' => ['photographer']]);
        $slot = PhotographerAvailability::create([
            'photographer_id' => $photographer->id, 'day_of_week' => 'monday',
            'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available',
        ]);
        Sanctum::actingAs($rep);

        $roster = $this->getJson('/api/photographers')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$photographer->id, $secondary->id], array_column($roster, 'id'));
        $this->getJson("/api/photographer/availability/{$photographer->id}")->assertOk()
            ->assertJsonPath('data.0.id', $slot->id);
        $this->postJson('/api/photographer/availability/bulk-index', [
            'photographer_ids' => [$photographer->id, $secondary->id],
            'from_date' => '2026-09-28', 'to_date' => '2026-09-28',
        ])->assertOk()->assertJsonPath("data.{$photographer->id}.0.id", $slot->id);
        $this->postJson('/api/photographer/availability/booked-slots', [
            'photographer_id' => $photographer->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-28',
        ])->assertOk();
        $this->postJson('/api/photographer/availability/available-photographers', [
            'date' => '2026-09-28', 'start_time' => '09:00', 'end_time' => '17:00',
        ])->assertOk()->assertJsonPath('data.0.photographer_id', $photographer->id);

        $this->deleteJson("/api/photographer/availability/{$slot->id}")->assertForbidden();
        $this->assertModelExists($slot);
    }
}
