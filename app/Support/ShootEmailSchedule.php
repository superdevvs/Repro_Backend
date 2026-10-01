<?php

namespace App\Support;

use Carbon\Carbon;

class ShootEmailSchedule
{
    /**
     * Format a service/shoot appointment for client/photographer emails.
     *
     * Storage policy (see ScheduleInstantResolver / ShootDateService):
     * - When the shoot has an explicit IANA timezone, scheduled_at is an absolute
     *   UTC instant and must be converted before display.
     * - When timezone is empty (legacy), scheduled_at is a local wall-clock value
     *   and should be formatted as stored.
     */
    public static function format(mixed $value, ?string $timezone = null): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)->copy()
                : Carbon::parse((string) $value);

            $tz = trim((string) $timezone);
            if ($tz !== '') {
                $date = $date->setTimezone($tz);
            }

            return $date->format('M j, Y \a\t g:i A');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function summarize(
        iterable $appointments,
        ?string $fallbackDate,
        ?string $fallbackTime,
        ?string $timezone = null
    ): array {
        $dates = [];
        $times = [];
        foreach ($appointments as $appointment) {
            if (self::format($appointment, $timezone) === null) {
                $dates[] = $fallbackDate ?: 'TBD';
                $times[] = $fallbackTime ?: 'TBD';

                continue;
            }

            $date = $appointment instanceof \DateTimeInterface
                ? Carbon::instance($appointment)->copy()
                : Carbon::parse((string) $appointment);

            $tz = trim((string) $timezone);
            if ($tz !== '') {
                $date = $date->setTimezone($tz);
            }

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
