<?php

namespace App\Services;

use App\Models\PhotographerAvailability;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PhotographerAvailabilityService
{
    /**
     * Get available time slots for a photographer in a date range
     */
    public function getAvailableSlots(int $photographerId, Carbon $from, Carbon $to): array
    {
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
            ])
            ->whereNotNull('scheduled_at')
            ->get();

        $blocked = [];
        foreach ($shoots as $shoot) {
            $scheduledAt = Carbon::parse($shoot->scheduled_at);
            // Assume shoots are 2 hours by default (can be made configurable)
            $endTime = $scheduledAt->copy()->addHours(2);
            
            $blocked[] = [
                'start' => $scheduledAt->format('H:i'),
                'end' => $endTime->format('H:i'),
            ];
        }

        return $blocked;
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
     */
    public function isAvailable(int $photographerId, Carbon $datetime): bool
    {
        $date = $datetime->copy()->startOfDay();
        $time = $datetime->format('H:i');
        $dayOfWeek = strtolower($datetime->format('l'));

        // Check for specific date unavailability
        $unavailable = PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereDate('date', $date->toDateString())
            ->where('status', 'unavailable')
            ->where('start_time', '<=', $time)
            ->where('end_time', '>=', $time)
            ->exists();

        if ($unavailable) {
            return false;
        }

        // Check for recurring unavailability
        $unavailable = PhotographerAvailability::where('photographer_id', $photographerId)
            ->whereNull('date')
            ->where('day_of_week', $dayOfWeek)
            ->where('status', 'unavailable')
            ->where('start_time', '<=', $time)
            ->where('end_time', '>=', $time)
            ->exists();

        if ($unavailable) {
            return false;
        }

        // Check for existing shoot conflicts
        $conflict = Shoot::where('photographer_id', $photographerId)
            ->where('scheduled_at', $datetime->format('Y-m-d H:i:s'))
            ->whereIn('status', [
                ShootWorkflowService::STATUS_SCHEDULED,
                ShootWorkflowService::STATUS_IN_PROGRESS,
            ])
            ->exists();

        if ($conflict) {
            return false;
        }

        // Check if time falls within any available slot
        $available = PhotographerAvailability::where('photographer_id', $photographerId)
            ->where(function ($query) use ($date, $dayOfWeek) {
                $query->whereDate('date', $date->toDateString())
                    ->orWhere(function ($q) use ($dayOfWeek) {
                        $q->whereNull('date')->where('day_of_week', $dayOfWeek);
                    });
            })
            ->where('status', 'available')
            ->where('start_time', '<=', $time)
            ->where('end_time', '>=', $time)
            ->exists();

        return $available;
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

