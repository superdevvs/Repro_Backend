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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhotographerAvailabilityBookedTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'availability.default_shoot_duration_minutes' => 60, 'availability.booked_block_duration_minutes' => 120]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    public static function appointmentTimes(): array
    {
        return [
            'reported imported appointment' => ['2026-09-28 16:30:00', 'America/New_York', 'America/New_York', '2026-09-28', '12:30', '14:30'],
            'jaz shoot 134 style' => ['2026-10-02 14:00:00', 'America/New_York', 'America/New_York', '2026-10-02', '10:00', '12:00'],
            'legacy local appointment' => ['2026-09-28 10:00:00', null, 'America/New_York', '2026-09-28', '10:00', '12:00'],
            'blank timezone uses legacy clock' => ['2026-09-28 10:00:00', '', 'America/New_York', '2026-09-28', '10:00', '12:00'],
            'winter offset' => ['2026-01-15 17:30:00', 'America/New_York', 'America/New_York', '2026-01-15', '12:30', '14:30'],
            'spring DST' => ['2026-03-08 16:30:00', 'America/New_York', 'America/New_York', '2026-03-08', '12:30', '14:30'],
            'autumn DST' => ['2026-11-01 17:30:00', 'America/New_York', 'America/New_York', '2026-11-01', '12:30', '14:30'],
            'UTC next day' => ['2026-09-29 00:30:00', 'America/New_York', 'America/New_York', '2026-09-28', '20:30', '22:30'],
            'UTC previous day' => ['2026-09-27 23:30:00', 'Asia/Tokyo', 'Asia/Tokyo', '2026-09-28', '08:30', '10:30'],
            'photographer zone takes priority' => ['2026-09-28 16:30:00', 'America/New_York', 'America/Los_Angeles', '2026-09-28', '09:30', '11:30'],
            'shoot zone fallback' => ['2026-09-28 16:30:00', 'America/New_York', '', '2026-09-28', '12:30', '14:30'],
        ];
    }

    #[DataProvider('appointmentTimes')]
    public function test_calendar_uses_local_appointment_date_and_time_without_changing_the_shoot(
        string $storedAt, ?string $shootZone, ?string $photographerZone,
        string $localDate, string $start, string $end,
    ): void {
        $photographer = User::factory()->photographer()->create(['timezone' => $photographerZone]);
        $shoot = $this->shoot($photographer, $storedAt, $shootZone, 'scheduled', $localDate);
        $before = $shoot->fresh()->getRawOriginal();
        Sanctum::actingAs($photographer);

        $this->postJson('/api/photographer/availability/booked-slots', [
            'photographer_id' => $photographer->id, 'from_date' => $localDate, 'to_date' => $localDate,
        ])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shoot_id', $shoot->id)
            ->assertJsonPath('data.0.date', $localDate)
            ->assertJsonPath('data.0.day_of_week', strtolower(Carbon::parse($localDate)->format('l')))
            ->assertJsonPath('data.0.start_time', $start)
            ->assertJsonPath('data.0.end_time', $end)
            ->assertJsonPath('data.0.shoot_details.duration_minutes', 120);

        // The adjacent days must not inherit a booking from its raw UTC date.
        foreach ([-1, 1] as $offset) {
            $otherDay = Carbon::parse($localDate)->addDays($offset)->toDateString();
            $this->postJson('/api/photographer/availability/booked-slots', [
                'photographer_id' => $photographer->id, 'from_date' => $otherDay, 'to_date' => $otherDay,
            ])->assertOk()->assertExactJson(['data' => []]);
        }

        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_for_booking_and_check_return_local_booked_windows_for_jaz_134_style(): void
    {
        $photographer = User::factory()->photographer()->create([
            'timezone' => 'America/New_York',
            'name' => 'Jaz Singh',
            'address' => '100 Home St',
            'city' => 'Burke',
            'state' => 'VA',
            'zip' => '22015',
        ]);
        PhotographerAvailability::create([
            'photographer_id' => $photographer->id,
            'day_of_week' => 'friday',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'status' => 'available',
        ]);
        $shoot = $this->shoot($photographer, '2026-10-02 14:00:00', 'America/New_York', 'scheduled', '2026-10-02');
        Sanctum::actingAs(User::factory()->admin()->create());

        $forBooking = $this->postJson('/api/photographer/availability/for-booking', [
            'date' => '2026-10-02',
            'time' => '13:00',
            'shoot_address' => '5629 Herberts Crossing Drive',
            'shoot_city' => 'Burke',
            'shoot_state' => 'VA',
            'shoot_zip' => '22015',
            'photographer_ids' => [$photographer->id],
        ])->assertOk();

        $photographers = collect($forBooking->json('data'));
        $row = $photographers->firstWhere('id', $photographer->id);
        $this->assertNotNull($row, 'photographer missing from for-booking payload');
        $this->assertSame([[
            'start_time' => '10:00',
            'end_time' => '12:00',
            'status' => 'scheduled',
            'shoot_id' => $shoot->id,
            'address' => $shoot->property_address ?? $shoot->address,
            'city' => $shoot->city,
            'state' => $shoot->state,
            'zip' => $shoot->zip,
        ]], array_map(function ($slot) {
            return [
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'status' => $slot['status'],
                'shoot_id' => $slot['shoot_id'] ?? null,
                'address' => $slot['address'] ?? null,
                'city' => $slot['city'] ?? null,
                'state' => $slot['state'] ?? null,
                'zip' => $slot['zip'] ?? null,
            ];
        }, $row['booked_slots']));
        // Must NOT leak UTC wall clocks 14:00-16:00
        $this->assertFalse(collect($row['booked_slots'])->contains(fn ($s) => ($s['start_time'] ?? null) === '14:00'));

        $check = $this->postJson('/api/photographer/availability/check', [
            'photographer_id' => $photographer->id,
            'date' => '2026-10-02',
        ])->assertOk()->json('data');

        $booked = collect($check)->first(fn ($slot) => ($slot['status'] ?? null) === 'booked');
        $this->assertNotNull($booked);
        $this->assertSame('10:00', $booked['start_time']);
        $this->assertSame('12:00', $booked['end_time']);
        $this->assertSame($shoot->id, $booked['shoot_id']);
    }

    public function test_mixed_storage_is_sorted_by_local_time_and_matches_shoot_details(): void
    {
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $laterLegacy = $this->shoot($photographer, '2026-09-28 14:00:00', null);
        $earlierUtc = $this->shoot($photographer, '2026-09-28 16:30:00', 'America/New_York');
        Sanctum::actingAs($photographer);

        $this->postJson('/api/photographer/availability/booked-slots', [
            'photographer_id' => $photographer->id, 'from_date' => '2026-09-01', 'to_date' => '2026-09-30',
        ])->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.shoot_id', $earlierUtc->id)
            ->assertJsonPath('data.0.start_time', '12:30')
            ->assertJsonPath('data.1.shoot_id', $laterLegacy->id)
            ->assertJsonPath('data.1.start_time', '14:00');

        $this->getJson('/api/shoots/'.$earlierUtc->id)->assertOk()
            ->assertJsonPath('data.scheduled_instant', '2026-09-28T16:30:00+00:00')
            ->assertJsonPath('data.schedule_timezone', 'America/New_York');
    }

    public function test_calendar_keeps_booking_status_and_photographer_access_filters(): void
    {
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $other = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $scheduled = $this->shoot($photographer, '2026-09-28 16:30:00', 'America/New_York');
        $this->shoot($other, '2026-09-28 16:30:00', 'America/New_York');
        foreach (['delivered', 'cancelled', 'on_hold', 'declined'] as $status) {
            $this->shoot($photographer, '2026-09-28 16:30:00', 'America/New_York', $status);
        }
        $this->shoot($photographer, null, null);
        Sanctum::actingAs($photographer);
        $request = ['photographer_id' => $photographer->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-28'];

        $this->postJson('/api/photographer/availability/booked-slots', $request)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shoot_id', $scheduled->id);
        $this->postJson('/api/photographer/availability/booked-slots', array_replace($request, ['photographer_id' => $other->id]))
            ->assertForbidden();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/photographer/availability/booked-slots', $request)
            ->assertOk()->assertJsonPath('data.0.start_time', '12:30');
    }

    private function shoot(
        User $photographer,
        ?string $storedAt,
        ?string $timezone,
        string $status = 'scheduled',
        string $scheduledDate = '2026-09-28',
    ): Shoot {
        return Shoot::factory()->createQuietly([
            'photographer_id' => $photographer->id,
            'scheduled_at' => $storedAt,
            'scheduled_date' => $scheduledDate,
            'time' => '12:30:00',
            'timezone' => $timezone,
            'status' => $status,
            'workflow_status' => $status,
            'address' => '5629 Herberts Crossing Drive',
            'city' => 'Burke',
            'state' => 'VA',
            'zip' => '22015',
        ]);
    }
}
