<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Schedule\ScheduleDateScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootScheduleUpdateTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public static function localSchedules(): array
    {
        return [
            ['2026-09-23', '10:00:00', 'America/New_York', '2026-09-23 14:00:00'],
            ['2026-12-23', '10:00:00', 'America/New_York', '2026-12-23 15:00:00'],
            ['2026-09-23', '01:00:00', 'Asia/Kolkata', '2026-09-22 19:30:00'],
            ['2026-09-23', '10:00:00', null, '2026-09-23 10:00:00'],
        ];
    }

    #[DataProvider('localSchedules')]
    public function test_overview_client_edit_preserves_local_schedule_and_storage_convention(
        string $date, string $time, ?string $timezone, string $stored
    ): void {
        $shoot = $this->shoot($stored, $timezone);
        $client = User::factory()->create(['role' => 'client']);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'client_id' => $client->id, 'scheduled_date' => $date, 'time' => $time,
            'city' => null, 'zip' => null, 'notify_client' => false,
        ])->assertOk();
        $shoot->refresh();
        $this->assertSame($stored, $shoot->getRawOriginal('scheduled_at'));
        $this->assertSame($date, $shoot->scheduled_date->toDateString());
        $this->assertSame($time, $shoot->time);
        $this->assertSame($timezone, $shoot->timezone);
        $this->assertSame($client->id, $shoot->client_id);
    }

    public static function explicitInstants(): array
    {
        return [
            ['2026-09-23T10:00:00-04:00', '2026-09-23 14:00:00', '10:00:00'],
            ['2026-09-23T14:00:00Z', '2026-09-23 14:00:00', '10:00:00'],
            ['2026-09-23T11:00:00', '2026-09-23 15:00:00', '11:00:00'],
        ];
    }

    #[DataProvider('explicitInstants')]
    public function test_explicit_offsets_and_local_offsetless_timestamps_are_not_reinterpreted(
        string $input, string $stored, string $localTime
    ): void {
        $shoot = $this->shoot();
        $this->patchJson('/api/shoots/'.$shoot->id, ['scheduled_at' => $input, 'notify_client' => false])->assertOk();
        $shoot->refresh();
        $this->assertSame($stored, $shoot->getRawOriginal('scheduled_at'));
        $this->assertSame($localTime, $shoot->time);
        $this->assertSame('America/New_York', $shoot->timezone);
    }

    public function test_date_only_edit_reuses_the_existing_local_time_when_legacy_time_column_is_empty(): void
    {
        $shoot = $this->shoot();
        $this->patchJson('/api/shoots/'.$shoot->id, ['scheduled_date' => '2026-09-24'])->assertOk();
        $this->assertSame('2026-09-24 14:00:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
    }

    public function test_client_timezone_only_edit_preserves_instant_and_displays_the_new_local_time(): void
    {
        $shoot = $this->shoot();
        Sanctum::actingAs($shoot->client);
        $this->patchJson('/api/shoots/'.$shoot->id, ['timezone' => 'America/Los_Angeles'])->assertOk();
        $this->assertSame('2026-09-23 14:00:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
        $this->assertSame('America/Los_Angeles', $shoot->fresh()->timezone);
        $this->assertSame('07:00', app(ScheduleDateScopeService::class)->localTimeForScheduledAt(
            $shoot->fresh()->scheduled_at, $shoot->fresh()->timezone
        ));
    }

    public function test_new_nonexistent_or_ambiguous_dst_wall_times_reject_without_mutation(): void
    {
        $shoot = $this->shoot();
        foreach (['2027-03-14T02:30:00', '2026-11-01T01:30:00'] as $time) {
            $this->patchJson('/api/shoots/'.$shoot->id, ['scheduled_at' => $time])->assertUnprocessable()
                ->assertJsonValidationErrors('scheduled_at');
            $this->assertSame('2026-09-23 14:00:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
        }
    }

    public function test_unrelated_edit_retains_the_original_second_dst_occurrence(): void
    {
        $shoot = $this->shoot('2026-11-01 06:30:00');
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'scheduled_date' => '2026-11-01', 'time' => '01:30:00', 'city' => 'Updated City',
        ])->assertOk();
        $this->assertSame('2026-11-01 06:30:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
    }

    public function test_availability_bounds_use_local_clock_instead_of_stored_utc_hour(): void
    {
        $shoot = $this->shoot();
        $photographer = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        $shoot->update(['photographer_id' => $photographer->id]);
        PhotographerAvailability::create([
            'photographer_id' => $photographer->id, 'date' => '2026-09-23',
            'day_of_week' => 'wednesday', 'start_time' => '09:00', 'end_time' => '13:00', 'status' => 'available',
        ]);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'scheduled_date' => '2026-09-23', 'time' => '10:00:00', 'notify_client' => false,
        ])->assertOk();
        $this->assertSame('2026-09-23 14:00:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
        $this->patchJson('/api/shoots/'.$shoot->id, ['scheduled_date' => '2026-09-23', 'time' => '14:00:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->assertSame('2026-09-23 14:00:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
    }

    public static function serviceInstants(): array
    {
        return [['2026-09-23T10:00:00-04:00'], ['2026-09-23T14:00:00.000Z']];
    }

    #[DataProvider('serviceInstants')]
    public function test_service_offsets_survive_normalization_and_local_availability_checks(string $timestamp): void
    {
        $shoot = $this->shoot();
        $photographer = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        $service = Service::factory()->create(['price' => 100, 'photographer_required' => true]);
        $shoot->services()->attach($service->id, [
            'price' => 100, 'quantity' => 1, 'photographer_id' => $photographer->id,
            'scheduled_at' => '2026-09-23 14:00:00',
        ]);
        PhotographerAvailability::create([
            'photographer_id' => $photographer->id, 'date' => '2026-09-23',
            'day_of_week' => 'wednesday', 'start_time' => '09:00', 'end_time' => '13:00', 'status' => 'available',
        ]);
        $item = ['service_id' => $service->id, 'photographer_id' => $photographer->id, 'scheduled_at' => $timestamp];
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'scheduled_date' => '2026-09-23', 'time' => '10:00:00',
            'services' => [array_merge($item, ['id' => $service->id])],
            'service_items' => [$item], 'skip_availability_check' => false, 'notify_client' => false,
        ])->assertOk();
        $this->assertSame('2026-09-23 14:00:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
        $this->assertSame('2026-09-23 14:00:00', $shoot->serviceItems()->where('service_id', $service->id)->firstOrFail()->getRawOriginal('scheduled_at'));
    }

    private function shoot(string $stored = '2026-09-23 14:00:00', ?string $timezone = 'America/New_York'): Shoot
    {
        return Shoot::factory()->create([
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD, 'photographer_id' => null,
            'scheduled_at' => $stored, 'scheduled_date' => substr($stored, 0, 10), 'time' => null,
            'timezone' => $timezone, 'status' => 'scheduled', 'workflow_status' => 'scheduled',
        ]);
    }
}
