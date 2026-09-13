<?php

namespace App\Support;

use Carbon\Carbon;

class ShootEmailSchedule
{
    public static function format(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            // Shoot service appointments are stored as local wall-clock values.
            return Carbon::parse($value)->format('M j, Y \a\t g:i A');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function summarize(iterable $appointments, ?string $fallbackDate, ?string $fallbackTime): array
    {
        $dates = [];
        $times = [];
        foreach ($appointments as $appointment) {
            if (self::format($appointment) === null) {
                $dates[] = $fallbackDate ?: 'TBD';
                $times[] = $fallbackTime ?: 'TBD';

                continue;
            }
            $date = Carbon::parse($appointment);
            $dates[] = $date->format('M j, Y');
            $times[] = $date->format('g:i A');
        }

        $dates = array_values(array_unique($dates));
        $times = array_values(array_unique($times));

        return [
            'date' => count($dates) > 1 ? 'Multiple dates — see service schedules' : ($dates[0] ?? $fallbackDate),
            'time' => count($dates) > 1 || count($times) > 1 ? 'See service schedules' : ($times[0] ?? $fallbackTime),
        ];
    }
}
