<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class MultiUnitRescheduleService
{
    public function apply(Shoot $shoot, ShootRescheduleRequest $request, User $actor, array $durationChanges = []): void
    {
        $lines = $shoot->serviceItems()->get();
        $durations = collect($durationChanges)->keyBy('shoot_service_id');
        if ($durations->keys()->diff($lines->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['service_lines' => ['A duration can only be changed for a service line on this shoot.']]);
        }
        $anchor = $shoot->scheduled_at ?? $lines->whereNotNull('scheduled_at')->sortBy('scheduled_at')->first()?->scheduled_at;
        if (! $anchor) {
            throw ValidationException::withMessages(['service_lines' => ['Assign the initial unit service schedules before moving the booking.']]);
        }
        if ($lines->contains(fn ($line) => ! in_array($line->workflow_status, [null, 'pending', 'scheduled', 'cancelled'], true))) {
            throw ValidationException::withMessages(['service_lines' => ['Some unit work has already started. Edit the remaining unit service schedules individually.']]);
        }
        if ($request->units_revision === null) {
            throw ValidationException::withMessages(['expected_units_revision' => ['This request predates the unit schedule. Submit a new reschedule request.']]);
        }
        $resolved = $this->resolveRequestedWallClock(
            $shoot,
            $request->requested_date,
            $request->requested_time
        );
        $hasTimezone = $resolved['has_timezone'];
        $timezone = $resolved['timezone'];
        $local = $resolved['local'];
        $target = $resolved['storage'];
        $seconds = $target->getTimestamp() - $anchor->getTimestamp();
        $changes = [
            'expected_units_revision' => $request->units_revision,
            'scheduled_at' => $resolved['scheduled_at'],
            'scheduled_date' => $resolved['scheduled_date'],
            'time' => $resolved['time'],
            'service_lines' => $lines->map(function ($line) use ($seconds, $hasTimezone, $timezone, $durations) {
                $scheduled = $line->scheduled_at?->copy()->addSeconds($seconds);

                return [
                    'shoot_service_id' => $line->id, 'client_key' => $line->client_key,
                    'duration_minutes' => $durations->get($line->id)['duration_minutes'] ?? $line->duration_minutes,
                    'shoot_unit_id' => $line->shoot_unit_id, 'service_id' => $line->service_id,
                    'scheduled_at' => $scheduled ? ($hasTimezone ? $scheduled->setTimezone($timezone)->toIso8601String() : $scheduled->format('Y-m-d H:i:s')) : null,
                ];
            })->all(),
        ];
        $prepared = app(MultiUnitBookingService::class)->prepare($shoot, $changes, $actor);
        app(ShootMutationSupportService::class)->checkServiceItemPhotographerAvailability(
            $prepared['services'], $shoot->photographer_id, $shoot->id,
            $hasTimezone ? $timezone : null, false, $target
        );
        $changes = app(\App\Services\Schedule\ShootScheduleUpdateInput::class)->normalize($shoot, $changes);
        app(ShootEditablePayloadService::class)->apply($shoot, $changes, $actor);
    }

    /**
     * Parse a reschedule wall-clock date+time in the shoot timezone.
     *
     * Zoned shoots store an absolute UTC instant; legacy unzoned shoots keep
     * naive local-clock storage (same convention as ScheduleInstantResolver).
     *
     * @return array{
     *   has_timezone: bool,
     *   timezone: string,
     *   local: Carbon,
     *   storage: Carbon,
     *   scheduled_at: string,
     *   scheduled_date: string,
     *   time: string
     * }
     */
    public function resolveRequestedWallClock(Shoot $shoot, mixed $requestedDate, ?string $requestedTime): array
    {
        $hasTimezone = trim((string) $shoot->timezone) !== '';
        $timezone = $hasTimezone ? $shoot->timezone : config('app.timezone', 'UTC');
        if ($requestedDate instanceof Carbon) {
            $dateString = $requestedDate->toDateString();
        } elseif ($requestedDate instanceof \DateTimeInterface) {
            $dateString = Carbon::instance($requestedDate)->toDateString();
        } else {
            $dateString = Carbon::parse((string) $requestedDate)->toDateString();
        }
        $local = Carbon::parse(
            $dateString.' '.($requestedTime ?: $shoot->time ?: '10:00'),
            $timezone
        );
        $storage = $hasTimezone ? $local->copy()->utc() : $local->copy();

        return [
            'has_timezone' => $hasTimezone,
            'timezone' => $timezone,
            'local' => $local,
            'storage' => $storage,
            'scheduled_at' => $hasTimezone ? $storage->toIso8601String() : $storage->format('Y-m-d H:i:s'),
            'scheduled_date' => $local->toDateString(),
            'time' => $local->format('H:i'),
        ];
    }
}
