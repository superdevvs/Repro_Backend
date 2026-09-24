<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhotographerAvailabilityRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    private function monday(User $photographer, array $attributes = []): array
    {
        return array_merge([
            'photographer_id' => $photographer->id,
            'day_of_week' => 'monday',
            'start_time' => '09:00',
            'end_time' => '21:00',
            'status' => 'available',
        ], $attributes);
    }

    public function test_bulk_recurring_mondays_preserve_dated_exceptions_and_open_october(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);

        foreach (['2026-04-27', '2026-09-21', '2026-09-28'] as $date) {
            PhotographerAvailability::create($this->monday($photographer, [
                'date' => $date, 'end_time' => '17:00',
            ]));
        }
        $blocked = PhotographerAvailability::create($this->monday($photographer, [
            'date' => '2026-11-16', 'status' => 'unavailable',
        ]));

        $this->postJson('/api/photographer/availability/bulk', [
            'photographer_id' => $photographer->id,
            'availabilities' => [$this->monday($photographer)],
        ])->assertCreated()->assertJsonCount(1, 'data');

        $service = app(PhotographerAvailabilityService::class);
        foreach (['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'] as $date) {
            $slots = $service->getAvailableSlots($photographer->id, Carbon::parse($date), Carbon::parse($date));
            $this->assertSame([['start' => '09:00', 'end' => '21:00']], $slots[$date]);
        }
        $this->assertSame([], $service->getAvailableSlots(
            $photographer->id, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-16')
        ));
        $september = $service->getAvailableSlots(
            $photographer->id, Carbon::parse('2026-09-28'), Carbon::parse('2026-09-28')
        );
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $september['2026-09-28']);
        $this->assertSame('unavailable', $blocked->fresh()->status);
        $this->assertDatabaseCount('photographer_availabilities', 5);
    }

    public function test_recurring_create_and_update_ignore_dated_exceptions(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);
        PhotographerAvailability::create($this->monday($photographer, ['date' => '2026-09-28']));

        $id = $this->postJson('/api/photographer/availability', $this->monday($photographer))
            ->assertCreated()->json('data.id');
        $this->putJson('/api/photographer/availability/'.$id, [
            'start_time' => '10:00', 'end_time' => '18:00',
        ])->assertOk()->assertJsonPath('data.start_time', '10:00');
    }

    public function test_fully_blocked_date_does_not_fall_back_to_recurring_hours(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);
        PhotographerAvailability::create($this->monday($photographer));
        PhotographerAvailability::create($this->monday($photographer, [
            'date' => '2026-11-16', 'status' => 'unavailable',
        ]));

        $this->postJson('/api/photographer/availability/check', [
            'photographer_id' => $photographer->id, 'date' => '2026-11-16',
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recurring_conflicts_are_rejected_but_adjacent_hours_are_allowed(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);
        PhotographerAvailability::create($this->monday($photographer, ['end_time' => '12:00']));

        $this->postJson('/api/photographer/availability/bulk', [
            'photographer_id' => $photographer->id,
            'availabilities' => [$this->monday($photographer)],
        ])->assertUnprocessable()->assertJsonPath('error', 'overlap');
        $this->postJson('/api/photographer/availability', $this->monday($photographer, [
            'start_time' => '12:00',
        ]))->assertCreated();
    }

    public function test_dated_conflicts_and_duplicate_recurring_batch_slots_are_still_rejected(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);
        $dated = $this->monday($photographer, ['date' => '2026-10-05']);
        PhotographerAvailability::create($dated);
        $this->postJson('/api/photographer/availability', $dated)
            ->assertUnprocessable()->assertJsonPath('error', 'overlap');

        $this->postJson('/api/photographer/availability/bulk', [
            'photographer_id' => $photographer->id,
            'availabilities' => [$this->monday($photographer), $this->monday($photographer)],
        ])->assertUnprocessable()->assertJsonPath('error', 'overlap');
        $this->assertDatabaseCount('photographer_availabilities', 1);
    }
}
