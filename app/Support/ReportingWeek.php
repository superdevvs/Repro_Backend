<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class ReportingWeek
{
    public const START_DAY = Carbon::SUNDAY;

    public const END_DAY = Carbon::SATURDAY;

    /**
     * Return the Sunday-through-Saturday reporting week containing the date.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function containing(CarbonInterface|string $date): array
    {
        $anchor = self::toCarbon($date);
        $start = $anchor->copy()->startOfWeek(self::START_DAY)->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();

        return [$start, $end];
    }

    /**
     * Return the most recent fully completed Sunday-through-Saturday week.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function lastCompleted(CarbonInterface|string|null $reference = null): array
    {
        $anchor = $reference === null ? Carbon::now() : self::toCarbon($reference);
        $currentWeekStart = $anchor->copy()->startOfWeek(self::START_DAY)->startOfDay();

        return [
            $currentWeekStart->copy()->subWeek()->startOfDay(),
            $currentWeekStart->copy()->subDay()->endOfDay(),
        ];
    }

    /**
     * Expand a requested range to complete reporting weeks.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function normalizeRange(
        CarbonInterface|string $start,
        CarbonInterface|string $end
    ): array {
        [$normalizedStart] = self::containing($start);
        [, $normalizedEnd] = self::containing($end);

        if ($normalizedEnd->lt($normalizedStart)) {
            throw new InvalidArgumentException('The reporting period end must not be before its start.');
        }

        return [$normalizedStart, $normalizedEnd];
    }

    private static function toCarbon(CarbonInterface|string $value): Carbon
    {
        return $value instanceof CarbonInterface
            ? Carbon::instance($value)->copy()
            : Carbon::parse($value);
    }
}
