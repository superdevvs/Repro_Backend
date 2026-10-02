<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AddressLookupService;
use App\Services\PhotographerAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AvailabilityDayOverrideParityTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['availability.buffer_time_minutes' => 15, 'app.timezone' => 'UTC']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->andReturn(null);
        $this->app->instance(AddressLookupService::class, $distance);
    }

    private function hours(?string $date, string $start, string $end): void
    {
        PhotographerAvailability::create(['photographer_id' => $this->photographer->id,
            'date' => $date, 'day_of_week' => 'friday', 'start_time' => $start, 'end_time' => $end, 'status' => 'available']);
    }

    private function bookingRead(string $time): array
    {
        return $this->postJson('/api/photographer/availability/for-booking', [
            'photographer_ids' => [$this->photographer->id], 'date' => '2026-10-02',
            'time' => $time, 'duration_minutes' => 15, 'shoot_address' => '1 Test Road',
            'shoot_city' => 'Baltimore', 'shoot_state' => 'MD', 'shoot_zip' => '21201',
        ])->assertOk()->json('data.0');
    }

    public function test_dated_shorter_hours_match_read_and_write_checks_under_both_travel_policies(): void
    {
        $this->hours(null, '09:00', '18:00');
        $this->hours('2026-10-02', '09:00', '10:00');
        $availability = app(PhotographerAvailabilityService::class);
        foreach ([false, true] as $hybrid) {
            config(['availability.hybrid_travel_enabled' => $hybrid]);
            $this->postJson('/api/photographer/availability/check', [
                'photographer_id' => $this->photographer->id, 'date' => '2026-10-02',
            ])->assertOk()->assertJsonPath('data.0.start_time', '09:00')->assertJsonPath('data.0.end_time', '10:00');
            $this->assertFalse($this->bookingRead('15:00')['is_available_at_time']);
            $outside = Carbon::parse('2026-10-02 15:00', 'America/New_York');
            $this->assertFalse($availability->isAvailable($this->photographer->id, $outside, 15, null, 'America/New_York'));
            try {
                $availability->assertWithinAvailabilityBounds($this->photographer->id, $outside, 15, null, true, 'America/New_York');
                $this->fail('A recurring window must not widen the explicit date override.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('start_time', $exception->errors());
            }
            $inside = Carbon::parse('2026-10-02 09:45', 'America/New_York');
            $availability->assertWithinAvailabilityBounds($this->photographer->id, $inside, 15, null, true, 'America/New_York');
            $this->assertTrue($availability->isAvailable($this->photographer->id, $inside, 15, null, 'America/New_York'));
        }
    }

    public function test_dated_later_hours_replace_rather_than_merge_the_weekly_window(): void
    {
        $this->hours(null, '09:00', '10:00');
        $this->hours('2026-10-02', '17:00', '18:00');
        $availability = app(PhotographerAvailabilityService::class);
        $this->assertFalse($this->bookingRead('09:00')['is_available_at_time']);
        $this->assertTrue($this->bookingRead('17:00')['is_available_at_time']);
        $this->assertFalse($availability->isAvailable($this->photographer->id, Carbon::parse('2026-10-02 09:00', 'America/New_York'), 15, null, 'America/New_York'));
        $this->assertTrue($availability->isAvailable($this->photographer->id, Carbon::parse('2026-10-02 17:00', 'America/New_York'), 15, null, 'America/New_York'));
    }

    public function test_read_slots_keep_one_fifteen_minute_fixed_gap_and_no_fixed_gap_in_hybrid_mode(): void
    {
        $this->hours(null, '09:00', '18:00');
        $shoot = Shoot::factory()->create(['photographer_id' => $this->photographer->id,
            'status' => 'scheduled', 'timezone' => 'America/New_York', 'scheduled_at' => '2026-10-02 13:00:00']);
        $service = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 15]);
        $shoot->services()->attach($service->id, ['photographer_id' => $this->photographer->id,
            'duration_minutes' => 15, 'scheduled_at' => '2026-10-02 13:00:00', 'workflow_status' => 'scheduled']);
        foreach ([false => '09:30', true => '09:15'] as $hybrid => $expectedStart) {
            config(['availability.hybrid_travel_enabled' => (bool) $hybrid]);
            $this->postJson('/api/photographer/availability/check', [
                'photographer_id' => $this->photographer->id, 'date' => '2026-10-02',
            ])->assertOk()->assertJsonPath('data.1.start_time', $expectedStart);
            $read = $this->bookingRead('09:15');
            $this->assertSame($expectedStart, $read['net_available_slots'][0]['start_time']);
            $this->assertSame((bool) $hybrid, $read['is_available_at_time']);
        }
    }

    public function test_cached_net_slots_refresh_after_reschedule_cancellation_and_hours_changes_without_timestamp_changes(): void
    {
        config(['availability.hybrid_travel_enabled' => false]);
        $this->hours(null, '09:00', '18:00');
        $shoot = Shoot::factory()->create(['photographer_id' => $this->photographer->id,
            'status' => 'scheduled', 'timezone' => 'America/New_York', 'scheduled_at' => '2026-10-02 13:00:00']);
        $service = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 15]);
        $shoot->services()->attach($service->id, ['photographer_id' => $this->photographer->id,
            'duration_minutes' => 15, 'scheduled_at' => '2026-10-02 13:00:00', 'workflow_status' => 'scheduled']);
        $availability = app(PhotographerAvailabilityService::class);
        $day = Carbon::parse('2026-10-02');
        $slots = fn () => $availability->getAvailableSlots($this->photographer->id, $day, $day)['2026-10-02'];
        $this->assertSame([['start' => '09:30', 'end' => '18:00']], $slots());

        // Deliberately keep updated_at unchanged: timestamp-only versioning misses
        // quick writes and supported direct database schedule mutations.
        DB::table('shoots')->where('id', $shoot->id)->update(['scheduled_at' => '2026-10-02 15:00:00']);
        DB::table('shoot_service')->where('shoot_id', $shoot->id)->update(['scheduled_at' => '2026-10-02 15:00:00']);
        $this->assertSame([['start' => '09:00', 'end' => '10:45'], ['start' => '11:30', 'end' => '18:00']], $slots());
        DB::table('shoots')->where('id', $shoot->id)->update(['status' => Shoot::STATUS_CANCELLED]);
        $this->assertSame([['start' => '09:00', 'end' => '18:00']], $slots());
        DB::table('photographer_availabilities')->where('photographer_id', $this->photographer->id)->update(['end_time' => '16:00']);
        $this->assertSame([['start' => '09:00', 'end' => '16:00']], $slots());
    }
}
