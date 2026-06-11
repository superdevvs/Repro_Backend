<?php

namespace App\Services;

use App\Models\PhotographerAvailability;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
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
        $cacheKey = "availability:slots:{$photographerId}:{$from->toDateString()}:{$to->toDateString()}";
        
        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($photographerId, $from, $to) {
            $slots = [];
            $current = $from->copy();

            while ($current->lte($to)) {
                $daySlots = $this->getDaySlots($photographerId, $current);
                if (!empty($daySlots)) {
                    $slots[$current->toDateString()] = $daySlots;
                }
                $current->addDay();
            }

            return $slots;
        });
    }

    /**
     * Get available slots for a specific day
     */
    protected function getDaySlots(int $photographerId, Carbon $date): array
    {
        $dayOfWeek = strtolower($date->format('l'));
        
        // Get specific date overrides first
        $specific = PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereDate('date', $date->toDateString())
            ->where('status', 'available')
            ->get();

        // If no specific overrides, use recurring rules
        if ($specific->isEmpty()) {
            $specific = PhotographerAvailability::where('photographer_id', $photographerId)
                ->whereNull('date')
                ->where('day_of_week', $dayOfWeek)
                ->where('status', 'available')
                ->get();
        }

        $slots = [];
        foreach ($specific as $availability) {
            $start = Carbon::parse($availability->start_time);
            $end = Carbon::parse($availability->end_time);
            
            // Remove blocked times (existing shoots)
            $blockedTimes = $this->getBlockedTimes($photographerId, $date);
            
            $availableSlots = $this->subtractBlockedTimes($start, $end, $blockedTimes);
            $slots = array_merge($slots, $availableSlots);
        }

        return $slots;
    }

    /**
     * Get blocked times from existing shoots
     */
    protected function getBlockedTimes(int $photographerId, Carbon $date): array
    {
        $shoots = Shoot::where('photographer_id', $photographerId)
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('status', [
                ShootWorkflowService::STATUS_SCHEDULED,
                ShootWorkflowService::STATUS_IN_PROGRESS,
                ShootWorkflowService::STATUS_EDITING,
            ])
            ->whereNotNull('scheduled_at')
            ->get();

        $blocked = [];
        foreach ($shoots as $shoot) {
            $scheduledAt = Carbon::parse($shoot->scheduled_at);
            // Use actual shoot duration
            $durationMinutes = $this->calculateShootDuration($shoot);
            $endTime = $scheduledAt->copy()->addMinutes($durationMinutes);
            
            $blocked[] = [
                'start' => $scheduledAt->format('H:i'),
                'end' => $endTime->format('H:i'),
                'shoot_id' => $shoot->id,
            ];
        }

        $serviceItems = ShootService::with(['shoot', 'service'])
            ->where('photographer_id', $photographerId)
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('workflow_status', [
                ShootService::WORKFLOW_SCHEDULED,
                ShootService::WORKFLOW_IN_PROGRESS,
                ShootService::WORKFLOW_READY,
            ])
            ->whereNotNull('scheduled_at')
            ->whereHas('shoot', function ($query) {
                $query->whereNotIn('status', [
                    Shoot::STATUS_CANCELLED,
                    Shoot::STATUS_DECLINED,
                    Shoot::STATUS_ON_HOLD,
                ]);
            })
            ->get();

        foreach ($serviceItems as $item) {
            $scheduledAt = Carbon::parse($item->scheduled_at);
            $endTime = $scheduledAt->copy()->addMinutes($this->calculateServiceItemDuration($item));

            $blocked[] = [
                'start' => $scheduledAt->format('H:i'),
                'end' => $endTime->format('H:i'),
                'shoot_id' => $item->shoot_id,
                'shoot_service_id' => $item->id,
            ];
        }

        usort($blocked, fn ($a, $b) => strcmp($a['start'], $b['start']));

        return $blocked;
    }

    /**
     * Get booked slots (existing shoots) for a specific day
     */
    public function getBookedSlots(int $photographerId, Carbon $date): array
    {
        $shoots = Shoot::where('photographer_id', $photographerId)
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('status', [
                ShootWorkflowService::STATUS_SCHEDULED,
                ShootWorkflowService::STATUS_IN_PROGRESS,
                ShootWorkflowService::STATUS_EDITING,
            ])
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at')
            ->get();

        $bookedSlots = [];
        foreach ($shoots as $shoot) {
            $scheduledAt = Carbon::parse($shoot->scheduled_at);
            $durationMinutes = $this->calculateShootDuration($shoot);
            $endTime = $scheduledAt->copy()->addMinutes($durationMinutes);
            
            $bookedSlots[] = [
                'id' => $shoot->id,
                'photographer_id' => $photographerId,
                'date' => $date->toDateString(),
                'day_of_week' => strtolower($date->format('l')),
                'start_time' => $scheduledAt->format('H:i'),
                'end_time' => $endTime->format('H:i'),
                'status' => 'booked',
                'shoot_id' => $shoot->id,
            ];
        }

        $serviceItems = ShootService::with(['shoot', 'service'])
            ->where('photographer_id', $photographerId)
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('workflow_status', [
                ShootService::WORKFLOW_SCHEDULED,
                ShootService::WORKFLOW_IN_PROGRESS,
                ShootService::WORKFLOW_READY,
            ])
            ->whereNotNull('scheduled_at')
            ->whereHas('shoot', function ($query) {
                $query->whereNotIn('status', [
                    Shoot::STATUS_CANCELLED,
                    Shoot::STATUS_DECLINED,
                    Shoot::STATUS_ON_HOLD,
                ]);
            })
            ->orderBy('scheduled_at')
            ->get();

        foreach ($serviceItems as $item) {
            $scheduledAt = Carbon::parse($item->scheduled_at);
            $endTime = $scheduledAt->copy()->addMinutes($this->calculateServiceItemDuration($item));

            $bookedSlots[] = [
                'id' => 'service_item_' . $item->id,
                'photographer_id' => $photographerId,
                'date' => $date->toDateString(),
                'day_of_week' => strtolower($date->format('l')),
                'start_time' => $scheduledAt->format('H:i'),
                'end_time' => $endTime->format('H:i'),
                'status' => 'booked',
                'shoot_id' => $item->shoot_id,
                'shoot_service_id' => $item->id,
            ];
        }

        usort($bookedSlots, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));

        return $bookedSlots;
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
     * @param int|null $durationMinutes Duration of the requested slot in minutes (default: 120)
     * @param int|null $excludeShootId Shoot ID to exclude from conflict check (for updates)
     * @param string|null $userTimezone User's timezone (defaults to photographer's timezone or system default)
     * @return bool
     */
    public function isAvailable(int $photographerId, Carbon $datetime, ?int $durationMinutes = 120, ?int $excludeShootId = null, ?string $userTimezone = null): bool
    {
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

        // Check for existing shoot conflicts - check time range overlap, not just exact match
        $conflictingShoots = Shoot::where('photographer_id', $photographerId)
            ->whereNotNull('scheduled_at')
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('status', [
                ShootWorkflowService::STATUS_SCHEDULED,
                ShootWorkflowService::STATUS_IN_PROGRESS,
                ShootWorkflowService::STATUS_EDITING,
            ])
            ->when($excludeShootId, function ($query) use ($excludeShootId) {
                $query->where('id', '!=', $excludeShootId);
            })
            ->orderBy('scheduled_at')
            ->get();

        // Get buffer time from config
        $bufferMinutes = config('availability.buffer_time_minutes', 30);

        // Check each shoot for time overlap (with buffer time consideration)
        $conflictingShootIds = [];
        foreach ($conflictingShoots as $shoot) {
            $shootStart = Carbon::parse($shoot->scheduled_at);
            $shootDuration = $this->calculateShootDuration($shoot);
            $shootEnd = $shootStart->copy()->addMinutes($shootDuration);
            
            // Apply buffer time by expanding the existing shoot window forward and backward
            $shootEndWithBuffer = $shootEnd->copy()->addMinutes($bufferMinutes);
            $shootStartWithBuffer = $shootStart->copy()->subMinutes($bufferMinutes);
            
            // Conflict if the requested slot starts before the buffered shoot ends
            // AND the requested slot ends after the buffered shoot starts
            if ($datetimeLocal < $shootEndWithBuffer && $requestEndTime > $shootStartWithBuffer) {
                $conflictingShootIds[] = $shoot->id;
            }
        }

        $conflictingServiceItems = ShootService::with(['shoot', 'service'])
            ->where('photographer_id', $photographerId)
            ->whereNotNull('scheduled_at')
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('workflow_status', [
                ShootService::WORKFLOW_SCHEDULED,
                ShootService::WORKFLOW_IN_PROGRESS,
                ShootService::WORKFLOW_READY,
            ])
            ->whereHas('shoot', function ($query) use ($excludeShootId) {
                $query->whereNotIn('status', [
                    Shoot::STATUS_CANCELLED,
                    Shoot::STATUS_DECLINED,
                    Shoot::STATUS_ON_HOLD,
                ]);

                if ($excludeShootId) {
                    $query->where('id', '!=', $excludeShootId);
                }
            })
            ->orderBy('scheduled_at')
            ->get();

        $conflictingServiceItemIds = [];
        foreach ($conflictingServiceItems as $item) {
            $itemStart = Carbon::parse($item->scheduled_at);
            $itemDuration = $this->calculateServiceItemDuration($item);
            $itemEnd = $itemStart->copy()->addMinutes($itemDuration);
            $itemEndWithBuffer = $itemEnd->copy()->addMinutes($bufferMinutes);
            $itemStartWithBuffer = $itemStart->copy()->subMinutes($bufferMinutes);

            if ($datetimeLocal < $itemEndWithBuffer && $requestEndTime > $itemStartWithBuffer) {
                $conflictingServiceItemIds[] = $item->id;
            }
        }
        
        if (!empty($conflictingShootIds) || !empty($conflictingServiceItemIds)) {
            Log::warning('Availability check failed: shoot conflict', array_merge($logContext, [
                'reason' => 'shoot_conflict',
                'conflicting_shoot_ids' => $conflictingShootIds,
                'conflicting_shoot_service_ids' => $conflictingServiceItemIds,
                'buffer_minutes' => $bufferMinutes,
                'result' => false,
            ]));
            return false; // Conflict found (either direct overlap or buffer violation)
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
        $availableSlots = PhotographerAvailability::where('photographer_id', $photographerId)
            ->where(function ($query) use ($date, $dayOfWeek) {
                $query->whereDate('date', $date->toDateString())
                    ->orWhere(function ($q) use ($dayOfWeek) {
                        $q->whereNull('date')->where('day_of_week', $dayOfWeek);
                    });
            })
            ->where('status', 'available')
            ->get();

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
            
            // If no full match, check if the start time is within a slot
            // This matches the frontend behavior - it only checks if start time is available
            if (!$available) {
                foreach ($availableSlots as $slot) {
                    $slotStart = Carbon::parse($slot->start_time)->format('H:i');
                    $slotEnd = Carbon::parse($slot->end_time)->format('H:i');
                    
                    // Check if requested start time falls within the slot (inclusive)
                    // This matches frontend logic which only checks start time
                    if ($slotStart <= $time && $time <= $slotEnd) {
                        $available = true;
                        Log::info('Availability check: allowing booking - start time within slot', array_merge($logContext, [
                            'slot_start' => $slotStart,
                            'slot_end' => $slotEnd,
                            'requested_start' => $time,
                            'note' => 'Start time is within available slot (matches frontend check)',
                        ]));
                        break;
                    }
                }
            }
            
            // Log which slots were checked for debugging
            Log::info('Availability check: checked slots', array_merge($logContext, [
                'available_slots_count' => $availableSlots->count(),
                'slots' => $availableSlots->map(fn($s) => [
                    'start' => $s->start_time,
                    'end' => $s->end_time,
                    'date' => $s->date,
                    'day_of_week' => $s->day_of_week,
                ])->toArray(),
                'requested_time' => $time,
                'requested_end_time' => $requestEndTime->format('H:i'),
                'result' => $available,
            ]));
        }

        // Log final result
        Log::info('Availability check completed', array_merge($logContext, [
            'result' => $available,
            'has_availability_slots' => $hasAvailabilitySlots,
            'checked_slots' => $conflictingShoots->count(),
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
     * Calculate shoot duration in minutes based on services
     * Defaults to 120 minutes (2 hours) if services don't have duration info
     * 
     * @param Shoot $shoot
     * @return int Duration in minutes
     */
    protected function calculateShootDuration(Shoot $shoot): int
    {
        // Get duration from config
        $defaultDurationMinutes = config('availability.default_shoot_duration_minutes', 120);
        $minDurationMinutes = config('availability.min_shoot_duration_minutes', 60);
        $maxDurationMinutes = config('availability.max_shoot_duration_minutes', 240);

        // Try to calculate from services
        $services = $shoot->services;
        if ($services && $services->isNotEmpty()) {
            $calculatedDurationMinutes = (int) $services
                ->map(function ($service) use ($defaultDurationMinutes) {
                    return method_exists($service, 'getShootDurationMinutes')
                        ? $service->getShootDurationMinutes()
                        : $defaultDurationMinutes;
                })
                ->max();

            return min(max($calculatedDurationMinutes, $minDurationMinutes), $maxDurationMinutes);
        }
        
        return $defaultDurationMinutes;
    }

    protected function calculateServiceItemDuration(ShootService $item): int
    {
        $defaultDurationMinutes = config('availability.default_shoot_duration_minutes', 120);
        $service = $item->relationLoaded('service') ? $item->service : $item->service()->first();
        if (!$service || !method_exists($service, 'getShootDurationMinutes')) {
            return $defaultDurationMinutes;
        }

        return $service->getShootDurationMinutes();
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
        bool $skipConflictCheck = false
    ): void {
        $durationMinutes = $durationMinutes ?? (int) config('availability.default_shoot_duration_minutes', 120);

        // Availability slots are stored in local time; compare in local time.
        $datetimeLocal = $scheduledAt->copy();
        $date = $datetimeLocal->copy()->startOfDay();
        $time = $datetimeLocal->format('H:i');
        $dayOfWeek = strtolower($datetimeLocal->format('l'));
        $requestEndTime = $datetimeLocal->copy()->addMinutes($durationMinutes);

        // 1) Specific-date or recurring unavailability blocks => outside available hours.
        //    This is part of the configured-hours bound and is always enforced.
        if ($this->overlapsUnavailability($photographerId, $date, $dayOfWeek, $time, $requestEndTime)) {
            throw $this->outsideHoursException($photographerId, $date, $dayOfWeek, $datetimeLocal);
        }

        // 2) Conflicts with existing shoots / service items (with buffer) => booking conflict.
        //    Only this step may be skipped by a privileged override; the bound below is not.
        if (!$skipConflictCheck
            && $this->hasBookingConflict($photographerId, $date, $datetimeLocal, $requestEndTime, $excludeShootId)) {
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
        Carbon $datetimeLocal,
        Carbon $requestEndTime,
        ?int $excludeShootId
    ): bool {
        $bufferMinutes = (int) config('availability.buffer_time_minutes', 30);

        $conflictingShoots = Shoot::where('photographer_id', $photographerId)
            ->whereNotNull('scheduled_at')
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('status', [
                ShootWorkflowService::STATUS_SCHEDULED,
                ShootWorkflowService::STATUS_IN_PROGRESS,
                ShootWorkflowService::STATUS_EDITING,
            ])
            ->when($excludeShootId, function ($query) use ($excludeShootId) {
                $query->where('id', '!=', $excludeShootId);
            })
            ->get();

        foreach ($conflictingShoots as $shoot) {
            $shootStart = Carbon::parse($shoot->scheduled_at);
            $shootEnd = $shootStart->copy()->addMinutes($this->calculateShootDuration($shoot));
            $shootEndWithBuffer = $shootEnd->copy()->addMinutes($bufferMinutes);
            $shootStartWithBuffer = $shootStart->copy()->subMinutes($bufferMinutes);

            if ($datetimeLocal < $shootEndWithBuffer && $requestEndTime > $shootStartWithBuffer) {
                return true;
            }
        }

        $conflictingServiceItems = ShootService::with(['shoot', 'service'])
            ->where('photographer_id', $photographerId)
            ->whereNotNull('scheduled_at')
            ->whereDate('scheduled_at', $date->toDateString())
            ->whereIn('workflow_status', [
                ShootService::WORKFLOW_SCHEDULED,
                ShootService::WORKFLOW_IN_PROGRESS,
                ShootService::WORKFLOW_READY,
            ])
            ->whereHas('shoot', function ($query) use ($excludeShootId) {
                $query->whereNotIn('status', [
                    Shoot::STATUS_CANCELLED,
                    Shoot::STATUS_DECLINED,
                    Shoot::STATUS_ON_HOLD,
                ]);

                if ($excludeShootId) {
                    $query->where('id', '!=', $excludeShootId);
                }
            })
            ->get();

        foreach ($conflictingServiceItems as $item) {
            $itemStart = Carbon::parse($item->scheduled_at);
            $itemEnd = $itemStart->copy()->addMinutes($this->calculateServiceItemDuration($item));
            $itemEndWithBuffer = $itemEnd->copy()->addMinutes($bufferMinutes);
            $itemStartWithBuffer = $itemStart->copy()->subMinutes($bufferMinutes);

            if ($datetimeLocal < $itemEndWithBuffer && $requestEndTime > $itemStartWithBuffer) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configured "available" slots for the date (specific-date overrides take
     * precedence over recurring day-of-week rules). Mirrors isAvailable().
     */
    protected function getConfiguredAvailableSlots(int $photographerId, Carbon $date, string $dayOfWeek): Collection
    {
        return PhotographerAvailability::where('photographer_id', $photographerId)
            ->where(function ($query) use ($date, $dayOfWeek) {
                $query->whereDate('date', $date->toDateString())
                    ->orWhere(function ($q) use ($dayOfWeek) {
                        $q->whereNull('date')->where('day_of_week', $dayOfWeek);
                    });
            })
            ->where('status', 'available')
            ->get();
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
     * full containment, or (matching the frontend / isAvailable() behavior) a
     * start time that falls within the window.
     */
    protected function requestWithinWindow(string $windowStart, string $windowEnd, string $time, Carbon $requestEndTime): bool
    {
        $start = Carbon::parse($windowStart)->format('H:i');
        $end = Carbon::parse($windowEnd)->format('H:i');

        // Fully contained: window starts at/before request and ends at/after request end.
        if ($start <= $time && $end >= $requestEndTime->format('H:i')) {
            return true;
        }

        // Start time within window (matches frontend behavior, which checks the start time).
        return $start <= $time && $time <= $end;
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
