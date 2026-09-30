<?php

namespace Tests\Unit\Services\Shoots;

use App\Models\Shoot;
use App\Services\Shoots\MultiUnitRescheduleService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MultiUnitRescheduleWallClockTest extends TestCase
{
    public static function wallClocks(): array
    {
        return [
            'EDT 10:00 -> 14:00Z' => ['2026-10-08', '10:00', 'America/New_York', '2026-10-08T14:00:00+00:00', '10:00'],
            'EST 10:00 -> 15:00Z' => ['2026-01-15', '10:00', 'America/New_York', '2026-01-15T15:00:00+00:00', '10:00'],
            'PM string EDT' => ['2026-10-08', '02:30 PM', 'America/New_York', '2026-10-08T18:30:00+00:00', '14:30'],
            'legacy unzoned keeps local clock' => ['2026-10-08', '10:00', null, '2026-10-08T10:00:00+00:00', '10:00'],
            'blank timezone treated as unzoned' => ['2026-10-08', '10:00', ' ', '2026-10-08T10:00:00+00:00', '10:00'],
            'Carbon date input' => [Carbon::parse('2026-10-08'), '10:00', 'America/New_York', '2026-10-08T14:00:00+00:00', '10:00'],
            'string date input' => ['2026-10-08', '10:00', 'America/New_York', '2026-10-08T14:00:00+00:00', '10:00'],
        ];
    }

    #[DataProvider('wallClocks')]
    public function test_resolve_requested_wall_clock_matches_multi_unit_storage_convention(
        mixed $date, string $time, ?string $timezone, string $expectedUtcIso, string $expectedLocalTime,
    ): void {
        config(['app.timezone' => 'UTC']);
        $shoot = new Shoot(['timezone' => $timezone, 'time' => '09:00']);
        $resolved = app(MultiUnitRescheduleService::class)
            ->resolveRequestedWallClock($shoot, $date, $time);

        $this->assertSame($expectedUtcIso, $resolved['storage']->utc()->toIso8601String());
        $this->assertSame($expectedLocalTime, $resolved['time']);
        $dateString = $date instanceof Carbon ? $date->toDateString() : (string) $date;
        $this->assertSame($dateString, $resolved['scheduled_date']);

        if (trim((string) $timezone) !== '') {
            $this->assertTrue($resolved['has_timezone']);
            $this->assertSame($expectedUtcIso, Carbon::parse($resolved['scheduled_at'])->utc()->toIso8601String());
        } else {
            $this->assertFalse($resolved['has_timezone']);
            $this->assertSame('2026-10-08 10:00:00', $resolved['scheduled_at']);
        }
    }

    public function test_falls_back_to_shoot_time_then_default_ten(): void
    {
        config(['app.timezone' => 'UTC']);
        $shoot = new Shoot(['timezone' => 'America/New_York', 'time' => '11:15']);
        $service = app(MultiUnitRescheduleService::class);

        $fromShoot = $service->resolveRequestedWallClock($shoot, '2026-10-08', null);
        $this->assertSame('2026-10-08T15:15:00+00:00', $fromShoot['storage']->utc()->toIso8601String());

        $shoot->time = null;
        $fromDefault = $service->resolveRequestedWallClock($shoot, '2026-10-08', null);
        $this->assertSame('2026-10-08T14:00:00+00:00', $fromDefault['storage']->utc()->toIso8601String());
    }
}
