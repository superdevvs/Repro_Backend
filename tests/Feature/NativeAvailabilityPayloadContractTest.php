<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NativeAvailabilityPayloadContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_period_response_contains_raw_rules_date_exceptions_bookings_and_empty_people(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02T12:00:00Z'));
        config(['app.timezone' => 'UTC', 'availability.default_shoot_duration_minutes' => 60]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $rep = User::factory()->create(['role' => 'salesRep', 'name' => 'Synthetic Representative']);
        $person = User::factory()->photographer()->create([
            'name' => 'Synthetic Photographer', 'timezone' => 'America/New_York', 'avatar' => null,
        ]);
        $unconfigured = User::factory()->photographer()->create([
            'name' => 'Synthetic Unconfigured Photographer', 'timezone' => 'America/Los_Angeles', 'avatar' => null,
        ]);
        $client = User::factory()->create([
            'role' => 'client', 'name' => 'Synthetic Client', 'email' => 'native-fixture@example.invalid',
        ]);
        PhotographerAvailability::create([
            'photographer_id' => $person->id, 'date' => null, 'day_of_week' => 'monday',
            'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available', 'note' => 'Weekly hours',
        ]);
        Sanctum::actingAs($rep);
        $this->postJson('/api/photographer/availability', [
            'photographer_id' => $person->id, 'date' => '2026-11-02',
            'start_time' => '12:00', 'end_time' => '13:00', 'status' => 'unavailable', 'note' => 'Date-only block',
        ])->assertCreated();
        $shoot = Shoot::factory()->createQuietly([
            'client_id' => $client->id, 'photographer_id' => $person->id,
            'scheduled_at' => '2026-11-02 15:30:00', 'scheduled_date' => '2026-11-02', 'time' => '10:30:00',
            'timezone' => 'America/New_York', 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'address' => '100 Synthetic Test Lane', 'city' => 'Test City', 'state' => 'VA', 'zip' => '00000',
            'latitude' => 38.9, 'longitude' => -77.1,
        ]);
        $roster = $this->getJson('/api/photographers')->assertOk();
        $period = $this->postJson('/api/photographer/availability/bulk-index', [
            'photographer_ids' => [$person->id, $unconfigured->id],
            'from_date' => '2026-11-02', 'to_date' => '2026-11-08',
        ])->assertOk()->assertJsonPath('data.'.$unconfigured->id, [])
            ->assertJsonPath('meta.timezones.'.$person->id, 'America/New_York')
            ->assertJsonPath('meta.timezones.'.$unconfigured->id, 'America/Los_Angeles')
            ->assertJsonPath('meta.can_manage.'.$person->id, true)
            ->assertJsonPath('meta.can_manage.'.$unconfigured->id, true);
        $rows = collect($period->json('data.'.$person->id));
        $weekly = $rows->first(fn ($row) => $row['date'] === null);
        $this->assertSame('available', $weekly['status']);
        $this->assertIsInt($weekly['id']);
        $dated = $rows->first(fn ($row) => $row['status'] === 'unavailable');
        $this->assertSame('2026-11-02', $dated['date']);
        $booked = $rows->first(fn ($row) => $row['status'] === 'booked');
        $this->assertIsString($booked['id']);
        $this->assertSame($shoot->id, $booked['shoot_id']);
        $this->assertSame('10:30', $booked['start_time']);
        $this->assertSame('11:30', $booked['end_time']);
        $this->assertSame('America/New_York', $booked['timezone']);
        $this->assertSame('2026-11-02T10:30:00-05:00', $booked['starts_at']);
        $this->assertSame('2026-11-02T11:30:00-05:00', $booked['ends_at']);
        $this->assertSame('100 Synthetic Test Lane', $booked['shoot_details']['address']);
        $this->assertNotNull($booked['shoot_details']['latitude']);
        $individual = $this->getJson('/api/photographer/availability/'.$person->id)
            ->assertOk()->assertJsonPath('timezone', 'America/New_York');

        // Optional local-only wire fixtures for the Swift decoder; no headers/tokens or real records.
        if ($directory = getenv('NATIVE_AVAILABILITY_FIXTURE_DIR')) {
            foreach (['roster' => $roster, 'period' => $period, 'individual' => $individual] as $name => $response) {
                file_put_contents($directory.'/'.$name.'.json', json_encode($response->json(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
        }
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_period_metadata_is_scoped_authoritative_and_canonical_without_exposing_more_public_roster_data(): void
    {
        config(['app.timezone' => 'UTC']);
        $person = User::factory()->photographer()->create(['timezone' => 'Asia/Calcutta']);
        $client = User::factory()->create(['role' => 'client', 'timezone' => 'Invalid/Zone']);
        $request = ['photographer_ids' => [$person->id, $client->id],
            'from_date' => '2026-11-02', 'to_date' => '2026-11-02'];
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $this->postJson('/api/photographer/availability/bulk-index', $request)->assertOk()
            ->assertJsonPath('meta.can_manage.'.$person->id, true)
            ->assertJsonPath('meta.can_manage.'.$client->id, false)
            ->assertJsonPath('meta.timezones.'.$person->id, 'Asia/Kolkata')
            ->assertJsonPath('meta.timezones.'.$client->id, 'UTC');
        Sanctum::actingAs($person);
        $this->postJson('/api/photographer/availability/bulk-index', $request)->assertForbidden();
        $this->postJson('/api/photographer/availability/bulk-index', array_replace($request, [
            'photographer_ids' => [$person->id],
        ]))->assertOk()->assertJsonPath('meta.can_manage.'.$person->id, true)
            ->assertJsonMissingPath('meta.timezones.'.$client->id);
        $public = $this->getJson('/api/photographers')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('timezone', $public);
        $this->assertArrayNotHasKey('secondary_roles', $public);
    }
}
