<?php

namespace App\Http\Controllers;

use App\Models\PhotographerAvailability;
use Illuminate\Http\Request;

class PhotographerAvailabilityController extends Controller
{
    /**
     * Check if a time range overlaps with existing availabilities
     * 
     * @param int $photographerId
     * @param string $startTime Time in H:i format
     * @param string $endTime Time in H:i format
     * @param string|null $date Specific date (Y-m-d) or null for recurring
     * @param string|null $dayOfWeek Day of week (monday, tuesday, etc.) or null for specific date
     * @param int|null $excludeId ID to exclude from check (for updates)
     * @return bool True if overlap exists
     */
    private function hasOverlap($photographerId, $startTime, $endTime, $date = null, $dayOfWeek = null, $excludeId = null)
    {
        $query = PhotographerAvailability::where('photographer_id', $photographerId);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($date) {
            // For specific date: check against other slots on the same date
            // and also check against recurring slots for that day of week
            $dayOfWeekForDate = strtolower(date('l', strtotime($date)));
            
            $specificSlots = (clone $query)
                ->whereDate('date', $date)
                ->get();
            
            $recurringSlots = (clone $query)
                ->whereNull('date')
                ->where('day_of_week', $dayOfWeekForDate)
                ->get();
            
            $allSlots = $specificSlots->concat($recurringSlots);
        } else {
            // For recurring: check against other recurring slots on same day
            // and also check against specific date slots that fall on that day
            $recurringSlots = (clone $query)
                ->whereNull('date')
                ->where('day_of_week', $dayOfWeek)
                ->get();
            
            // Get all specific date slots that match this day of week
            // We need to check if any specific date falls on this day of week
            $specificSlots = (clone $query)
                ->whereNotNull('date')
                ->get()
                ->filter(function ($slot) use ($dayOfWeek) {
                    $slotDayOfWeek = strtolower(date('l', strtotime($slot->date)));
                    return $slotDayOfWeek === $dayOfWeek;
                });
            
            $allSlots = $recurringSlots->concat($specificSlots);
        }

        // Check for time overlap
        foreach ($allSlots as $slot) {
            if ($this->timesOverlap($startTime, $endTime, $slot->start_time, $slot->end_time)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if two time ranges overlap
     * 
     * @param string $start1 Start time of first range (H:i)
     * @param string $end1 End time of first range (H:i)
     * @param string $start2 Start time of second range (H:i)
     * @param string $end2 End time of second range (H:i)
     * @return bool True if ranges overlap
     */
    private function timesOverlap($start1, $end1, $start2, $end2)
    {
        // Convert times to minutes for easier comparison
        $start1Minutes = $this->timeToMinutes($start1);
        $end1Minutes = $this->timeToMinutes($end1);
        $start2Minutes = $this->timeToMinutes($start2);
        $end2Minutes = $this->timeToMinutes($end2);

        // Two ranges overlap if: start1 < end2 && start2 < end1
        // Adjacent times (end1 == start2 or end2 == start1) are NOT considered overlapping
        return $start1Minutes < $end2Minutes && $start2Minutes < $end1Minutes;
    }

    /**
     * Convert time string (H:i) to minutes since midnight
     * 
     * @param string $time Time in H:i format
     * @return int Minutes since midnight
     */
    private function timeToMinutes($time)
    {
        list($hours, $minutes) = explode(':', $time);
        return (int)$hours * 60 + (int)$minutes;
    }

    public function index($photographerId)
    {
        $availabilities = PhotographerAvailability::where('photographer_id', $photographerId)->get();
        return response()->json(['data' => $availabilities]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'photographer_id' => 'required|exists:users,id',
            'date' => 'sometimes|date',
            'day_of_week' => 'required_without:date|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'status' => 'sometimes|in:available,unavailable',
        ]);

        // Ensure non-null day_of_week to satisfy DB constraint
        $data = $validated;
        if (!isset($data['day_of_week']) && isset($data['date'])) {
            $data['day_of_week'] = strtolower(date('l', strtotime($data['date'])));
        }

        // Check for overlaps
        if ($this->hasOverlap(
            $data['photographer_id'],
            $data['start_time'],
            $data['end_time'],
            $data['date'] ?? null,
            $data['day_of_week'] ?? null
        )) {
            return response()->json([
                'message' => 'This availability overlaps with an existing time slot. Please choose a different time.',
                'error' => 'overlap'
            ], 422);
        }

        $availability = PhotographerAvailability::create($data);

        return response()->json(['data' => $availability], 201);
    }

    public function destroy($id)
    {
        PhotographerAvailability::findOrFail($id)->delete();
        return response()->json(['message' => 'Availability removed']);
    }

    public function checkAvailability(Request $request)
    {
        $validated = $request->validate([
            'photographer_id' => 'required|exists:users,id',
            'date' => 'required|date',
        ]);

        $dayOfWeek = strtolower(date('l', strtotime($validated['date'])));

        \Log::info('[Availability Check] Request received', [
            'photographer_id' => $validated['photographer_id'],
            'date' => $validated['date'],
            'day_of_week' => $dayOfWeek,
        ]);

        // Specific date overrides first
        $specific = PhotographerAvailability::where('photographer_id', $validated['photographer_id'])
            ->whereDate('date', $validated['date'])
            ->get();

        \Log::info('[Availability Check] Specific date slots', [
            'count' => $specific->count(),
            'slots' => $specific->toArray(),
        ]);

        if ($specific->count() > 0) {
            return response()->json(['data' => $specific]);
        }

        // Fallback to recurring for the weekday
        $recurring = PhotographerAvailability::where('photographer_id', $validated['photographer_id'])
            ->whereNull('date')
            ->where('day_of_week', $dayOfWeek)
            ->get();

        \Log::info('[Availability Check] Recurring slots', [
            'count' => $recurring->count(),
            'slots' => $recurring->toArray(),
            'query_day_of_week' => $dayOfWeek,
        ]);

        // Debug: Check all availability for this photographer
        $allAvailability = PhotographerAvailability::where('photographer_id', $validated['photographer_id'])->get();
        \Log::info('[Availability Check] All availability for photographer', [
            'photographer_id' => $validated['photographer_id'],
            'total_count' => $allAvailability->count(),
            'all_slots' => $allAvailability->map(function($slot) {
                return [
                    'id' => $slot->id,
                    'date' => $slot->date,
                    'day_of_week' => $slot->day_of_week,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'status' => $slot->status,
                ];
            })->toArray(),
        ]);

        return response()->json(['data' => $recurring]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date' => 'sometimes|nullable|date',
            'day_of_week' => 'sometimes|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'status' => 'sometimes|in:available,unavailable',
        ]);

        $availability = PhotographerAvailability::findOrFail($id);
        
        // Merge validated data with existing data to get complete values
        $finalData = array_merge([
            'photographer_id' => $availability->photographer_id,
            'date' => $availability->date,
            'day_of_week' => $availability->day_of_week,
            'start_time' => $availability->start_time,
            'end_time' => $availability->end_time,
        ], $validated);

        // Ensure day_of_week is set if date is provided
        if (isset($finalData['date']) && !isset($finalData['day_of_week'])) {
            $finalData['day_of_week'] = strtolower(date('l', strtotime($finalData['date'])));
        }

        // Check for overlaps (excluding the current record)
        if ($this->hasOverlap(
            $finalData['photographer_id'],
            $finalData['start_time'],
            $finalData['end_time'],
            $finalData['date'] ?? null,
            $finalData['day_of_week'] ?? null,
            $id
        )) {
            return response()->json([
                'message' => 'This availability overlaps with an existing time slot. Please choose a different time.',
                'error' => 'overlap'
            ], 422);
        }

        $availability->update($validated);

        return response()->json(['data' => $availability]);
    }

    public function bulkStore(Request $request)
    {
        $validated = $request->validate([
            'photographer_id' => 'required|exists:users,id',
            'availabilities' => 'required|array',
            'availabilities.*.date' => 'sometimes|date',
            'availabilities.*.day_of_week' => 'required_without:availabilities.*.date|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'availabilities.*.start_time' => 'required|date_format:H:i',
            'availabilities.*.end_time' => 'required|date_format:H:i|after:availabilities.*.start_time',
            'availabilities.*.status' => 'sometimes|in:available,unavailable',
        ]);

        $created = [];
        $errors = [];

        // First, check all slots for overlaps with existing slots
        foreach ($validated['availabilities'] as $index => $availability) {
            $day = $availability['day_of_week'] ?? (isset($availability['date']) ? strtolower(date('l', strtotime($availability['date']))) : null);
            
            if ($this->hasOverlap(
                $validated['photographer_id'],
                $availability['start_time'],
                $availability['end_time'],
                $availability['date'] ?? null,
                $day
            )) {
                $errors[] = "Slot #" . ($index + 1) . " overlaps with an existing availability";
            }
        }

        // Then, check for overlaps within the batch itself
        for ($i = 0; $i < count($validated['availabilities']); $i++) {
            for ($j = $i + 1; $j < count($validated['availabilities']); $j++) {
                $slot1 = $validated['availabilities'][$i];
                $slot2 = $validated['availabilities'][$j];
                
                $day1 = $slot1['day_of_week'] ?? (isset($slot1['date']) ? strtolower(date('l', strtotime($slot1['date']))) : null);
                $day2 = $slot2['day_of_week'] ?? (isset($slot2['date']) ? strtolower(date('l', strtotime($slot2['date']))) : null);
                
                // Check if they're for the same day/date
                $sameDay = false;
                if ($slot1['date'] && $slot2['date']) {
                    $sameDay = $slot1['date'] === $slot2['date'];
                } elseif ($day1 && $day2) {
                    $sameDay = $day1 === $day2;
                } elseif ($slot1['date'] && $day2) {
                    $dayOfWeekForDate1 = strtolower(date('l', strtotime($slot1['date'])));
                    $sameDay = $dayOfWeekForDate1 === $day2;
                } elseif ($day1 && $slot2['date']) {
                    $dayOfWeekForDate2 = strtolower(date('l', strtotime($slot2['date'])));
                    $sameDay = $day1 === $dayOfWeekForDate2;
                }
                
                if ($sameDay && $this->timesOverlap(
                    $slot1['start_time'],
                    $slot1['end_time'],
                    $slot2['start_time'],
                    $slot2['end_time']
                )) {
                    $errors[] = "Slot #" . ($i + 1) . " and Slot #" . ($j + 1) . " overlap with each other";
                }
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'One or more availability slots overlap with existing slots or each other.',
                'errors' => $errors,
                'error' => 'overlap'
            ], 422);
        }

        // All checks passed, create all slots
        foreach ($validated['availabilities'] as $availability) {
            $day = $availability['day_of_week'] ?? (isset($availability['date']) ? strtolower(date('l', strtotime($availability['date']))) : null);
            $created[] = PhotographerAvailability::create([
                'photographer_id' => $validated['photographer_id'],
                'date' => $availability['date'] ?? null,
                'day_of_week' => $day,
                'start_time' => $availability['start_time'],
                'end_time' => $availability['end_time'],
                'status' => $availability['status'] ?? 'available',
            ]);
        }

        return response()->json(['data' => $created], 201);
    }

    public function availablePhotographers(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        $dayOfWeek = strtolower(date('l', strtotime($validated['date'])));
        // Prefer specific overrides for that date; otherwise use recurring
        $specific = PhotographerAvailability::whereDate('date', $validated['date'])
            ->where('start_time', '<=', $validated['start_time'])
            ->where('end_time', '>=', $validated['end_time'])
            ->where('status', '!=', 'unavailable')
            ->get();

        $specificPhotographerIds = $specific->pluck('photographer_id')->unique();

        // Recurring for others who don't have specific overrides
        $recurring = PhotographerAvailability::whereNull('date')
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<=', $validated['start_time'])
            ->where('end_time', '>=', $validated['end_time'])
            ->whereNotIn('photographer_id', $specificPhotographerIds)
            ->get();

        $merged = $specific->concat($recurring)->values();

        return response()->json(['data' => $merged]);
    }

    public function clearAll($photographerId)
    {
        PhotographerAvailability::where('photographer_id', $photographerId)->delete();
        return response()->json(['message' => 'All availability cleared']);
    }



}
