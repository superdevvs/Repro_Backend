<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhotographerAvailabilityCalendarDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_photographer_blocked_day_round_trips_as_the_selected_calendar_date(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);

        $created = $this->postJson('/api/photographer/availability', [
            'photographer_id' => $photographer->id,
            'date' => '2026-09-19',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'status' => 'unavailable',
        ])->assertCreated()->json('data');

        $this->assertSame('2026-09-19', $created['date']);
        $this->assertDoesNotMatchRegularExpression('/T/', (string) $created['date']);
        $this->assertSame('saturday', $created['day_of_week']);

        $listed = $this->getJson('/api/photographer/availability/'.$photographer->id)
            ->assertOk()
            ->json('data.0');

        $this->assertSame('2026-09-19', $listed['date']);
        $this->assertSame('unavailable', $listed['status']);
        $this->assertSame($photographer->id, PhotographerAvailability::query()->sole()->photographer_id);
    }
}
