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
            'availability.min_shoot_duration_minutes' => 5,
            'availability.max_shoot_duration_minutes' => 300,
        ]);
    }

    public function test_default_calendar_event_is_one_hour_and_ignores_delivery_turnaround(): void
    {
        [$shoot, $service] = $this->shoot();
        $service->update(['delivery_time' => 48]);
        $payload = app(GoogleCalendarEventPayloadBuilder::class)->build($shoot->fresh(), $shoot->photographer);

        $this->assertSame('2026-10-01T12:30:00-04:00', $payload['start']['dateTime']);
        $this->assertSame('2026-10-01T13:30:00-04:00', $payload['end']['dateTime']);
        $this->assertSame(['default_minutes' => 60, 'min_minutes' => 5, 'max_minutes' => 300], $service->booking_duration_defaults);
    }

    public static function longerDurations(): array
    {
        return ['five minutes' => [5], 'custom seventeen minutes' => [17], 'fifteen minutes' => [15], 'explicit half hour' => [30], 'one hour' => [60], 'ninety minutes' => [90]];
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
        $this->assertSame($minutes, (int) Carbon::parse($whole['start']['dateTime'])->diffInMinutes(Carbon::parse($whole['end']['dateTime'])));
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
        $this->assertSame('2026-10-01T13:30:00-04:00', $builder->buildForServiceItem($shoot, $item->fresh(), $shoot->photographer)['end']['dateTime']);

        $item->update(['duration_minutes' => 90]);
        $range->update(['duration' => 30]);
        $this->assertSame(90, $resolver->forServiceItem($item->fresh()));
        $this->assertSame(90, $resolver->forShoot($shoot->fresh()));
        $this->assertSame('2026-10-01T14:00:00-04:00', $builder->build($shoot->fresh(), $shoot->photographer)['end']['dateTime']);
    }

    public function test_same_start_services_sum_and_staggered_same_day_visits_retain_their_times(): void
    {
        [$shoot, $first] = $this->shoot(30);
        $second = Service::factory()->create();
        $shoot->services()->attach($second->id, [
            'duration_minutes' => 60, 'scheduled_at' => '2026-10-01 16:30:00',
            'photographer_id' => $shoot->photographer_id, 'workflow_status' => 'scheduled',
        ]);
        $resolver = app(ShootDurationResolver::class);
        $this->assertSame(90, $resolver->forShoot($shoot->fresh()));
        $this->assertSame(90, $resolver->forServices([
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
        $this->assertSame('2026-10-01T13:30:00-04:00', $payload['end']['dateTime']);
    }

    public function test_actual_duration_and_one_fifteen_minute_buffer_define_both_boundaries(): void
    {
        config(['availability.buffer_time_minutes' => 15]);
        [$shoot] = $this->shoot(15);
        $availability = app(PhotographerAvailabilityService::class);
        $this->assertFalse($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T12:59:00-04:00'), 15, null, 'America/New_York'));
        $this->assertTrue($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T13:00:00-04:00'), 15, null, 'America/New_York'));
        // A 15-minute request ending at 12:15 has precisely one gap before 12:30.
        $this->assertTrue($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T12:00:00-04:00'), 15, null, 'America/New_York'));
        $this->assertFalse($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T12:01:00-04:00'), 15, null, 'America/New_York'));
        $shoot->serviceItems()->update(['duration_minutes' => 90]);
        $this->assertFalse($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T14:14:00-04:00'), 15, null, 'America/New_York'));
        $this->assertTrue($availability->isAvailable($shoot->photographer_id, Carbon::parse('2026-10-01T14:15:00-04:00'), 15, null, 'America/New_York'));
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
        $this->assertSame('2026-10-01T13:00:00-04:00', $primary['end']['dateTime']);
        $this->assertSame('2026-10-01T15:00:00-04:00', $other['start']['dateTime']);
        $this->assertSame('2026-10-01T16:30:00-04:00', $other['end']['dateTime']);
        $this->assertSame(30, $resolver->forServices([
            ['id' => $primaryService->id, 'duration_minutes' => 30, 'photographer_id' => $shoot->photographer_id],
            ['id' => $secondaryService->id, 'duration_minutes' => 90, 'photographer_id' => $secondary->id],
        ], null, null, null, $shoot->photographer_id));

        $booked = app(PhotographerAvailabilityService::class)->getBookedSlots($shoot->photographer_id, Carbon::parse('2026-10-01'));
        $this->assertNotEmpty($booked);
        foreach ($booked as $slot) {
            $this->assertSame('12:30', $slot['start_time']);
            $this->assertSame('13:00', $slot['end_time']);
        }
        $unassignedViewer = User::factory()->create(['role' => 'client', 'timezone' => 'America/New_York']);
        $this->assertSame($primary['end'], $builder->build($shoot, $unassignedViewer)['end']);
    }

    public function test_separate_same_day_visits_do_not_block_the_gap(): void
    {
        config(['availability.buffer_time_minutes' => 15]);
        [$shoot] = $this->shoot(15);
        $other = Service::factory()->create();
        $shoot->services()->attach($other->id, ['duration_minutes' => 15,
            'scheduled_at' => '2026-10-01 19:00:00', 'photographer_id' => $shoot->photographer_id,
            'workflow_status' => 'scheduled']);
        $availability = app(PhotographerAvailabilityService::class);
        $booked = $availability->getBookedSlots($shoot->photographer_id, Carbon::parse('2026-10-01'));
        $this->assertCount(2, $booked);
        $this->assertSame(['12:45', '15:15'], array_column($booked, 'end_time'));
        $this->assertTrue($availability->isAvailable($shoot->photographer_id,
            Carbon::parse('2026-10-01T13:00:00-04:00'), 60, null, 'America/New_York'));
    }

    public function test_bundle_is_one_duration_and_additional_capture_adds_time_without_quantity_or_aggregate_cap(): void
    {
        $bundle = Service::factory()->create(['shoot_duration_minutes' => 270]);
        $extra = Service::factory()->create(['shoot_duration_minutes' => 60]);
        $digital = Service::factory()->create(['photographer_required' => false]);
        $resolver = app(ShootDurationResolver::class);
        $this->assertSame(330, $resolver->forServices([
            ['id' => $bundle->id, 'duration_minutes' => 270, 'quantity' => 3],
            ['id' => $extra->id, 'duration_minutes' => 60],
            ['id' => $digital->id, 'duration_minutes' => 0, 'quantity' => 10],
        ]));
        $this->assertSame(0, $resolver->forServices([['id' => $digital->id, 'duration_minutes' => 0]]));
    }

    public function test_secondary_photographer_same_start_work_sums_without_using_the_lead_window(): void
    {
        config(['availability.buffer_time_minutes' => 15]);
        [$shoot] = $this->shoot(15);
        $secondary = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        foreach ([15, 30] as $minutes) {
            $service = Service::factory()->create();
            $shoot->services()->attach($service->id, ['duration_minutes' => $minutes,
                'scheduled_at' => '2026-10-01 19:00:00', 'photographer_id' => $secondary->id,
                'workflow_status' => 'scheduled']);
        }
        $availability = app(PhotographerAvailabilityService::class);
        $booked = $availability->getBookedSlots($secondary->id, Carbon::parse('2026-10-01'));
        $this->assertCount(1, $booked);
        $this->assertSame('15:00', $booked[0]['start_time']);
        $this->assertSame('15:45', $booked[0]['end_time']);
        $this->assertFalse($availability->isAvailable($secondary->id, Carbon::parse('2026-10-01T15:59:00-04:00'), 5, null, 'America/New_York'));
        $this->assertTrue($availability->isAvailable($secondary->id, Carbon::parse('2026-10-01T16:00:00-04:00'), 5, null, 'America/New_York'));
    }

    public function test_distinct_start_overlaps_inside_single_property_request_are_rejected_even_with_external_override(): void
    {
        [$shoot, $first] = $this->shoot(15);
        $second = Service::factory()->create();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Shoots\ShootMutationSupportService::class)->checkServiceItemPhotographerAvailability([
            ['id' => $first->id, 'duration_minutes' => 30, 'scheduled_at' => '2026-10-01T09:00:00-04:00'],
            ['id' => $second->id, 'duration_minutes' => 15, 'scheduled_at' => '2026-10-01T09:15:00-04:00'],
        ], $shoot->photographer_id, $shoot->id, 'America/New_York', true);
    }

    public function test_actual_work_can_end_at_closing_but_not_one_minute_after_and_buffer_is_not_added_to_hours(): void
    {
        config(['availability.buffer_time_minutes' => 15, 'availability.fallback_end_time' => '18:00']);
        [$shoot] = $this->shoot(15);
        $availability = app(PhotographerAvailabilityService::class);
        $availability->assertWithinAvailabilityBounds($shoot->photographer_id,
            Carbon::parse('2026-10-01T17:45:00-04:00'), 15, $shoot->id, false, 'America/New_York');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $availability->assertWithinAvailabilityBounds($shoot->photographer_id,
            Carbon::parse('2026-10-01T17:45:00-04:00'), 16, $shoot->id, true, 'America/New_York');
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
