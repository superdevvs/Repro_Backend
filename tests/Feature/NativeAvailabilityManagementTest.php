<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NativeAvailabilityManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    public static function representativeRoles(): array
    {
        return array_map(fn ($role) => [$role], ['salesRep', 'sales_rep', 'sales-rep', 'rep', 'representative']);
    }

    #[DataProvider('representativeRoles')]
    public function test_representative_can_manage_a_photographers_dates_weekly_hours_and_blocks(string $role): void
    {
        $person = User::factory()->photographer()->create();
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $date = ['photographer_id' => $person->id, 'date' => '2026-11-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available', 'note' => 'Date exception'];

        $id = $this->postJson('/api/photographer/availability', $date)->assertCreated()->json('data.id');
        $this->putJson('/api/photographer/availability/'.$id, [
            'start_time' => '10:00', 'end_time' => '16:00', 'note' => 'Updated date exception',
        ])->assertOk()->assertJsonPath('data.note', 'Updated date exception');
        $blockId = $this->postJson('/api/photographer/availability', array_replace($date, [
            'start_time' => '12:00', 'end_time' => '13:00', 'status' => 'unavailable', 'note' => 'Lunch',
        ]))->assertCreated()->json('data.id');

        $this->postJson('/api/photographer/availability/replace-weekly', [
            'photographer_id' => $person->id, 'availabilities' => [[
                'day_of_week' => 'monday', 'start_time' => '08:00', 'end_time' => '18:00',
                'status' => 'available', 'note' => 'Regular Monday',
            ]],
        ])->assertOk()->assertJsonPath('data.0.note', 'Regular Monday');
        $this->assertDatabaseHas('photographer_availabilities', ['id' => $id, 'note' => 'Updated date exception']);
        $this->assertDatabaseHas('photographer_availabilities', ['id' => $blockId, 'status' => 'unavailable']);

        $this->postJson('/api/photographer/availability/bulk', [
            'photographer_id' => $person->id, 'availabilities' => [[
                'day_of_week' => 'tuesday', 'start_time' => '09:00', 'end_time' => '17:00',
                'status' => 'available', 'note' => 'Regular Tuesday',
            ]],
        ])->assertCreated();
        $rows = $this->postJson('/api/photographer/availability/bulk-index', [
            'photographer_ids' => [$person->id], 'from_date' => '2026-11-02', 'to_date' => '2026-11-03',
        ])->assertOk()->json('data.'.$person->id);
        $this->assertContains('Lunch', array_column($rows, 'note'));
        $this->deleteJson('/api/photographer/availability/'.$id)->assertOk();
        $this->deleteJson('/api/photographer/availability/'.$blockId)->assertOk();
        $this->deleteJson('/api/photographer/availability/clear/'.$person->id)->assertOk();
        $this->assertDatabaseMissing('photographer_availabilities', ['photographer_id' => $person->id]);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_secondary_photographers_are_manageable_but_other_account_calendars_are_not(): void
    {
        $secondary = User::factory()->create(['role' => 'editor', 'secondary_roles' => ['photographer']]);
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $payload = ['photographer_id' => $secondary->id, 'day_of_week' => 'monday',
            'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available'];
        $this->postJson('/api/photographer/availability', $payload)->assertCreated();
        $this->postJson('/api/photographer/availability', array_replace($payload, [
            'photographer_id' => $client->id,
        ]))->assertForbidden();
        $foreign = PhotographerAvailability::create(array_replace($payload, ['photographer_id' => $client->id]));
        $this->putJson('/api/photographer/availability/'.$foreign->id, ['note' => 'Not permitted'])->assertForbidden();
        $this->deleteJson('/api/photographer/availability/'.$foreign->id)->assertForbidden();
        $this->postJson('/api/photographer/availability/replace-weekly', [
            'photographer_id' => $client->id, 'availabilities' => [],
        ])->assertForbidden();
        $this->deleteJson('/api/photographer/availability/clear/'.$client->id)->assertForbidden();
        $this->assertModelExists($foreign);
    }

    public function test_individual_and_nonstaff_roles_keep_their_existing_boundaries(): void
    {
        $person = User::factory()->photographer()->create();
        $other = User::factory()->photographer()->create();
        $payload = ['photographer_id' => $person->id, 'date' => '2026-11-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available'];
        Sanctum::actingAs($person);
        $id = $this->postJson('/api/photographer/availability', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/photographer/availability', array_replace($payload, [
            'photographer_id' => $other->id,
        ]))->assertForbidden();
        foreach ([$other, User::factory()->create(['role' => 'client']), User::factory()->create(['role' => 'editor'])] as $denied) {
            Sanctum::actingAs($denied);
            $this->putJson('/api/photographer/availability/'.$id, ['note' => 'Not permitted'])->assertForbidden();
            $this->deleteJson('/api/photographer/availability/'.$id)->assertForbidden();
        }
        $this->assertDatabaseHas('photographer_availabilities', ['id' => $id, 'note' => null]);
    }

    public function test_representative_management_keeps_interval_validation_and_google_connection_scope(): void
    {
        $person = User::factory()->photographer()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $payload = ['photographer_id' => $person->id, 'day_of_week' => 'monday',
            'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available'];
        $this->postJson('/api/photographer/availability', $payload)->assertCreated();
        $this->postJson('/api/photographer/availability', $payload)->assertUnprocessable()->assertJsonPath('error', 'overlap');
        $this->postJson('/api/photographer/availability', array_replace($payload, ['end_time' => '08:00']))->assertUnprocessable();
        $this->postJson('/api/photographer/availability', array_replace($payload, ['status' => 'booked']))->assertUnprocessable();
        $this->postJson('/api/photographer/availability', array_replace($payload, ['photographer_id' => 'all']))->assertUnprocessable();
        $this->postJson('/api/google-calendar/connect')->assertForbidden();
        $this->getJson('/api/google-calendar/status')->assertForbidden();
        $this->deleteJson('/api/google-calendar/disconnect')->assertForbidden();
        Http::assertNothingSent();
    }
}
