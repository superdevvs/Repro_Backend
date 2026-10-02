<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\PhotographerAvailabilityService;
use App\Services\Shoots\ShootDurationResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootAppointmentDurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.timezone' => 'UTC',
            'availability.default_shoot_duration_minutes' => 60,
            'availability.booked_block_duration_minutes' => 120,
            'availability.min_shoot_duration_minutes' => 30,
        ]);
    }

    public function test_default_calendar_event_is_two_hours_and_ignores_delivery_turnaround(): void
    {
        [$shoot, $service] = $this->shoot();
        $service->update(['delivery_time' => 48]);
        $payload = app(GoogleCalendarEventPayloadBuilder::class)->build($shoot->fresh(), $shoot->photographer);

        $this->assertSame('2026-10-01T12:30:00-04:00', $payload['start']['dateTime']);
        $this->assertSame('2026-10-01T14:30:00-04:00', $payload['end']['dateTime']);
        $this->assertSame(['default_minutes' => 60, 'min_minutes' => 30, 'max_minutes' => 240], $service->booking_duration_defaults);
    }

    public static function longerDurations(): array
    {
        return ['explicit half hour' => [30], 'one hour' => [60], 'ninety minutes' => [90]];
    }

    #[DataProvider('longerDurations')]
    public function test_booked_snapshot_agrees_across_calendar_and_resolver(int $minutes): void
    {
        [$shoot] = $this->shoot($minutes);
        $item = $shoot->serviceItems()->sole();
        $resolver = app(ShootDurationResolver::class);
        $builder = app(GoogleCalendarEventPayloadBuilder::class);
        $whole = $builder->build($shoot->fresh(), $shoot->photographer);
        $line = $builder->buildForServiceItem($shoot, $item, $shoot->photographer);

        $this->assertSame($minutes, $resolver->forShoot($shoot->fresh()));
        $this->assertSame($minutes, $resolver->forServiceItem($item));
        $this->assertSame($whole['end'], $line['end']);
        // Availability/GCal booked window is always the configured 2h default.
        $this->assertSame(120, (int) Carbon::parse($whole['start']['dateTime'])->diffInMinutes(Carbon::parse($whole['end']['dateTime'])));
    }

    public function test_unit_tier_applies_without_snapshot_and_a_booked_snapshot_survives_tier_edits(): void
    {
        [$shoot, $service] = $this->shoot();
        $service->update(['pricing_type' => 'variable']);
        $range = $service->sqftRanges()->create(['sqft_from' => 1000, 'sqft_to' => 2000, 'price' => 100, 'duration' => 60]);
        $unit = $shoot->units()->create(['client_key' => 'unit-a', 'label' => 'Unit A', 'sqft' => 1500, 'sort_order' => 0]);
        $item = $shoot->serviceItems()->sole();
        $item->update(['shoot_unit_id' => $unit->id]);
        $resolver = app(ShootDurationResolver::class);
        $builder = app(GoogleCalendarEventPayloadBuilder::class);

        $this->assertSame(60, $resolver->forServiceItem($item->fresh()));
        $this->assertSame(60, $resolver->forShoot($shoot->fresh()));
        $this->assertSame('2026-10-01T14:30:00-04:00', $builder->buildForServiceItem($shoot, $item->fresh(), $shoot->photographer)['end']['dateTime']);

        $item->update(['duration_minutes' => 90]);
        $range->update(['duration' => 30]);
        $this->assertSame(90, $resolver->forServiceItem($item->fresh()));
        $this->assertSame(90, $resolver->forShoot($shoot->fresh()));
        // GCal aggregate uses fixed 2h availability window, not the 90-minute snapshot.
        $this->assertSame('2026-10-01T14:30:00-04:00', $builder->build($shoot->fresh(), $shoot->photographer)['end']['dateTime']);
    }

    public function test_simultaneous_services_share_a_window_and_staggered_same_day_visits_extend_it(): void
    {
        [$shoot, $first] = $this->shoot(30);
        $second = Service::factory()->create();
        $shoot->services()->attach($second->id, [
            'duration_minutes' => 60, 'scheduled_at' => '2026-10-01 16:30:00',
            'photographer_id' => $shoot->photographer_id, 'workflow_status' => 'scheduled',
        ]);
        $resolver = app(ShootDurationResolver::class);
        $this->assertSame(60, $resolver->forShoot($shoot->fresh()));
        $this->assertSame(60, $resolver->forServices([
            ['id' => $first->id, 'duration_minutes' => 30],
            ['id' => $second->id, 'duration_minutes' => 60],
        ]));

        $shoot->serviceItems()->where('service_id', $second->id)->update(['scheduled_at' => '2026-10-01 17:30:00', 'duration_minutes' => 30]);
        $this->assertSame(90, $resolver->forShoot($shoot->fresh()));
        $this->assertSame(90, $resolver->forServices([
            ['id' => $first->id, 'duration_minutes' => 30, 'scheduled_at' => '2026-10-01T12:30:00-04:00'],
            ['id' => $second->id, 'duration_minutes' => 30, 'scheduled_at' => '2026-10-01T13:30:00-04:00'],
        ]));

        $shoot->serviceItems()->where('service_id', $second->id)->update(['scheduled_at' => '2026-10-02 17:30:00', 'duration_minutes' => 90]);
        $this->assertSame(30, $resolver->forShoot($shoot->fresh()));
        $this->assertSame(30, $resolver->forServices([
            ['id' => $first->id, 'duration_minutes' => 30, 'scheduled_at' => '2026-10-01T12:30:00-04:00'],
            ['id' => $second->id, 'duration_minutes' => 90, 'scheduled_at' => '2026-10-02T13:30:00-04:00'],
        ]));
    }

    public function test_non_capture_cancelled_and_non_deliverable_lines_cannot_extend_the_window(): void
    {
        [$shoot, $first] = $this->shoot(30);
        $fee = Service::factory()->create(['photographer_required' => false]);
        $cancelled = Service::factory()->create();
        $excluded = Service::factory()->create();
        foreach ([[$fee, 'scheduled', true], [$cancelled, 'cancelled', true], [$excluded, 'scheduled', false]] as [$service, $status, $deliverable]) {
            $shoot->services()->attach($service->id, [
                'duration_minutes' => 240, 'scheduled_at' => '2026-10-01 17:30:00',
                'workflow_status' => $status, 'is_deliverable' => $deliverable,
            ]);
        }
        $resolver = app(ShootDurationResolver::class);
        $this->assertSame(30, $resolver->forShoot($shoot->fresh()));
        $this->assertSame(30, $resolver->forServices([
            ['id' => $first->id, 'duration_minutes' => 30], ['id' => $fee->id, 'duration_minutes' => 240],
            ['id' => $cancelled->id, 'duration_minutes' => 240, 'workflow_status' => 'cancelled'],
            ['id' => $excluded->id, 'duration_minutes' => 240, 'is_deliverable' => false],
        ]));
        $this->assertSame(60, $resolver->forServices([]));
    }

    public function test_calendar_without_aggregate_start_anchors_to_the_first_scheduled_service(): void
    {
        [$shoot] = $this->shoot(60);
        $shoot->scheduled_at = null;
        $shoot->scheduled_date = '2026-10-01';
        $shoot->time = null;
        $this->assertSame(60, app(ShootDurationResolver::class)->forShoot($shoot));
        $payload = app(GoogleCalendarEventPayloadBuilder::class)->build($shoot, $shoot->photographer);
        $this->assertSame('2026-10-01T12:30:00-04:00', $payload['start']['dateTime']);
        $this->assertSame('2026-10-01T14:30:00-04:00', $payload['end']['dateTime']);
    }

    public function test_conflicts_use_fixed_two_hour_block_and_retain_the_thirty_minute_travel_buffer(): void
    {
        config(['availability.buffer_time_minutes' => 30]);
        [$shoot] = $this->shoot(30);
        $availability = app(PhotographerAvailabilityService::class);
        // Shoot at 12:30 with fixed 120m block + 30m buffer occupies through 14:30+buffer.
        $this->assertFalse($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T13:00:00-04:00'), 30, null, 'America/New_York'));
        $this->assertFalse($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T14:00:00-04:00'), 30, null, 'America/New_York'));
        // First free slot after 12:30+120+30 buffer = 15:00
        $this->assertTrue($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T15:00:00-04:00'), 30, null, 'America/New_York'));
        // Changing saved service duration must NOT stretch the availability block.
        $shoot->serviceItems()->update(['duration_minutes' => 90]);
        $this->assertFalse($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T14:00:00-04:00'), 30, null, 'America/New_York'));
        $this->assertTrue($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T15:00:00-04:00'), 30, null, 'America/New_York'));
    }

    public function test_each_photographer_calendar_and_booked_block_uses_only_their_appointments(): void
    {
        [$shoot, $primaryService] = $this->shoot(30);
        $secondary = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $secondaryService = Service::factory()->create();
        $shoot->services()->attach($secondaryService->id, [
            'duration_minutes' => 90, 'scheduled_at' => '2026-10-01 19:00:00',
            'photographer_id' => $secondary->id, 'workflow_status' => 'scheduled',
        ]);
        $nextDayService = Service::factory()->create();
        $shoot->services()->attach($nextDayService->id, [
            'duration_minutes' => 240, 'scheduled_at' => '2026-10-02 19:00:00',
            'photographer_id' => $secondary->id, 'workflow_status' => 'scheduled',
        ]);
        $shoot->refresh();
        $resolver = app(ShootDurationResolver::class);
        $builder = app(GoogleCalendarEventPayloadBuilder::class);
        $primary = $builder->build($shoot, $shoot->photographer);
        $other = $builder->build($shoot, $secondary);
        $this->assertSame(30, $resolver->forShoot($shoot));
        $this->assertSame(90, $resolver->forShoot($shoot, $secondary->id));
        $this->assertSame('2026-10-01T12:30:00-04:00', $primary['start']['dateTime']);
        $this->assertSame('2026-10-01T14:30:00-04:00', $primary['end']['dateTime']);
        $this->assertSame('2026-10-01T15:00:00-04:00', $other['start']['dateTime']);
        $this->assertSame('2026-10-01T17:00:00-04:00', $other['end']['dateTime']);
        $this->assertSame(30, $resolver->forServices([
            ['id' => $primaryService->id, 'duration_minutes' => 30, 'photographer_id' => $shoot->photographer_id],
            ['id' => $secondaryService->id, 'duration_minutes' => 90, 'photographer_id' => $secondary->id],
        ], null, null, null, $shoot->photographer_id));

        $booked = app(PhotographerAvailabilityService::class)->getBookedSlots($shoot->photographer_id, Carbon::parse('2026-10-01'));
        $this->assertNotEmpty($booked);
        foreach ($booked as $slot) {
            $this->assertSame('12:30', $slot['start_time']);
            $this->assertSame('14:30', $slot['end_time']);
        }
        $unassignedViewer = User::factory()->create(['role' => 'client', 'timezone' => 'America/New_York']);
        $this->assertSame($primary['end'], $builder->build($shoot, $unassignedViewer)['end']);
    }

    private function shoot(?int $minutes = null): array
    {
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographer->id, 'scheduled_at' => '2026-10-01 16:30:00',
            'scheduled_date' => '2026-10-01', 'time' => '12:30:00', 'timezone' => 'America/New_York',
            'status' => Shoot::STATUS_SCHEDULED,
        ]);
        $service = Service::factory()->create(['name' => '10 Exterior HDR Photos']);
        $shoot->services()->attach($service->id, [
            'duration_minutes' => $minutes, 'scheduled_at' => '2026-10-01 16:30:00',
            'photographer_id' => $photographer->id, 'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
        ]);

        return [$shoot, $service];
    }
}
