<?php

namespace App\Services\Schedule;

use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use Carbon\Carbon;

/** Resolve appointment instants without rewriting the legacy local-clock storage. */
class ScheduleInstantResolver
{
    public function forShoot(Shoot $shoot): ?Carbon
    {
        return $this->resolve($shoot, $shoot->scheduled_at, $shoot->photographer);
    }

    public function timezoneForShoot(Shoot $shoot): string
    {
        return $this->timezone($shoot, $shoot->photographer);
    }

    public function forServiceItem(Shoot $shoot, ShootService $serviceItem): ?Carbon
    {
        return $this->resolve(
            $shoot,
            $serviceItem->scheduled_at ?: $shoot->scheduled_at,
            $serviceItem->photographer ?: $shoot->photographer,
        );
    }

    public function isWithinCancellationFeeWindow(Shoot $shoot): bool
    {
        if (! $shoot->scheduled_at && (! $shoot->scheduled_date || ! $shoot->time)) {
            return false;
        }

        return $this->isWithinCancellationFeeWindowAt($this->forShoot($shoot));
    }

    public function isWithinCancellationFeeWindowAt(?Carbon $scheduledAt): bool
    {
        if (! $scheduledAt) {
            return false;
        }

        $minutesUntilShoot = now($scheduledAt->timezone)->diffInMinutes($scheduledAt, false);

        return $minutesUntilShoot >= 0 && $minutesUntilShoot <= 240;
    }

    private function resolve(Shoot $shoot, ?\DateTimeInterface $scheduledAt, ?User $photographer): ?Carbon
    {
        $timezone = $this->timezone($shoot, $photographer);

        if ($scheduledAt) {
            $date = Carbon::instance($scheduledAt)->copy();

            // This is the existing Google Calendar boundary policy: a shoot
            // with no timezone stores the selected local clock, despite its UTC
            // Eloquent cast. Explicitly zoned shoots store an absolute instant.
            return trim((string) $shoot->timezone) === ''
                ? $date->shiftTimezone($timezone)
                : $date->setTimezone($timezone);
        }

        if (! $shoot->scheduled_date) {
            return null;
        }

        try {
            return Carbon::parse(
                $shoot->scheduled_date->format('Y-m-d').' '.($shoot->time ?: '00:00'),
                $timezone,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function timezone(Shoot $shoot, ?User $photographer): string
    {
        foreach ([$photographer?->timezone, $shoot->timezone, config('app.timezone', 'UTC')] as $timezone) {
            $timezone = trim((string) $timezone);
            if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
                return $timezone;
            }
        }

        return 'UTC';
    }
}
