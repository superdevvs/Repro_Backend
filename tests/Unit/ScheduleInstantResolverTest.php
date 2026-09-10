<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Schedule\ScheduleInstantResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleInstantResolverTest extends TestCase
{
    public static function schedules(): array
    {
        return [
            'summer local clock' => ['2026-09-09 10:00:00', null, 'America/New_York', '2026-09-09T14:00:00+00:00'],
            'winter local clock' => ['2026-01-15 10:00:00', null, 'America/New_York', '2026-01-15T15:00:00+00:00'],
            'spring DST day' => ['2026-03-08 03:30:00', null, 'America/New_York', '2026-03-08T07:30:00+00:00'],
            'autumn DST day' => ['2026-11-01 03:30:00', null, 'America/New_York', '2026-11-01T08:30:00+00:00'],
            'UTC day crossing' => ['2026-09-09 23:30:00', null, 'America/New_York', '2026-09-10T03:30:00+00:00'],
            'explicit shoot zone' => ['2026-09-09 14:00:00', 'America/New_York', 'America/Los_Angeles', '2026-09-09T14:00:00+00:00'],
            'explicit UTC' => ['2026-09-09 14:00:00', 'UTC', 'America/New_York', '2026-09-09T14:00:00+00:00'],
            'blank shoot zone' => ['2026-09-09 10:00:00', ' ', 'America/New_York', '2026-09-09T14:00:00+00:00'],
            'application fallback' => ['2026-09-09 10:00:00', null, null, '2026-09-09T10:00:00+00:00'],
        ];
    }

    #[DataProvider('schedules')]
    public function test_resolves_correct_instant_without_changing_stored_schedule(
        string $storedAt, ?string $shootTimezone, ?string $photographerTimezone, string $expectedUtc,
    ): void {
        config(['app.timezone' => 'UTC']);
        $shoot = new Shoot(['scheduled_at' => $storedAt, 'timezone' => $shootTimezone]);
        $shoot->setRelation('photographer', new User(['timezone' => $photographerTimezone]));
        $shoot->syncOriginal();
        $before = $shoot->getAttributes();

        $resolved = app(ScheduleInstantResolver::class)->forShoot($shoot);

        $this->assertSame($expectedUtc, $resolved?->utc()->toIso8601String());
        $this->assertSame($before, $shoot->getAttributes());
        $this->assertFalse($shoot->isDirty());
    }

    public function test_service_photographer_zone_applies_to_own_or_fallback_local_clock(): void
    {
        config(['app.timezone' => 'UTC']);
        $shoot = new Shoot(['scheduled_at' => '2026-09-09 10:00:00', 'timezone' => null]);
        $shoot->setRelation('photographer', new User(['timezone' => 'America/New_York']));
        $item = new ShootService(['scheduled_at' => '2026-09-09 11:00:00']);
        $item->setRelation('photographer', new User(['timezone' => 'America/Los_Angeles']));
        $resolver = app(ScheduleInstantResolver::class);

        $this->assertSame('2026-09-09T18:00:00+00:00', $resolver->forServiceItem($shoot, $item)?->utc()->toIso8601String());
        $item->scheduled_at = null;
        $this->assertSame('2026-09-09T17:00:00+00:00', $resolver->forServiceItem($shoot, $item)?->utc()->toIso8601String());
        $item->setRelation('photographer', null);
        $this->assertSame('2026-09-09T14:00:00+00:00', $resolver->forServiceItem($shoot, $item)?->utc()->toIso8601String());
    }

    public function test_separate_date_and_time_are_local_and_missing_date_remains_unscheduled(): void
    {
        config(['app.timezone' => 'UTC']);
        $shoot = new Shoot(['scheduled_date' => '2026-09-09', 'time' => '23:30', 'timezone' => null]);
        $shoot->setRelation('photographer', new User(['timezone' => 'America/New_York']));
        $resolver = app(ScheduleInstantResolver::class);

        $this->assertSame('2026-09-10T03:30:00+00:00', $resolver->forShoot($shoot)?->utc()->toIso8601String());
        $shoot->scheduled_date = null;
        $this->assertNull($resolver->forShoot($shoot));
    }
}
