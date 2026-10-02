<?php

namespace App\Services;

use App\Models\PhotographerAvailability;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\ShootDurationResolver;
use App\Services\ShootWorkflowService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PhotographerAvailabilityService
{
    /**
     * Get available time slots for a photographer in a date range
     * Results are cached for 5 minutes to improve performance
     */
    public function getAvailableSlots(int $photographerId, Carbon $from, Carbon $to): array
    {
        $policy = app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled() ? 'hybrid' : 'fixed';
        // Read the current occupied windows before using cached net slots. A TTL
        // alone leaves cancelled/rescheduled work blocking otherwise free times.
        // Reuse this snapshot on cache misses instead of querying each day again.
        $appointments = $this->bookedAppointments($photographerId, $from, null, $to);
        $hours = PhotographerAvailability::where('photographer_id', $photographerId)
            ->where(fn ($query) => $query->whereNull('date')->orWhereBetween('date', [$from->toDateString(), $to->toDateString()]))
            ->orderBy('id')->get(['id', 'date', 'day_of_week', 'start_time', 'end_time', 'status'])->toArray();
        $version = hash('sha256', json_encode([
            array_map(fn (array $window) => [$window['shoot']->id, $window['start']->toIso8601String(), $window['minutes']], $appointments),
            $hours, (int) config('availability.buffer_time_minutes', 15),
        ], JSON_THROW_ON_ERROR));
        $cacheKey = "availability:slots:{$policy}:{$photographerId}:{$from->toDateString()}:{$to->toDateString()}:{$version}";

        // FileStore put can fail with permission denied (e.g. cache dirs owned by
        // another user). Never let a cache write failure 500 the availability API —
        // recompute and return slots without relying on a successful put.
        return $this->safeCacheRemember($cacheKey, now()->addMinutes(5), function () use ($photographerId, $from, $to, $appointments) {
            return $this->computeAvailableSlots($photographerId, $from, $to, $appointments);
        });
    }

    /**
     * Compute available slots for a date range without touching the cache.
     */
    protected function computeAvailableSlots(int $photographerId, Carbon $from, Carbon $to, ?array $appointments = null): array
    {
        $slots = [];
        $current = $from->copy();

        while ($current->lte($to)) {
            $daySlots = $this->getDaySlots($photographerId, $current, $appointments);
            if (!empty($daySlots)) {
                $slots[$current->toDateString()] = $daySlots;
            }
            $current->addDay();
        }

        return $slots;
    }

    /**
     * Cache::remember wrapper that treats put/get failures as non-fatal.
     * Mirrors WeatherLookupService::safeCacheRemember.
     */
    protected function safeCacheRemember(string $key, mixed $ttl, callable $callback): mixed
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            Log::warning('Availability slots cache remember failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * Get available slots for a specific day
     */
    protected function getDaySlots(int $photographerId, Carbon $date, ?array $appointments = null): array
    {
        $dayOfWeek = strtolower($date->format('l'));
        
        // Get specific date overrides first
        $specific = PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereDate('date', $date->toDateString())
            ->get();

        // A fully unavailable date is still an override. Only fall back to
        // recurring rules when the date has no entries at all.
        if ($specific->isEmpty()) {
            $specific = PhotographerAvailability::where('photographer_id', $photographerId)
                ->whereNull('date')
                ->where('day_of_week', $dayOfWeek)
                ->where('status', 'available')
                ->get();
        }

        $slots = [];
        foreach ($specific->where('status', 'available') as $availability) {
            $start = Carbon::parse($availability->start_time);
            $end = Carbon::parse($availability->end_time);
            
            // Remove blocked times (existing shoots)
            $blockedTimes = $this->getBlockedTimes($photographerId, $date, $appointments);
            
            $availableSlots = $this->subtractBlockedTimes($start, $end, $blockedTimes);
            $slots = array_merge($slots, $availableSlots);
        }

        return $slots;
    }

    /**
     * Get blocked times from existing shoots
     */
    protected function getBlockedTimes(int $photographerId, Carbon $date, ?array $appointments = null): array
    {
        $buffer = (app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled() ? 0 : (int) config('availability.buffer_time_minutes', 15));

        $blocked = [];
        foreach ($appointments ?? $this->bookedAppointments($photographerId, $date) as $window) {
            if ($window['start']->toDateString() !== $date->toDateString()) {
                continue;
            }
            $startMinutes = $window['start']->hour * 60 + $window['start']->minute;
            $start = max(0, $startMinutes - $buffer);
            $end = min(1440, $startMinutes + $window['minutes'] + $buffer);
            $blocked[] = ['start' => sprintf('%02d:%02d', intdiv($start, 60), $start % 60),
                'end' => sprintf('%02d:%02d', intdiv($end, 60), $end % 60), 'shoot_id' => $window['shoot']->id];
        }

        return $blocked;
    }

    /** Actual onsite appointments; the inter-visit buffer is not displayed as work. */
    public function getBookedSlots(int $photographerId, Carbon $date): array
    {
        return $this->getBookedSlotsForRange($photographerId, $date, $date);
    }

    public function getBookedSlotsForRange(int $photographerId, Carbon $from, Carbon $to): array
    {
        $slots = [];
        foreach ($this->bookedAppointments($photographerId, $from, null, $to) as $window) {
            $start = $window['start'];
            if ($start->toDateString() < $from->toDateString() || $start->toDateString() > $to->toDateString()) {
                continue;
            }
            $itemId = $window['row_indexes'][0] ?? null;
            $slots[] = [
                'id' => $itemId ? 'service_item_'.$itemId : $window['shoot']->id,
                'photographer_id' => $photographerId,
                'date' => $start->toDateString(),
                'day_of_week' => strtolower($start->format('l')),
                'start_time' => $start->format('H:i'),
                'end_time' => $start->copy()->addMinutes($window['minutes'])->format('H:i'),
                'status' => 'booked',
                'shoot_id' => $window['shoot']->id,
                'shoot_service_id' => $itemId,
                'duration_minutes' => $window['minutes'],
            ];
        }
        usort($slots, fn ($a, $b) => [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']]);

        return $slots;
    }

    /** Fetch once, then group only work assigned to this photographer at the same instant. */
    protected function bookedAppointments(int $photographerId, Carbon $date, ?int $excludeShootId = null, ?Carbon $to = null): array
    {
        $range = [$date->copy()->startOfDay()->subDay()->utc()->toDateTimeString(),
            ($to ?? $date)->copy()->endOfDay()->addDay()->utc()->toDateTimeString()];
        $shoots = Shoot::with(['photographer', 'services', 'serviceItems.service', 'serviceItems.unit'])
            ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED, Shoot::STATUS_ON_HOLD])
            ->when($excludeShootId, fn ($query) => $query->where('id', '!=', $excludeShootId))
            ->where(function ($query) use ($photographerId, $range) {
                $query->where(function ($parent) use ($photographerId, $range) {
                    $parent->where('photographer_id', $photographerId)->whereBetween('scheduled_at', $range)
                        ->whereIn('status', [ShootWorkflowService::STATUS_SCHEDULED,
                            ShootWorkflowService::STATUS_IN_PROGRESS, ShootWorkflowService::STATUS_EDITING]);
                })->orWhereHas('serviceItems', function ($items) use ($photographerId, $range) {
                    $items->whereBetween('scheduled_at', $range)
                        ->whereIn('workflow_status', [ShootService::WORKFLOW_SCHEDULED,
                            ShootService::WORKFLOW_IN_PROGRESS, ShootService::WORKFLOW_READY])
                        ->where(function ($assigned) use ($photographerId) {
                            $assigned->where('photographer_id', $photographerId)
                                ->orWhere(function ($inherited) use ($photographerId) {
                                    $inherited->whereNull('photographer_id')
                                        ->whereHas('shoot', fn ($shoot) => $shoot->where('photographer_id', $photographerId));
                                });
                        });
                });
            })->get();
        $windows = [];
        foreach ($shoots as $shoot) {
            foreach (app(ShootDurationResolver::class)->windowsForShoot($shoot, $photographerId) as $window) {
                if ($window['start'] && $window['minutes'] > 0) {
                    $windows[] = $window + ['shoot' => $shoot];
                }
            }
        }
        usort($windows, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        return $windows;
    }

    /**
     * Subtract blocked times from available range
     */
    protected function subtractBlockedTimes(Carbon $start, Carbon $end, array $blockedTimes): array
    {
        if (empty($blockedTimes)) {
            return [[
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
            ]];
        }

        $slots = [];
        $currentStart = $start->copy();

        foreach ($blockedTimes as $blocked) {
            $blockedStart = Carbon::parse($blocked['start']);
            $blockedEnd = Carbon::parse($blocked['end']);

            // If there's a gap before the blocked time
            if ($currentStart->lt($blockedStart)) {
                $slots[] = [
                    'start' => $currentStart->format('H:i'),
                    'end' => min($blockedStart->copy(), $end->copy())->format('H:i'),
                ];
            }

            // Move current start to after blocked time
            $currentStart = max($currentStart, $blockedEnd);
        }

        // Add remaining time after last blocked slot
        if ($currentStart->lt($end)) {
            $slots[] = [
                'start' => $currentStart->format('H:i'),
                'end' => $end->format('H:i'),
            ];
        }

        return $slots;
    }

    /**
     * Check if photographer is available at a specific time
     * 
     * @param int $photographerId
     * @param Carbon $datetime Requested date and time (in UTC or user timezone)
     * @param int|null $durationMinutes Requested slot duration; null uses the configured default.
     * @param int|null $excludeShootId Shoot ID to exclude from conflict check (for updates)
     * @param string|null $userTimezone User's timezone (defaults to photographer's timezone or system default)
     * @return bool
     */
    public function isAvailable(int $photographerId, Carbon $datetime, ?int $durationMinutes = null, ?int $excludeShootId = null, ?string $userTimezone = null): bool
    {
        $durationMinutes ??= app(\App\Services\Shoots\ShootDurationResolver::class)->defaultMinutes();
        // Use local time for comparison since availability slots are stored in local time
        // Do NOT convert to UTC - this was causing timezone mismatch issues
        $datetimeLocal = $datetime->copy();
        
        $date = $datetimeLocal->copy()->startOfDay();
        $time = $datetimeLocal->format('H:i');
        $dayOfWeek = strtolower($datetimeLocal->format('l'));
        
        // Calculate end time of requested slot
        $requestEndTime = $datetimeLocal->copy()->addMinutes($durationMinutes);

        
        // Log availability check
        $logContext = [
            'photographer_id' => $photographerId,
            'datetime' => $datetimeLocal->toIso8601String(),
            'user_timezone' => $userTimezone,
            'duration_minutes' => $durationMinutes,
            'exclude_shoot_id' => $excludeShootId,
            'user_id' => auth()->id(),
        ];

        // Check for specific date unavailability
        $unavailable = PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereDate('date', $date->toDateString())
            ->where('status', 'unavailable')
            ->where(function ($query) use ($time, $requestEndTime) {
                // Check if requested time range overlaps with unavailable slot
                // Overlap if: requested_start < unavailable_end && unavailable_start < requested_end
                $query->whereRaw('(start_time < ? AND end_time > ?)', [
                    $requestEndTime->format('H:i'),
                    $time
                ]);
            })
            ->exists();

        if ($unavailable) {
            Log::info('Availability check failed: specific date unavailable', array_merge($logContext, [
                'reason' => 'specific_date_unavailable',
                'result' => false,
            ]));
            return false;
        }

        // Check for recurring unavailability
        $unavailable = PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereNull('date')
            ->where('day_of_week', $dayOfWeek)
            ->where('status', 'unavailable')
            ->where(function ($query) use ($time, $requestEndTime) {
                // Check if requested time range overlaps with unavailable slot
                $query->whereRaw('(start_time < ? AND end_time > ?)', [
                    $requestEndTime->format('H:i'),
                    $time
                ]);
            })
            ->exists();

        if ($unavailable) {
            Log::info('Availability check failed: recurring unavailable', array_merge($logContext, [
                'reason' => 'recurring_unavailable',
                'day_of_week' => $dayOfWeek,
                'result' => false,
            ]));
            return false;
        }

        // Existing bookings: ScheduleInstantResolver (absolute). Request side: only
        // reinterpret naive wall-clocks when an EXPLICIT userTimezone is provided.
        $timezone = $userTimezone; // may be null — do not invent photographer profile tz
        $requestStart = $timezone
            ? $this->resolveRequestInstant($datetimeLocal, $timezone)
            : $datetimeLocal->copy()->utc();
        $requestEnd = $requestStart->copy()->addMinutes($durationMinutes);
        $bufferMinutes = (app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled() ? 0 : (int) config('availability.buffer_time_minutes', 15));
        if ($timezone) {
            $localRequest = $requestStart->copy()->setTimezone($this->validTimezoneOrUtc($timezone));
            $date = $localRequest->copy()->startOfDay();
            $time = $localRequest->format('H:i');
            $dayOfWeek = strtolower($localRequest->format('l'));
            $requestEndTime = $localRequest->copy()->addMinutes($durationMinutes);
            $datetimeLocal = $localRequest;
        }

        if ($requestEndTime->toDateString() !== $datetimeLocal->toDateString()) {
            return false;
        }

        if ($this->hasBookingConflict($photographerId, $date, $requestStart, $requestEnd, $excludeShootId, $timezone)) {
            Log::warning('Availability check failed: shoot conflict', array_merge($logContext, [
                'reason' => 'shoot_conflict',
                'buffer_minutes' => $bufferMinutes,
                'timezone' => $timezone,
                'request_utc' => $requestStart->toIso8601String(),
                'result' => false,
            ]));
            return false;
        }

        // Check if photographer has any availability slots set up for this date/day
        $hasAvailabilitySlots = PhotographerAvailability::where('photographer_id', $photographerId)
            ->where(function ($query) use ($date, $dayOfWeek) {
                $query->whereDate('date', $date->toDateString())
                    ->orWhere(function ($q) use ($dayOfWeek) {
                        $q->whereNull('date')->where('day_of_week', $dayOfWeek);
                    });
            })
            ->exists();

        // If no availability slots are set up, allow booking (assuming no conflicts or unavailability blocks)
        // This means photographer is implicitly available if they haven't set up restrictions
        if (!$hasAvailabilitySlots) {
            Log::info('Availability check: No slots configured, allowing booking', array_merge($logContext, [
                'reason' => 'no_slots_configured',
                'result' => true,
            ]));
            return true; // No availability slots = implicitly available (no restrictions)
        }

        // If availability slots exist, check if requested time range falls within any available slot
        $availableSlots = $this->getConfiguredAvailableSlots($photographerId, $date, $dayOfWeek);

        $available = false;
        if ($availableSlots->isNotEmpty()) {
            // First, check if requested time range falls fully within any available slot
            foreach ($availableSlots as $slot) {
                $slotStart = Carbon::parse($slot->start_time)->format('H:i');
                $slotEnd = Carbon::parse($slot->end_time)->format('H:i');
                
                // Requested slot must be fully within available slot
                // Available slot must start before or at requested start
                // and end after or at requested end
                if ($slotStart <= $time && $slotEnd >= $requestEndTime->format('H:i')) {
                    $available = true;
                    break;
                }
            }

        }

        // Log final result
        Log::info('Availability check completed', array_merge($logContext, [
            'result' => $available,
            'has_availability_slots' => $hasAvailabilitySlots,
            'checked_slots' => 'resolved',
        ]));

        return $available;
    }
    
    /**
     * Convert datetime to UTC for storage/comparison
     * 
     * @param Carbon $datetime
     * @param string|null $fromTimezone Source timezone (defaults to photographer's timezone or system default)
     * @return Carbon Datetime in UTC
     */
    protected function convertToUtc(Carbon $datetime, ?string $fromTimezone = null): Carbon
    {
        // If datetime already has timezone info, use it
        if ($datetime->timezone) {
            return $datetime->utc();
        }
        
        // Get timezone from parameter, photographer, or default
        if (!$fromTimezone) {
            $fromTimezone = config('app.timezone', 'America/New_York');
        }
        
        // Assume the datetime is in the specified timezone and convert to UTC
        return $datetime->setTimezone($fromTimezone)->utc();
    }
    
    /**
     * Convert datetime from UTC to user's timezone for display
     * 
     * @param Carbon $datetime Datetime in UTC
     * @param string|null $toTimezone Target timezone (defaults to photographer's timezone or system default)
     * @return Carbon Datetime in target timezone
     */
    protected function convertFromUtc(Carbon $datetime, ?string $toTimezone = null): Carbon
    {
        if (!$toTimezone) {
            $toTimezone = config('app.timezone', 'America/New_York');
        }
        
        return $datetime->utc()->setTimezone($toTimezone);
    }
    
    /**
     * Get photographer's timezone preference
     * 
     * @param int $photographerId
     * @return string Timezone string
     */
    protected function getPhotographerTimezone(int $photographerId): string
    {
        $photographer = User::find($photographerId);
        return $photographer->timezone ?? config('app.timezone', 'America/New_York');
    }

    /**
     * Assert that the requested schedule falls within the photographer's
     * effective availability bounds, throwing a structured ValidationException
     * (HTTP 422 contract) keyed on `start_time` when it does not.
     *
     * This is the shared bounds check enforced identically on shoot creation
     * and shoot update (the backend is the authoritative source of effective
     * availability). It reuses the same slot / unavailability / conflict logic
     * as isAvailable(), but instead of returning a bare boolean it throws an
     * error that distinguishes "outside the photographer's configured hours"
     * from a "booking conflict".
     *
     * Unlike isAvailable() — which treats a photographer with no configured
     * slots as implicitly available — this method applies the single canonical
     * Backend_Fallback_Hours (config/availability.php) when no configured hours
     * exist, so a bound is ALWAYS enforced.
     *
     * The configured-hours bound (unavailability blocks + effective working
     * window) is ALWAYS enforced and is identical on the create and update
     * paths. The booking-conflict check (step 2) may be suppressed via
     * $skipConflictCheck for privileged overrides (e.g. an admin update passing
     * skip_availability_check); suppressing it NEVER relaxes the configured-hours
     * bound.
     *
     * @param int       $photographerId
     * @param Carbon    $scheduledAt       Requested local date/time
     * @param int|null  $durationMinutes   Requested slot duration (defaults to config default)
     * @param int|null  $excludeShootId    Shoot to exclude from conflict checks (updates)
     * @param bool      $skipConflictCheck Skip ONLY the booking-conflict check (bounds still enforced)
     *
     * @throws ValidationException keyed on `start_time` (HTTP 422)
     */
    public function assertWithinAvailabilityBounds(
        int $photographerId,
        Carbon $scheduledAt,
        ?int $durationMinutes = null,
        ?int $excludeShootId = null,
        bool $skipConflictCheck = false,
        ?string $timezone = null
    ): void {
        $durationMinutes ??= app(\App\Services\Shoots\ShootDurationResolver::class)->defaultMinutes();

        // Availability slots are local clocks. Only project into an EXPLICIT booking
        // timezone (request/shoot). Do not invent one from the photographer profile.
        // UpdateShootAction may already have setTimezone — applying the same zone again
        // is a no-op and must not shiftTimezone.
        $datetimeLocal = $scheduledAt->copy();
        if ($timezone) {
            $tz = $this->validTimezoneOrUtc($timezone);
            if ($tz !== (string) config('app.timezone', 'UTC') && $datetimeLocal->timezoneName !== $tz) {
                $datetimeLocal->setTimezone($tz);
            }
        }
        $date = $datetimeLocal->copy()->startOfDay();
        $time = $datetimeLocal->format('H:i');
        $dayOfWeek = strtolower($datetimeLocal->format('l'));
        $requestEndTime = $datetimeLocal->copy()->addMinutes($durationMinutes);
        $requestStartUtc = $datetimeLocal->copy()->utc();
        $requestEndUtc = $requestEndTime->copy()->utc();

        if ($requestEndTime->toDateString() !== $datetimeLocal->toDateString()) {
            throw $this->outsideHoursException($photographerId, $date, $dayOfWeek, $datetimeLocal);
        }

        // 1) Specific-date or recurring unavailability blocks => outside available hours.
        //    This is part of the configured-hours bound and is always enforced.
        if ($this->overlapsUnavailability($photographerId, $date, $dayOfWeek, $time, $requestEndTime)) {
            throw $this->outsideHoursException($photographerId, $date, $dayOfWeek, $datetimeLocal);
        }

        // 2) Conflicts with existing shoots / service items (with buffer) => booking conflict.
        //    Only this step may be skipped by a privileged override; the bound below is not.
        if (!$skipConflictCheck
            && $this->hasBookingConflict($photographerId, $date, $requestStartUtc, $requestEndUtc, $excludeShootId, $timezone)) {
            throw ValidationException::withMessages([
                'start_time' => ['The selected time conflicts with another booking for this photographer.'],
            ]);
        }

        // 3) Effective working window (configured slots, else Backend_Fallback_Hours).
        //    Always enforced — this is the configured-hours bound shared by create and update.
        if (!$this->isWithinEffectiveWindow($photographerId, $date, $dayOfWeek, $time, $requestEndTime)) {
            throw $this->outsideHoursException($photographerId, $date, $dayOfWeek, $datetimeLocal);
        }
    }

    /**
     * Whether the requested range overlaps a specific-date or recurring
     * unavailability block. Mirrors the unavailability logic in isAvailable().
     */
    protected function overlapsUnavailability(
        int $photographerId,
        Carbon $date,
        string $dayOfWeek,
        string $time,
        Carbon $requestEndTime
    ): bool {
        $overlapClosure = function ($query) use ($time, $requestEndTime) {
            // Overlap if requested_start < unavailable_end && unavailable_start < requested_end
            $query->whereRaw('(start_time < ? AND end_time > ?)', [
                $requestEndTime->format('H:i'),
                $time,
            ]);
        };

        $specific = PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereDate('date', $date->toDateString())
            ->where('status', 'unavailable')
            ->where($overlapClosure)
            ->exists();

        if ($specific) {
            return true;
        }

        return PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereNull('date')
            ->where('day_of_week', $dayOfWeek)
            ->where('status', 'unavailable')
            ->where($overlapClosure)
            ->exists();
    }

    /**
     * Whether the requested range conflicts with an existing shoot or service
     * item (buffer-aware). Mirrors the conflict logic in isAvailable().
     */
    protected function hasBookingConflict(
        int $photographerId,
        Carbon $date,
        Carbon $requestStartUtc,
        Carbon $requestEndUtc,
        ?int $excludeShootId,
        ?string $timezone = null
    ): bool {
        $bufferMinutes = (app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled() ? 0 : (int) config('availability.buffer_time_minutes', 15));
        foreach ($this->bookedAppointments($photographerId, $date, $excludeShootId) as $window) {
            $start = $window['start']->copy();
            // Unzoned legacy requests and bookings share their stored wall clock.
            if (! $timezone && trim((string) $window['shoot']->timezone) === '') {
                $start->shiftTimezone('UTC');
            }
            $start->utc();
            $end = $start->copy()->addMinutes($window['minutes']);
            // Pad the existing interval only: adjacent appointments require one gap,
            // not a buffer on both the request and existing appointment.
            if ($requestStartUtc < $end->addMinutes($bufferMinutes)
                && $requestEndUtc > $start->subMinutes($bufferMinutes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a request datetime to an absolute UTC instant.
     *
     * Callers that receive naive wall-clock strings (no Z/offset) must parse them
     * in the booking timezone first (see ShootMutationSupportService::parseScheduleInstant).
     * DateTimes already carrying an offset, or absolute UTC instants from Z, are trusted.
     * When a booking timezone is provided, app-UTC wall clocks are reinterpreted as
     * local wall time in that zone so FE payloads without Z still compare correctly.
     */
    protected function resolveRequestInstant(Carbon $datetime, ?string $timezone): Carbon
    {
        $tz = $this->validTimezoneOrUtc($timezone);
        $copy = $datetime->copy();

        // Already an explicit non-UTC offset (e.g. -04:00) — absolute instant is known.
        if ($copy->utcOffset() !== 0) {
            return $copy->utc();
        }

        // Naive / app-UTC clock face + real booking timezone => local wall clock.
        // Proper Z timestamps that were already converted by the create/update path
        // should arrive with the booking zone applied (setTimezone) or as a local
        // parse; remaining app-UTC values from wall-clock payloads need shiftTimezone.
        if ($tz !== 'UTC'
            && in_array($copy->timezoneName, ['UTC', 'Z', (string) config('app.timezone', 'UTC')], true)
            && $this->looksLikeWallClockUtc($copy, $tz)
        ) {
            return $copy->shiftTimezone($tz)->utc();
        }

        return $copy->utc();
    }

    /**
     * Heuristic: a UTC midnight-aligned booking that matches a plausible local
     * wall clock in $tz is treated as wall-clock. Absolute Z instants that were
     * converted via setTimezone into $tz will already have a non-UTC offset and
     * are handled above. Remaining UTC values from `new DateTime(naive)` need shift.
     */
    protected function looksLikeWallClockUtc(Carbon $datetime, string $timezone): bool
    {
        // If caller already projected into the booking zone via setTimezone, offset != 0.
        // Pure UTC Carbon from naive string or Z both have offset 0 — prefer shifting
        // when photographer/booking tz is a real civil zone so 1pm wall clears 10am ET.
        return !in_array($timezone, ['UTC', 'Z'], true);
    }

    protected function validTimezoneOrUtc(?string $timezone): string
    {
        $timezone = \App\Support\Timezone::canonical(trim((string) ($timezone ?: '')));
        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }

    /**
     * Configured "available" slots for the date (specific-date overrides take
     * precedence over recurring day-of-week rules). Mirrors isAvailable().
     */
    protected function getConfiguredAvailableSlots(int $photographerId, Carbon $date, string $dayOfWeek): Collection
    {
        $specific = PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereDate('date', $date->toDateString())
            ->where('status', 'available')
            ->get();

        if ($specific->isNotEmpty()) {
            return $specific;
        }

        return PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereNull('date')->where('day_of_week', $dayOfWeek)
            ->where('status', 'available')->get();
    }

    /**
     * Whether the requested range falls within the effective working window:
     * the configured available slots when present, otherwise the single
     * canonical Backend_Fallback_Hours from config/availability.php.
     */
    protected function isWithinEffectiveWindow(
        int $photographerId,
        Carbon $date,
        string $dayOfWeek,
        string $time,
        Carbon $requestEndTime
    ): bool {
        $slots = $this->getConfiguredAvailableSlots($photographerId, $date, $dayOfWeek);

        if ($slots->isEmpty()) {
            // No configured hours => apply the single canonical Backend_Fallback_Hours.
            $fallbackStart = config('availability.fallback_start_time', '09:00');
            $fallbackEnd = config('availability.fallback_end_time', '18:00');

            return $this->requestWithinWindow($fallbackStart, $fallbackEnd, $time, $requestEndTime);
        }

        foreach ($slots as $slot) {
            if ($this->requestWithinWindow($slot->start_time, $slot->end_time, $time, $requestEndTime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a requested start/end fits a single [start, end] window. Accepts
     * full containment of onsite work; the travel buffer need not fit after closing.
     */
    protected function requestWithinWindow(string $windowStart, string $windowEnd, string $time, Carbon $requestEndTime): bool
    {
        $start = Carbon::parse($windowStart)->format('H:i');
        $end = Carbon::parse($windowEnd)->format('H:i');

        return $start <= $time && $time < $end && $end >= $requestEndTime->format('H:i');
    }

    /**
     * The encompassing effective working window (min start / max end of the
     * configured available slots, else Backend_Fallback_Hours) used for the
     * structured "outside hours" error message.
     *
     * @return array{start: string, end: string}|null
     */
    protected function getEffectiveWindow(int $photographerId, Carbon $date, string $dayOfWeek): ?array
    {
        $slots = $this->getConfiguredAvailableSlots($photographerId, $date, $dayOfWeek);

        if ($slots->isEmpty()) {
            return [
                'start' => Carbon::parse(config('availability.fallback_start_time', '09:00'))->format('H:i'),
                'end' => Carbon::parse(config('availability.fallback_end_time', '18:00'))->format('H:i'),
            ];
        }

        $starts = $slots->map(fn ($slot) => Carbon::parse($slot->start_time)->format('H:i'))->all();
        $ends = $slots->map(fn ($slot) => Carbon::parse($slot->end_time)->format('H:i'))->all();

        if (empty($starts) || empty($ends)) {
            return null;
        }

        return [
            'start' => min($starts),
            'end' => max($ends),
        ];
    }

    /**
     * Build the structured "outside configured hours" ValidationException
     * (keyed on `start_time`) per the HTTP 422 contract.
     */
    protected function outsideHoursException(
        int $photographerId,
        Carbon $date,
        string $dayOfWeek,
        Carbon $datetimeLocal
    ): ValidationException {
        $window = $this->getEffectiveWindow($photographerId, $date, $dayOfWeek);
        $dayLabel = ucfirst($dayOfWeek);
        $requested = $datetimeLocal->format('H:i');

        $detail = $window
            ? "Photographer is available {$window['start']}–{$window['end']} on {$dayLabel}; {$requested} is outside this window."
            : "Photographer has no available hours on {$dayLabel}; {$requested} cannot be booked.";

        $exception = ValidationException::withMessages([
            'start_time' => [$detail],
        ]);

        // Align with the design's HTTP 422 contract.
        $exception->status = 422;

        return $exception;
    }

    /**
     * Get availability summary for a date range
     */
    public function getAvailabilitySummary(int $photographerId, Carbon $from, Carbon $to): array
    {
        $current = $from->copy();
        $summary = [];

        while ($current->lte($to)) {
            $slots = $this->getDaySlots($photographerId, $current);
            $summary[$current->toDateString()] = [
                'available' => !empty($slots),
                'slots' => $slots,
            ];
            $current->addDay();
        }

        return $summary;
    }
}
