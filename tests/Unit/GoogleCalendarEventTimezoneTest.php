<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleCalendarEventTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'UTC',
            'availability.default_shoot_duration_minutes' => 120,
        ]);
    }

    public static function localSchedules(): array
    {
        return [
            'winter' => ['2026-01-15 14:00:00', '2026-01-15T14:00:00-05:00', '2026-01-15T16:00:00-05:00'],
            'summer' => ['2026-09-10 14:00:00', '2026-09-10T14:00:00-04:00', '2026-09-10T16:00:00-04:00'],
            'spring DST transition day' => ['2026-03-08 03:30:00', '2026-03-08T03:30:00-04:00', '2026-03-08T05:30:00-04:00'],
            'autumn DST transition day' => ['2026-11-01 03:30:00', '2026-11-01T03:30:00-05:00', '2026-11-01T05:30:00-05:00'],
            'start near midnight' => ['2026-09-10 00:30:00', '2026-09-10T00:30:00-04:00', '2026-09-10T02:30:00-04:00'],
            'end on following day' => ['2026-01-15 23:30:00', '2026-01-15T23:30:00-05:00', '2026-01-16T01:30:00-05:00'],
        ];
    }

    #[DataProvider('localSchedules')]
    public function test_legacy_bookings_keep_the_booked_clock_and_calendar_day(
        string $scheduledAt,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        $shoot = $this->shoot($scheduledAt);
        $item = $this->serviceItem($scheduledAt);
        $photographer = new User(['timezone' => 'America/New_York']);
        $shootAttributes = $shoot->getAttributes();
        $itemAttributes = $item->getAttributes();
        $builder = $this->builder();

        $this->assertTiming($builder->build($shoot, $photographer), $expectedStart, $expectedEnd);
        $this->assertTiming($builder->buildForServiceItem($shoot, $item, $photographer), $expectedStart, $expectedEnd);

        $this->assertSame($shootAttributes, $shoot->getAttributes());
        $this->assertSame($itemAttributes, $item->getAttributes());
        $this->assertFalse($shoot->isDirty());
        $this->assertFalse($item->isDirty());
    }

    public function test_service_events_use_their_own_time_or_the_shoot_fallback_without_a_shift(): void
    {
        $shoot = $this->shoot('2026-09-10 14:00:00');
        $shoot->timezone = ' ';
        $photographer = new User(['timezone' => 'America/New_York']);
        $fallbackItem = $this->serviceItem(null);
        $ownScheduleItem = $this->serviceItem('2026-09-10 16:30:00');
        $builder = $this->builder();

        $this->assertTiming(
            $builder->buildForServiceItem($shoot, $fallbackItem, $photographer),
            '2026-09-10T14:00:00-04:00',
            '2026-09-10T16:00:00-04:00',
        );
        $this->assertTiming(
            $builder->buildForServiceItem($shoot, $ownScheduleItem, $photographer),
            '2026-09-10T16:30:00-04:00',
            '2026-09-10T18:30:00-04:00',
        );
    }

    public function test_service_timing_description_uses_the_same_local_clock_as_events(): void
    {
        $shoot = $this->shoot('2026-09-10 14:00:00');
        $fallbackItem = $this->serviceItem(null, 'Photography');
        $laterItem = $this->serviceItem('2026-09-10 16:30:00', 'Video');
        $shoot->setRelation('serviceItems', new Collection([$fallbackItem, $laterItem]));

        $payload = $this->builder()->build($shoot, new User(['timezone' => 'America/New_York']));

        $this->assertStringContainsString(
            "Service Timing:\n- Photography: Thu, Sep 10 2026 2:00 PM\n- Video: Thu, Sep 10 2026 4:30 PM",
            $payload['description'],
        );
    }

    public static function explicitTimezones(): array
    {
        return [
            'shoot zone' => ['America/New_York'],
            'explicit UTC' => ['UTC'],
        ];
    }

    #[DataProvider('explicitTimezones')]
    public function test_explicit_shoot_timezones_preserve_absolute_instants(string $shootTimezone): void
    {
        $shoot = $this->shoot('2026-09-10 18:00:00', $shootTimezone);
        $fallbackItem = $this->serviceItem(null, 'Photography');
        $laterItem = $this->serviceItem('2026-09-10 20:30:00', 'Video');
        $shoot->setRelation('serviceItems', new Collection([$fallbackItem, $laterItem]));
        $photographer = new User(['timezone' => 'America/New_York']);
        $shootAttributes = $shoot->getAttributes();
        $itemAttributes = $laterItem->getAttributes();
        $builder = $this->builder();

        $payload = $builder->build($shoot, $photographer);
        $this->assertTiming($payload, '2026-09-10T14:00:00-04:00', '2026-09-10T16:00:00-04:00');
        $this->assertTiming(
            $builder->buildForServiceItem($shoot, $fallbackItem, $photographer),
            '2026-09-10T14:00:00-04:00',
            '2026-09-10T16:00:00-04:00',
        );
        $this->assertTiming(
            $builder->buildForServiceItem($shoot, $laterItem, $photographer),
            '2026-09-10T16:30:00-04:00',
            '2026-09-10T18:30:00-04:00',
        );
        $this->assertStringContainsString(
            "Service Timing:\n- Photography: Thu, Sep 10 2026 2:00 PM\n- Video: Thu, Sep 10 2026 4:30 PM",
            $payload['description'],
        );
        $this->assertSame($shootAttributes, $shoot->getAttributes());
        $this->assertSame($itemAttributes, $laterItem->getAttributes());
        $this->assertFalse($shoot->isDirty());
        $this->assertFalse($laterItem->isDirty());
    }

    public function test_calendar_timezone_falls_back_to_the_shoot_then_application_timezone(): void
    {
        $builder = $this->builder();
        $shoot = $this->shoot('2026-09-10 18:00:00', 'America/New_York');
        $item = $this->serviceItem(null);
        $photographer = new User(['timezone' => ' ']);

        $this->assertTiming($builder->build($shoot, $photographer), '2026-09-10T14:00:00-04:00', '2026-09-10T16:00:00-04:00');
        $this->assertTiming($builder->buildForServiceItem($shoot, $item), '2026-09-10T14:00:00-04:00', '2026-09-10T16:00:00-04:00');

        $legacyShoot = $this->shoot('2026-09-10 14:00:00');
        $this->assertTiming($builder->build($legacyShoot), '2026-09-10T14:00:00+00:00', '2026-09-10T16:00:00+00:00', 'UTC');
        $this->assertTiming($builder->buildForServiceItem($legacyShoot, $item), '2026-09-10T14:00:00+00:00', '2026-09-10T16:00:00+00:00', 'UTC');
    }

    private function builder(): GoogleCalendarEventPayloadBuilder
    {
        $support = $this->createMock(ShootMutationSupportService::class);
        $support->method('calculateShootDurationFromShoot')->willReturn(120);
        $support->method('formatFullAddress')->willReturn('123 Main St, Baltimore, MD');

        return new GoogleCalendarEventPayloadBuilder($support);
    }

    private function shoot(string $scheduledAt, ?string $timezone = null): Shoot
    {
        $shoot = new Shoot([
            'scheduled_at' => $scheduledAt,
            'timezone' => $timezone,
            'status' => Shoot::STATUS_SCHEDULED,
        ]);
        $shoot->setRelation('services', new Collection());
        $shoot->setRelation('serviceItems', new Collection());
        $shoot->setRelation('client', new User(['name' => 'Test Client']));
        $shoot->syncOriginal();

        return $shoot;
    }

    private function serviceItem(?string $scheduledAt, string $name = 'Photography'): ShootService
    {
        $item = new ShootService(['scheduled_at' => $scheduledAt]);
        $service = new Service(['name' => $name]);
        $service->setAttribute('shoot_duration_minutes', 120);
        $item->setRelation('service', $service);
        $item->syncOriginal();

        return $item;
    }

    private function assertTiming(array $payload, string $start, string $end, string $timezone = 'America/New_York'): void
    {
        $this->assertSame(['dateTime' => $start, 'timeZone' => $timezone], $payload['start']);
        $this->assertSame(['dateTime' => $end, 'timeZone' => $timezone], $payload['end']);
    }
}
