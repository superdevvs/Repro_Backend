<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvailabilityResponsiveWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_calendar_exposes_its_authorized_export_timezone(): void
    {
        $person = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        Sanctum::actingAs($person);
        $this->getJson('/api/photographer/availability/'.$person->id)->assertOk()->assertJsonPath('timezone', 'America/New_York');
    }

    public function test_dated_hours_override_weekly_hours_and_remain_editable_under_blocks(): void
    {
        $person = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($person);
        $base = ['photographer_id' => $person->id, 'day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available'];
        $this->postJson('/api/photographer/availability', $base)->assertCreated();
        $id = $this->postJson('/api/photographer/availability', array_merge($base, ['date' => '2026-10-26', 'end_time' => '15:00']))->assertCreated()->json('data.id');
        $this->postJson('/api/photographer/availability', array_merge($base, ['date' => '2026-10-26', 'status' => 'unavailable', 'start_time' => '10:00', 'end_time' => '11:00']))->assertCreated();
        $this->putJson('/api/photographer/availability/'.$id, ['start_time' => '09:00', 'end_time' => '14:00', 'note' => 'Short day'])->assertOk();
        $service = app(PhotographerAvailabilityService::class);
        $date = Carbon::parse('2026-10-26');
        $this->assertSame([['start' => '09:00', 'end' => '10:00'], ['start' => '11:00', 'end' => '14:00']], $service->getAvailableSlots($person->id, $date, $date)['2026-10-26']);
        $next = Carbon::parse('2026-11-02');
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $service->getAvailableSlots($person->id, $next, $next)['2026-11-02']);
    }

    public function test_notes_survive_create_edit_and_team_refresh_without_waiting_for_cache(): void
    {
        $person = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $payload = ['photographer_id' => $person->id, 'date' => '2026-10-26', 'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available', 'note' => 'Photo and drone'];
        $query = ['photographer_ids' => [$person->id], 'from_date' => '2026-10-26', 'to_date' => '2026-10-26'];
        $this->postJson('/api/photographer/availability/bulk-index', $query)->assertOk();
        $id = $this->postJson('/api/photographer/availability', $payload)->assertCreated()->assertJsonPath('data.note', 'Photo and drone')->json('data.id');
        $this->postJson('/api/photographer/availability/bulk-index', $query)->assertOk()->assertJsonPath('data.'.$person->id.'.0.note', 'Photo and drone');
        $this->putJson('/api/photographer/availability/'.$id, ['note' => 'Drone only'])->assertOk()->assertJsonPath('data.note', 'Drone only');
        $this->postJson('/api/photographer/availability/bulk-index', $query)->assertOk()->assertJsonPath('data.'.$person->id.'.0.note', 'Drone only');
        $this->deleteJson('/api/photographer/availability/'.$id)->assertOk();
        $this->postJson('/api/photographer/availability/bulk-index', $query)->assertOk()->assertJsonCount(0, 'data.'.$person->id);
    }

    public function test_block_edit_and_removal_restore_working_hours_and_preserve_other_blocks(): void
    {
        $person = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($person);
        PhotographerAvailability::create(['photographer_id' => $person->id, 'date' => null, 'day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available']);
        $payload = ['photographer_id' => $person->id, 'date' => '2026-10-26', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'unavailable'];
        $id = $this->postJson('/api/photographer/availability', $payload)->assertCreated()->json('data.id');
        $second = $this->postJson('/api/photographer/availability', array_replace($payload, ['start_time' => '14:00', 'end_time' => '15:00']))->assertCreated()->json('data.id');
        $service = app(PhotographerAvailabilityService::class);
        $day = Carbon::parse('2026-10-26');
        $this->assertSame([['start' => '09:00', 'end' => '10:00'], ['start' => '11:00', 'end' => '14:00'], ['start' => '15:00', 'end' => '17:00']], $service->getAvailableSlots($person->id, $day, $day)['2026-10-26']);
        $this->putJson('/api/photographer/availability/'.$id, ['start_time' => '11:00', 'end_time' => '12:00'])->assertOk();
        $this->assertSame([['start' => '09:00', 'end' => '11:00'], ['start' => '12:00', 'end' => '14:00'], ['start' => '15:00', 'end' => '17:00']], $service->getAvailableSlots($person->id, $day, $day)['2026-10-26']);
        $this->deleteJson('/api/photographer/availability/'.$id)->assertOk();
        $this->assertSame([['start' => '09:00', 'end' => '14:00'], ['start' => '15:00', 'end' => '17:00']], $service->getAvailableSlots($person->id, $day, $day)['2026-10-26']);
        $this->deleteJson('/api/photographer/availability/'.$second)->assertOk();
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $service->getAvailableSlots($person->id, $day, $day)['2026-10-26']);
    }

    public function test_bulk_and_weekly_notes_and_existing_role_boundaries(): void
    {
        $person = User::factory()->create(['role' => 'photographer']);
        $other = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $payload = ['photographer_id' => $person->id, 'availabilities' => [['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available', 'note' => 'Weekday availability']]];
        $this->postJson('/api/photographer/availability/bulk', $payload)->assertCreated()->assertJsonPath('data.0.note', 'Weekday availability');
        $this->postJson('/api/photographer/availability/replace-weekly', $payload)->assertOk()->assertJsonPath('data.0.note', 'Weekday availability');
        Sanctum::actingAs($other);
        $this->postJson('/api/photographer/availability/replace-weekly', $payload)->assertForbidden();
        $this->postJson('/api/photographer/availability/bulk-index', ['photographer_ids' => [$person->id]])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $this->postJson('/api/photographer/availability/bulk', $payload)->assertForbidden();
    }
}
