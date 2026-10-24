<?php

namespace App\Http\Controllers;

use App\Models\PhotographerAvailability;
use App\Models\Shoot;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use App\Services\AddressLookupService;
use App\Services\Photographers\RadiusEligibility;
use App\Services\Schedule\ScheduleInstantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\LockedWrite;

class PhotographerAvailabilityController extends Controller
{
    protected $availabilityService;

    public function __construct(PhotographerAvailabilityService $availabilityService)
    {
        $this->availabilityService = $availabilityService;
    }

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
        $query = PhotographerAvailability::where('photographer_id', $photographerId)->where('status', 'available');
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($date) {
            // A dated working window overrides recurring hours for this date.
            $allSlots = (clone $query)
                ->whereDate('date', $date)
                ->get();
        } else {
            // Dated entries override the weekly schedule on their own dates.
            // They must not prevent adding/editing the weekly rule for every
            // other week (including when those dated entries are in the past).
            $allSlots = (clone $query)
                ->whereNull('date')
                ->where('day_of_week', $dayOfWeek)
                ->get();
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

    public function index(Request $request, $photographerId)
    {
        if ($response = $this->denyUnlessCanViewAvailability($request->user(), (int) $photographerId)) {
            return $response;
        }

        $availabilities = PhotographerAvailability::where('photographer_id', $photographerId)->get();
        $timezone = \App\Support\Timezone::canonical((string) User::findOrFail($photographerId)->timezone);
        if (!in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            $timezone = config('app.timezone', 'UTC');
        }
        return response()->json(['data' => $availabilities, 'timezone' => $timezone]);
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
            'note' => 'nullable|string|max:1000',
        ]);

        if ($response = $this->denyUnlessCanManageAvailability($request->user(), (int) $validated['photographer_id'])) {
            return $response;
        }

        // Ensure non-null day_of_week to satisfy DB constraint
        $data = $validated;
        if (!isset($data['day_of_week']) && isset($data['date'])) {
            $data['day_of_week'] = strtolower(date('l', strtotime($data['date'])));
        }

        $status = $data['status'] ?? 'available';
        if ($status !== 'unavailable') {
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
        }

        $availability = LockedWrite::run(fn () => DB::transaction(function () use ($data, $status) {
            if ($status === 'unavailable') {
                app(\App\Services\AvailabilityBlockWindows::class)->preserve(
                    $data['photographer_id'], $data['date'] ?? null, $data['day_of_week'] ?? null, null
                );
            }
            return PhotographerAvailability::create($data);
        }), 'availability.store');
        
        // Clear availability cache for this photographer
        $this->clearAvailabilityCache($availability->photographer_id);

        return response()->json(['data' => $availability], 201);
    }

    public function destroy($id)
    {
        $availability = PhotographerAvailability::findOrFail($id);
        if ($response = $this->denyUnlessCanManageAvailability(request()->user(), (int) $availability->photographer_id)) {
            return $response;
        }

        $photographerId = $availability->photographer_id;
        
        $availability->delete();
        
        // Clear availability cache for this photographer
        $this->clearAvailabilityCache($photographerId);
        
        return response()->json(['message' => 'Availability removed']);
    }

    public function checkAvailability(Request $request)
    {
        $travelFeasibility = $this->selectedTravelFeasibility($request);
        try {
            $validated = $request->validate([
                'photographer_id' => 'required|exists:users,id',
                'date' => 'required|date',
                'shoot_id' => 'nullable|integer|exists:shoots,id',
            ]);

            if ($response = $this->denyUnlessCanViewAvailability($request->user(), (int) $validated['photographer_id'])) {
                return $response;
            }

            $excludeShootId = isset($validated['shoot_id']) ? (int) $validated['shoot_id'] : null;
            if ($excludeShootId && !app(\App\Services\Shoots\ShootAuthorizationSupport::class)
                ->canScheduleShoot(Shoot::findOrFail($excludeShootId), $request->user())) {
                return response()->json(['message' => 'You cannot edit this appointment.'], 403);
            }
            $dayOfWeek = strtolower(date('l', strtotime($validated['date'])));
            $date = \Carbon\Carbon::parse($validated['date']);

            \Log::info('[Availability Check] Request received', [
                'photographer_id' => $validated['photographer_id'],
                'date' => $validated['date'],
                'day_of_week' => $dayOfWeek,
            ]);

            // Use the availability service to get available slots for this day
            // This method already excludes blocked times from existing shoots
            $availableSlots = $this->availabilityService->getAvailableSlots(
                $validated['photographer_id'],
                $date,
                $date, // Same date for from and to (single day)
                $excludeShootId
            );

            // The method returns slots grouped by date, so get slots for this specific date
            $daySlots = $availableSlots[$date->toDateString()] ?? [];

            // Convert available slots to the format expected by frontend
            $formattedAvailableSlots = array_map(function($slot) use ($validated, $date) {
                // Handle both array formats: ['start' => '09:00', 'end' => '17:00'] or ['start_time' => '09:00', 'end_time' => '17:00']
                $startTime = $slot['start_time'] ?? $slot['start'] ?? '09:00';
                $endTime = $slot['end_time'] ?? $slot['end'] ?? '17:00';
                
                return [
                    'id' => null, // These are computed slots, not database records
                    'photographer_id' => $validated['photographer_id'],
                    'date' => $date->toDateString(),
                    'day_of_week' => strtolower($date->format('l')),
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'status' => 'available',
                ];
            }, $daySlots);

            // Get booked slots (existing shoots) for this day
            $bookedSlots = $this->availabilityService->getBookedSlots(
                $validated['photographer_id'],
                $date,
                $excludeShootId
            );

            $bookedShoots = \App\Models\Shoot::with(['client:id,name', 'services', 'serviceItems.service'])
                ->whereIn('id', array_column($bookedSlots, 'shoot_id'))->get()->keyBy('id');
            $bookedSlots = array_map(function (array $slot) use ($bookedShoots) {
                $shoot = $bookedShoots->get($slot['shoot_id']);
                return $shoot ? array_merge($slot, $this->bookedShootDetails($shoot, $slot['shoot_service_id'] ?? null)) : $slot;
            }, $bookedSlots);

            // Combine available and booked slots
            $allSlots = array_merge($formattedAvailableSlots, $bookedSlots);

            // Sort by start_time
            usort($allSlots, function($a, $b) {
                return strcmp($a['start_time'], $b['start_time']);
            });

            \Log::info('[Availability Check] All slots (available + booked)', [
                'photographer_id' => $validated['photographer_id'],
                'date' => $validated['date'],
                'available_count' => count($formattedAvailableSlots),
                'booked_count' => count($bookedSlots),
                'total_count' => count($allSlots),
            ]);

            return response()->json(['data' => $allSlots, 'hybrid_travel_enabled' => (bool) app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled(), 'travel_feasibility' => $travelFeasibility]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // validate() throws ValidationException (extends Exception); must stay 422.
            throw $e;
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'message' => 'Failed to check availability',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e)
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date' => 'sometimes|nullable|date',
            'day_of_week' => 'sometimes|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'status' => 'sometimes|in:available,unavailable',
            'note' => 'nullable|string|max:1000',
        ]);

        $availability = PhotographerAvailability::findOrFail($id);
        if ($response = $this->denyUnlessCanManageAvailability($request->user(), (int) $availability->photographer_id)) {
            return $response;
        }
        
        // Merge validated data with existing data to get complete values
        $finalData = array_merge([
            'photographer_id' => $availability->photographer_id,
            'date' => $availability->date,
            'day_of_week' => $availability->day_of_week,
            'start_time' => $availability->start_time,
            'end_time' => $availability->end_time,
        ], $validated);

        // Ensure day_of_week is set if date is provided
        if (isset($validated['date'])) {
            $finalData['day_of_week'] = strtolower(date('l', strtotime($finalData['date'])));
            $validated['day_of_week'] = $finalData['day_of_week'];
        }

        if ($this->timeToMinutes($finalData['end_time']) <= $this->timeToMinutes($finalData['start_time'])) {
            return response()->json(['message' => 'End time must be after start time.'], 422);
        }

        $newStatus = $validated['status'] ?? $availability->status ?? 'available';
        if ($newStatus !== 'unavailable') {
            // Check for overlaps only for available slots (excluding the current record)
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
        }

        $availability = LockedWrite::run(fn () => DB::transaction(function () use ($id, $validated, $finalData, $newStatus) {
            $availability = PhotographerAvailability::findOrFail($id);
            if ($newStatus === 'unavailable') {
                app(\App\Services\AvailabilityBlockWindows::class)->preserve(
                    $finalData['photographer_id'], $finalData['date'] ?? null, $finalData['day_of_week'] ?? null,
                    $availability->status === 'available' ? (int) $id : null
                );
            }
            $availability->update($validated);
            return $availability;
        }), 'availability.update');
        
        // Clear availability cache for this photographer
        $this->clearAvailabilityCache($availability->photographer_id);

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
            'availabilities.*.note' => 'nullable|string|max:1000',
        ]);

        if ($response = $this->denyUnlessCanManageAvailability($request->user(), (int) $validated['photographer_id'])) {
            return $response;
        }

        $created = [];
        $errors = [];

        // First, check all slots for overlaps with existing slots
        foreach ($validated['availabilities'] as $index => $availability) {
            $slotStatus = $availability['status'] ?? 'available';
            if ($slotStatus === 'unavailable') {
                continue;
            }
            $day = $availability['day_of_week'] ?? (isset($availability['date']) ? strtolower(date('l', strtotime($availability['date']))) : null);
            
            if ($this->hasOverlap(
                $validated['photographer_id'],
                $availability['start_time'],
                $availability['end_time'],
                $availability['date'] ?? null,
                $day
            )) {
                $slotType = isset($availability['date']) ? "specific date slot" : "recurring slot";
                $errors[] = "Slot #" . ($index + 1) . " ({$slotType}) overlaps with an existing availability";
            }
        }

        // Then, check for overlaps within the batch itself
        for ($i = 0; $i < count($validated['availabilities']); $i++) {
            for ($j = $i + 1; $j < count($validated['availabilities']); $j++) {
                $slot1 = $validated['availabilities'][$i];
                $slot2 = $validated['availabilities'][$j];
                $slot1Status = $slot1['status'] ?? 'available';
                $slot2Status = $slot2['status'] ?? 'available';

                if ($slot1Status === 'unavailable' || $slot2Status === 'unavailable') {
                    continue;
                }
                
                $slot1Date = $slot1['date'] ?? null;
                $slot2Date = $slot2['date'] ?? null;
                $day1 = $slot1['day_of_week'] ?? ($slot1Date ? strtolower(date('l', strtotime($slot1Date))) : null);
                $day2 = $slot2['day_of_week'] ?? ($slot2Date ? strtolower(date('l', strtotime($slot2Date))) : null);
                
                // Check if they're for the same day/date
                $sameDay = false;
                if ($slot1Date && $slot2Date) {
                    $sameDay = $slot1Date === $slot2Date;
                } elseif ($day1 && $day2) {
                    $sameDay = $day1 === $day2;
                } elseif ($slot1Date && $day2) {
                    $dayOfWeekForDate1 = strtolower(date('l', strtotime($slot1Date)));
                    $sameDay = $dayOfWeekForDate1 === $day2;
                } elseif ($day1 && $slot2Date) {
                    $dayOfWeekForDate2 = strtolower(date('l', strtotime($slot2Date)));
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

        // Keep working-hour preservation and the complete batch atomic.
        $created = LockedWrite::run(fn () => DB::transaction(function () use ($validated) {
            $created = [];
            foreach ($validated['availabilities'] as $availability) {
                $day = $availability['day_of_week'] ?? (isset($availability['date']) ? strtolower(date('l', strtotime($availability['date']))) : null);
                $slotStatus = $availability['status'] ?? 'available';

                if ($slotStatus === 'unavailable') {
                    app(\App\Services\AvailabilityBlockWindows::class)->preserve(
                        $validated['photographer_id'], $availability['date'] ?? null, $day, null
                    );
                }
                $created[] = PhotographerAvailability::create([
                    'photographer_id' => $validated['photographer_id'],
                    'date' => $availability['date'] ?? null,
                    'day_of_week' => $day,
                    'start_time' => $availability['start_time'],
                    'end_time' => $availability['end_time'],
                    'status' => $availability['status'] ?? 'available',
                    'note' => $availability['note'] ?? null,
                ]);
            }
        
            return $created;
        }), 'availability.bulk-store');
        // Clear availability cache for this photographer
        $this->clearAvailabilityCache($validated['photographer_id']);

        return response()->json(['data' => $created], 201);
    }

    /**
     * Replace the photographer's recurring (dateless) available windows.
     * Dated overrides, bookings, and unavailable blocks are left untouched.
     * Used by the Availability "Default schedule" editor so adjusting Mon–Fri
     * 9–5 does not trip create-only bulk overlap errors.
     */
    public function replaceWeekly(Request $request)
    {
        $validated = $request->validate([
            'photographer_id' => 'required|exists:users,id',
            'availabilities' => 'present|array',
            'availabilities.*.day_of_week' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'availabilities.*.start_time' => 'required|date_format:H:i',
            'availabilities.*.end_time' => 'required|date_format:H:i|after:availabilities.*.start_time',
            'availabilities.*.status' => 'sometimes|in:available,unavailable',
            'availabilities.*.note' => 'nullable|string|max:1000',
        ]);

        if ($response = $this->denyUnlessCanManageAvailability($request->user(), (int) $validated['photographer_id'])) {
            return $response;
        }

        // Reject within-batch overlaps on the same weekday.
        $errors = [];
        $items = $validated['availabilities'];
        for ($i = 0; $i < count($items); $i++) {
            for ($j = $i + 1; $j < count($items); $j++) {
                if (($items[$i]['day_of_week'] ?? null) !== ($items[$j]['day_of_week'] ?? null)) {
                    continue;
                }
                if ($this->timesOverlap(
                    $items[$i]['start_time'],
                    $items[$i]['end_time'],
                    $items[$j]['start_time'],
                    $items[$j]['end_time']
                )) {
                    $errors[] = 'Slot #' . ($i + 1) . ' and Slot #' . ($j + 1) . ' overlap with each other';
                }
            }
        }
        if (!empty($errors)) {
            return response()->json([
                'message' => 'One or more default schedule slots overlap with each other.',
                'errors' => $errors,
                'error' => 'overlap',
            ], 422);
        }

        $created = DB::transaction(function () use ($validated) {
            $photographerId = (int) $validated['photographer_id'];

            // Only wipe recurring *available* windows. Keep dated overrides,
            // bookings, and recurring unavailable blocks.
            PhotographerAvailability::where('photographer_id', $photographerId)
                ->whereNull('date')
                ->where(function ($query) {
                    $query->where('status', 'available')
                        ->orWhereNull('status');
                })
                ->delete();

            $rows = [];
            foreach ($validated['availabilities'] as $availability) {
                $rows[] = PhotographerAvailability::create([
                    'photographer_id' => $photographerId,
                    'date' => null,
                    'day_of_week' => $availability['day_of_week'],
                    'start_time' => $availability['start_time'],
                    'end_time' => $availability['end_time'],
                    'status' => $availability['status'] ?? 'available',
                    'note' => $availability['note'] ?? null,
                ]);
            }

            return $rows;
        });

        $this->clearAvailabilityCache($validated['photographer_id']);

        return response()->json(['data' => $created], 200);
    }

    public function availablePhotographers(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        // Create cache key from request parameters
        $cacheKey = 'available_photographers_v2_' . md5(json_encode($validated).app(\App\Services\Scheduling\SchedulingBufferSettings::class)->version());
        
        $merged = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addSeconds(30), function () use ($validated) {
            $dayOfWeek = strtolower(date('l', strtotime($validated['date'])));
            // Prefer specific overrides for that date; otherwise use recurring
            $specificOverrides = PhotographerAvailability::whereDate('date', $validated['date'])->get();
            $specificPhotographerIds = $specificOverrides->pluck('photographer_id')->unique();

            $specific = $specificOverrides
                ->filter(fn ($slot) => $slot->start_time < $validated['end_time']
                    && $slot->end_time > $validated['start_time']
                    && $slot->status !== 'unavailable'
                    && $slot->status !== 'booked')
                ->values();

            $recurring = PhotographerAvailability::whereNull('date')
                ->where('day_of_week', $dayOfWeek)
                ->where('start_time', '<', $validated['end_time'])
                ->where('end_time', '>', $validated['start_time'])
                ->where('status', '!=', 'unavailable')
                ->where('status', '!=', 'booked')
                ->whereNotIn('photographer_id', $specificPhotographerIds)
                ->get();

            return $specific->concat($recurring)->values();
        });

        return response()->json(['data' => $merged, 'hybrid_travel_enabled' => (bool) app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled()]);
    }

    public function clearAll(Request $request, $photographerId)
    {
        if ($response = $this->denyUnlessCanManageAvailability($request->user(), (int) $photographerId)) {
            return $response;
        }

        PhotographerAvailability::where('photographer_id', $photographerId)->delete();
        
        // Clear availability cache
        $this->clearAvailabilityCache($photographerId);
        
        return response()->json(['message' => 'All availability cleared']);
    }

    /**
     * Bulk fetch availability for multiple photographers in a single request
     * This optimizes the frontend by reducing N API calls to 1
     */
    public function bulkIndex(Request $request)
    {
        $validated = $request->validate([
            'photographer_ids' => 'required|array',
            'photographer_ids.*' => 'integer|exists:users,id',
            'from_date' => 'sometimes|date',
            'to_date' => 'sometimes|date|after_or_equal:from_date',
        ]);

        if ($response = $this->denyUnlessCanViewAllPhotographers($request->user(), $validated['photographer_ids'])) {
            return $response;
        }

        $photographerIds = $validated['photographer_ids'];
        sort($photographerIds); // Sort for consistent cache key
        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        // Read current rows so edits, deletes and blocks appear immediately in team calendars.
        $query = PhotographerAvailability::whereIn('photographer_id', $photographerIds);
        if ($fromDate && $toDate) {
            $query->where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('date', [$fromDate, $toDate])->orWhereNull('date');
            });
        }
        $grouped = $query->get()->groupBy('photographer_id')->map(fn ($slots) => $slots->values());
        // Team calendars retain the same authorized visit details as individual calendars.
        $rangeStart = \Carbon\Carbon::parse($fromDate ?? now()->toDateString());
        $rangeEnd = \Carbon\Carbon::parse($toDate ?? $rangeStart->copy()->addMonth()->toDateString());
        $grouped = collect($photographerIds)->mapWithKeys(function ($photographerId) use ($grouped, $rangeStart, $rangeEnd) {
            $slots = $this->availabilityService->getBookedSlotsForRange((int) $photographerId, $rangeStart, $rangeEnd);
            return [$photographerId => collect($grouped[$photographerId] ?? [])->concat($this->enrichBookedSlots($slots))->values()];
        });
        return response()->json(['data' => $grouped]);
    }

    /**
     * Get booked slots with shoot details for a photographer within a date range
     * Returns shoot information for calendar display
     */
    public function getBookedSlotsWithDetails(Request $request)
    {
        $validated = $request->validate([
            'photographer_id' => 'required|exists:users,id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        if ($response = $this->denyUnlessCanViewAvailability($request->user(), (int) $validated['photographer_id'])) {
            return $response;
        }

        $photographerId = $validated['photographer_id'];
        $fromDate = \Carbon\Carbon::parse($validated['from_date']);
        $toDate = \Carbon\Carbon::parse($validated['to_date']);
        $slots = $this->availabilityService->getBookedSlotsForRange((int) $photographerId, $fromDate, $toDate);
        return response()->json(['data' => $this->enrichBookedSlots($slots)]);
    }

    protected function enrichBookedSlots(array $slots): \Illuminate\Support\Collection
    {
        $shoots = \App\Models\Shoot::with(['client:id,name,email,phone', 'services:id,name,price'])
            ->whereIn('id', array_column($slots, 'shoot_id'))->get()->keyBy('id');
        $bookedSlots = collect($slots)->map(function (array $slot) use ($shoots) {
            $shoot = $shoots->get($slot['shoot_id']);
            $slot['id'] = 'shoot_'.$shoot->id.'_'.($slot['shoot_service_id'] ?? 'main');
            $slot['shoot_details'] = [
                'id' => $shoot->id,
                'title' => $shoot->title ?? 'Shoot #'.$shoot->id,
                'address' => $shoot->property_address ?? $shoot->address,
                'latitude' => $shoot->latitude,
                'longitude' => $shoot->longitude,
                'shoot_status' => $shoot->status,
                'client' => $shoot->client ? $shoot->client->only(['id', 'name', 'email', 'phone']) : null,
                'services' => $shoot->services->map(fn ($service) => $service->only(['id', 'name', 'price']))->all(),
                'duration_minutes' => $slot['duration_minutes'],
            ];
            return $slot;
        })->values();

        return $bookedSlots;
    }

    /**
     * Calculate shoot duration from shoot model
     */
    protected function calculateShootDurationFromShoot($shoot): int
    {
        return app(\App\Services\Shoots\ShootDurationResolver::class)->forShoot($shoot);
    }
    
    /**
     * Get comprehensive availability info for photographers for booking
     * Returns: distance, available slots, booked slots, net available slots
     */
    public function getPhotographersForBooking(Request $request)
    {
        // This route remains public. Resolve optional bearer authentication explicitly;
        // the default web guard does not read tokens without auth:sanctum middleware.
        // Prefer the request user so API impersonation keeps the target's permissions.
        $authUser = $request->user() ?? auth('sanctum')->user();
        if ($authUser !== null) {
            $actors = collect([$request->attributes->get('original_admin_user'), $authUser])
                ->filter()->unique('id');
            foreach ($actors as $actor) {
                // Match protected API account gates for both the original actor and target.
                if (! $actor->isAccountEligibleForAuthentication()) {
                    $actor->currentAccessToken()?->delete();
                    auth('web')->logout();

                    return response()->json(['message' => 'This account is no longer active.'], 401);
                }
                // The verification middleware skips public routes; use its shared policy here.
                $verification = app(\App\Services\Users\EmailVerificationPilot::class)->status($actor);
                if ($verification['required']) {
                    return response()->json([
                        'message' => 'Verify your current email address to continue.',
                        'code' => 'email_verification_required',
                        'email_verification' => $verification,
                    ], 403);
                }
            }
            $request->setUserResolver(fn ($guard = null) => $authUser);
        }

        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'sometimes|string',
            'duration_minutes' => 'sometimes|integer|min:5',
            'shoot_address' => 'required|string',
            'shoot_city' => 'required|string',
            'shoot_state' => 'required|string',
            'shoot_zip' => 'sometimes|string',
            'shoot_latitude' => 'sometimes|numeric|between:-90,90',
            'shoot_longitude' => 'sometimes|numeric|between:-180,180',
            'photographer_ids' => 'sometimes|array',
            'service_ids' => 'sometimes|array', // Filter by service capabilities
            'service_ids.*' => 'integer|exists:services,id',
            'require_all_services' => 'sometimes|boolean', // If true, photographer must have ALL services
            'include_location_exceptions' => 'sometimes|boolean',
        ]);
        $includeLocationExceptions = filter_var($validated['include_location_exceptions'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($includeLocationExceptions) {
            abort_unless(app(\App\Services\Scheduling\TravelScheduleAccess::class)
                ->canOverride(['action_mode' => 'create'], null, $authUser), 403,
                'Only an authorized administrator or sales rep may review location exceptions.');
        }
        $travelFeasibility = $this->selectedTravelFeasibility($request);

        // Public/anonymous callers and clients receive no other clients' booking details.
        $privilegedRoles = ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative', 'photographer', 'editor'];
        $includeSensitiveSlotFields = app(\App\Services\Shoots\ShootAuthorizationSupport::class)->hasRole($authUser, $privilegedRoles);
        $isAuthenticatedClient = $authUser !== null && strtolower((string) $authUser->role) === 'client';

        $date = \Carbon\Carbon::parse($validated['date']);
        $shootAddress = $validated['shoot_address'];
        $shootCity = $validated['shoot_city'];
        $shootState = $validated['shoot_state'];
        $shootZip = $validated['shoot_zip'] ?? '';
        $requestedTime = $validated['time'] ?? null;
        $requestedDuration = (int) ($validated['duration_minutes'] ?? config('availability.default_shoot_duration_minutes', 60));
        $photographerIds = $validated['photographer_ids'] ?? null;
        $serviceIds = $validated['service_ids'] ?? null;
        $requireAllServices = $validated['require_all_services'] ?? true;

        // Get photographers
        $query = \App\Models\User::where('role', 'photographer')
            ->with(['serviceAreas:id,kind,value,label'])
            ->select('id', 'role', 'name', 'email', 'metadata', 'address', 'city', 'state', 'zip', 'locked_at', 'account_status');
        
        if ($photographerIds) {
            $query->whereIn('id', $photographerIds);
        }
        
        $photographers = $query->get()->filter(fn ($photographer) => $photographer->isAccountEligibleForAuthentication());
        
        // A location exception never restores a photographer without verified capability.
        if ($serviceIds && !empty($serviceIds)) {
            $requiredServices = \App\Models\Service::with('category')->whereIn('id', $serviceIds)->get()
                ->filter(fn ($service) => $service->requiresPhotographer());
            $capability = app(\App\Services\Photographers\PhotographerServiceEligibility::class);
            $photographers = $photographers->filter(function ($photographer) use ($requiredServices, $requireAllServices, $capability) {
                if ($requiredServices->isEmpty()) return true;
                if ($requireAllServices) {
                    return $requiredServices->every(fn ($service) => $capability->canPerform($photographer, $service));
                } else {
                    return $requiredServices->contains(fn ($service) => $capability->canPerform($photographer, $service));
                }
            });
        }

        $result = [];

        // Sorting/display distance must not delay schedule choices. Required
        // radius checks retain their existing criteria within one shared budget.
        $distanceLookupPolicy = ['network_allowed' => RadiusEligibility::enforced(), 'deadline' => microtime(true) + 3];

        foreach ($photographers as $photographer) {
            $photographerId = $photographer->id;
            $metadata = is_string($photographer->metadata) 
                ? json_decode($photographer->metadata, true) 
                : ($photographer->metadata ?? []);

            // Get photographer's home address
            $homeAddress = $photographer->address
                ?? $metadata['address']
                ?? $metadata['homeAddress']
                ?? '';
            $homeCity = $photographer->city
                ?? $metadata['city']
                ?? '';
            $homeState = $photographer->state
                ?? $metadata['state']
                ?? '';
            $homeZip = $photographer->zip
                ?? $metadata['zip']
                ?? $metadata['zipcode']
                ?? '';

            // Get photographer's shoots on this local date (UTC storage can land on neighbor day)
            $scheduleInstants = app(ScheduleInstantResolver::class);
            $candidateShoots = \App\Models\Shoot::where(fn ($query) => $query->where('photographer_id', $photographerId)
                ->orWhereHas('serviceItems', fn ($items) => $items->where('photographer_id', $photographerId)))
                ->whereNotNull('scheduled_at')
                ->whereBetween('scheduled_at', [
                    $date->copy()->subDay()->startOfDay(),
                    $date->copy()->addDay()->endOfDay(),
                ])
                ->whereIn('status', [
                    \App\Services\ShootWorkflowService::STATUS_SCHEDULED,
                    \App\Services\ShootWorkflowService::STATUS_IN_PROGRESS,
                ])
                ->with(['services', 'client:id,name', 'serviceItems.service', 'photographer:id,timezone'])
                ->orderBy('scheduled_at')
                ->get();
            $shootsOnDate = $candidateShoots->filter(function ($shoot) use ($scheduleInstants, $date) {
                $local = $scheduleInstants->forShoot($shoot);
                return $local && $local->toDateString() === $date->toDateString();
            })->values();

            // Determine origin address for distance calculation
            $originAddress = $homeAddress;
            $originCity = $homeCity;
            $originState = $homeState;
            $originZip = $homeZip;
            $originLatitude = $metadata['latitude'] ?? $metadata['lat'] ?? null;
            $originLongitude = $metadata['longitude'] ?? $metadata['lng'] ?? null;
            $distanceFrom = 'home';
            $previousShootId = null;
            $visits = $this->availabilityService->getBookedSlots($photographerId, $date);

            // Independent service photographers/times are authoritative too. The
            // shoot's primary photographer and anchor do not describe every visit.
            if ($requestedTime) {
                $previousVisit = collect($visits)->filter(fn ($visit) =>
                    $this->timeToMinutes($visit['end_time']) <= $this->timeToMinutes($this->convertTo24Hour($requestedTime)))
                    ->sortByDesc(fn ($visit) => $this->timeToMinutes($visit['end_time']))->first();
                $lastShoot = $previousVisit ? \App\Models\Shoot::find($previousVisit['shoot_id']) : null;
                if ($lastShoot) {
                    $originAddress = $lastShoot->address;
                    $originCity = $lastShoot->city;
                    $originState = $lastShoot->state;
                    $originZip = $lastShoot->zip;
                    $originLatitude = $lastShoot->latitude;
                    $originLongitude = $lastShoot->longitude;
                    $distanceFrom = 'previous_shoot';
                    $previousShootId = $lastShoot->id;
                }
            }

            $distanceMiles = null;
            $distanceData = null;
            $hasOrigin = $originAddress || ($originCity && $originState);
            $hasShoot = $shootAddress && $shootCity && $shootState;
            if ($hasOrigin && $hasShoot) {
                try {
                    $distanceData = app(AddressLookupService::class)->getDistance(
                        [
                            'address' => $originAddress,
                            'city' => $originCity,
                            'state' => $originState,
                            'zip' => $originZip,
                            'latitude' => is_numeric($originLatitude) ? (float) $originLatitude : null,
                            'longitude' => is_numeric($originLongitude) ? (float) $originLongitude : null,
                        ],
                        [
                            'address' => $shootAddress,
                            'city' => $shootCity,
                            'state' => $shootState,
                            'zip' => $shootZip,
                            'latitude' => isset($validated['shoot_latitude']) && is_numeric($validated['shoot_latitude'])
                                ? (float) $validated['shoot_latitude']
                                : null,
                            'longitude' => isset($validated['shoot_longitude']) && is_numeric($validated['shoot_longitude'])
                                ? (float) $validated['shoot_longitude']
                                : null,
                        ],
                        $distanceLookupPolicy
                    );

                    if (is_array($distanceData)) {
                        if (isset($distanceData['distance_value'])) {
                            $distanceMiles = round(((float) $distanceData['distance_value']) / 1609.34, 1);
                        } elseif (isset($distanceData['distance'])) {
                            $distanceMiles = (float) preg_replace('/[^0-9.]/', '', (string) $distanceData['distance']);
                        }
                    }
                } catch (\Throwable $e) {
                    $distanceMiles = null;
                }
            }

            if ($distanceMiles === null) {
                // Token-based comparison: extract unique alphanumeric tokens, sort, compare
                // This handles cases where address field contains embedded city/state/zip
                $extractTokens = function ($address, $city, $state, $zip) {
                    $raw = strtolower(implode(' ', array_filter([
                        $address, $city, $state, $zip,
                    ], function ($v) { return is_string($v) && trim($v) !== ''; })));
                    // Split into tokens, keep only alphanumeric
                    $tokens = preg_split('/[^a-z0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
                    $tokens = array_unique($tokens);
                    sort($tokens);
                    return $tokens;
                };
                $originTokens = $extractTokens($originAddress, $originCity, $originState, $originZip);
                $shootTokens = $extractTokens($shootAddress, $shootCity, $shootState, $shootZip);
                if (!empty($originTokens) && !empty($shootTokens) && $originTokens === $shootTokens) {
                    $distanceMiles = 0.0;
                }
            }

            if ($distanceMiles === null) {
                $shootLatitude = $validated['shoot_latitude'] ?? null;
                $shootLongitude = $validated['shoot_longitude'] ?? null;

                if (is_numeric($originLatitude) && abs((float) $originLatitude) <= 90
                    && is_numeric($originLongitude) && abs((float) $originLongitude) <= 180
                    && is_numeric($shootLatitude) && is_numeric($shootLongitude)) {
                    $distanceMiles = round($this->calculateMilesBetweenCoordinates(
                        (float) $originLatitude,
                        (float) $originLongitude,
                        (float) $shootLatitude,
                        (float) $shootLongitude
                    ), 1);
                }
            }

            // Get availability slots for this day
            // Priority: specific date overrides > recurring day rules
            $dayOfWeek = strtolower($date->format('l'));
            $unavailableSlots = collect();
            
            // First check for specific date slots
            $specificDateQuery = PhotographerAvailability::where('photographer_id', $photographerId)
                ->where('date', $date->toDateString())
                ->get();
            $specificDateSlots = $specificDateQuery
                ->filter(fn ($slot) => $slot->status !== 'unavailable')
                ->values();
            $specificUnavailableSlots = $specificDateQuery
                ->filter(fn ($slot) => $slot->status === 'unavailable')
                ->values();
            
            // If no specific date slots, fall back to recurring day rules
            if ($specificDateSlots->isEmpty()) {
                $weeklyAvailabilityQuery = PhotographerAvailability::where('photographer_id', $photographerId)
                    ->whereNull('date')
                    ->where('day_of_week', $dayOfWeek)
                    ->get();
                $availabilitySlots = $weeklyAvailabilityQuery
                    ->filter(fn ($slot) => $slot->status !== 'unavailable')
                    ->values();
                $unavailableSlots = $weeklyAvailabilityQuery
                    ->filter(fn ($slot) => $slot->status === 'unavailable')
                    ->merge($specificUnavailableSlots)
                    ->values();
            } else {
                $availabilitySlots = $specificDateSlots;
                $unavailableSlots = $specificUnavailableSlots;
            }

            // Align with assertWithinAvailabilityBounds / isWithinEffectiveWindow:
            // when no configured available hours exist, apply Backend_Fallback_Hours
            // so Book Shoot does not mark the photographer as unavailable solely
            // because they have not set a weekly schedule yet.
            if ($availabilitySlots->isEmpty()) {
                $availabilitySlots = collect([(object) [
                    'start_time' => config('availability.fallback_start_time', '09:00'),
                    'end_time' => config('availability.fallback_end_time', '18:00'),
                    'date' => null,
                    'day_of_week' => $dayOfWeek,
                    'status' => 'available',
                ]]);
            }
            
            \Log::debug('Availability slots for booking', [
                'photographer_id' => $photographerId,
                'date' => $date->toDateString(),
                'day_of_week' => $dayOfWeek,
                'slots_count' => $availabilitySlots->count(),
                'slots' => $availabilitySlots->map(fn($s) => [
                    'start' => $s->start_time,
                    'end' => $s->end_time,
                    'date' => $s->date,
                    'day_of_week' => $s->day_of_week,
                    'status' => $s->status,
                ])->toArray(),
            ]);

            // Use the same per-photographer service intervals as conflict validation.
            $visitShoots = \App\Models\Shoot::with(['client:id,name', 'services', 'serviceItems.service'])
                ->whereIn('id', array_column($visits, 'shoot_id'))->get()->keyBy('id');
            $bookedSlots = array_map(function (array $visit) use ($includeSensitiveSlotFields, $visitShoots) {
                $shoot = $visitShoots->get($visit['shoot_id']);
                $slot = ['start_time' => $visit['start_time'], 'end_time' => $visit['end_time'],
                    'status' => $shoot?->status ?? 'booked'];
                if ($includeSensitiveSlotFields && $shoot) {
                    $slot = array_merge($slot, $this->bookedShootDetails($shoot, $visit['shoot_service_id']));
                }
                return $slot;
            }, $visits);

            // Calculate net available slots (availability minus bookings)
            $netAvailableSlots = [];
            $buffer = (app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled() ? 0 : (int) config('availability.buffer_time_minutes', 15));
            $bufferedBookings = array_map(function (array $slot) use ($buffer) {
                $start = max(0, $this->timeToMinutes($slot['start_time']) - $buffer);
                $end = min(1440, $this->timeToMinutes($slot['end_time']) + $buffer);
                return ['start_time' => sprintf('%02d:%02d', intdiv($start, 60), $start % 60),
                    'end_time' => sprintf('%02d:%02d', intdiv($end, 60), $end % 60)];
            }, $bookedSlots);
            $blockedSlots = array_merge(
                $bufferedBookings,
                $unavailableSlots->map(fn ($slot) => [
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'status' => $slot->status,
                ])->values()->toArray()
            );

            foreach ($availabilitySlots as $slot) {
                $slotStart = $slot->start_time;
                $slotEnd = $slot->end_time;
                
                // Subtract booked times from this slot
                $availableRanges = $this->subtractBookedTimes($slotStart, $slotEnd, $blockedSlots);
                
                foreach ($availableRanges as $range) {
                    $netAvailableSlots[] = [
                        'start_time' => $range['start'],
                        'end_time' => $range['end'],
                    ];
                }
            }

            // Check if requested time is available
            $isAvailableAtTime = false;
            if ($requestedTime) {
                $time24 = $this->convertTo24Hour($requestedTime);
                
                \Log::debug('Checking availability at time', [
                    'photographer_id' => $photographerId,
                    'requested_time_raw' => $requestedTime,
                    'requested_time_24h' => $time24,
                    'net_available_slots' => $netAvailableSlots,
                ]);
                
                foreach ($netAvailableSlots as $slot) {
                    $requestStartMinutes = $this->timeToMinutes($time24);
                    $inRange = $requestStartMinutes >= $this->timeToMinutes($slot['start_time'])
                        && $requestStartMinutes + $requestedDuration <= $this->timeToMinutes($slot['end_time']);
                    \Log::debug('Time range check', [
                        'time' => $time24,
                        'slot_start' => $slot['start_time'],
                        'slot_end' => $slot['end_time'],
                        'in_range' => $inRange,
                    ]);
                    if ($inRange) {
                        $buffer = (app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled() ? 0 : (int) config('availability.buffer_time_minutes', 15));
                        foreach ($bookedSlots as $booked) {
                            if ($requestStartMinutes < $this->timeToMinutes($booked['end_time']) + $buffer
                                && $requestStartMinutes + $requestedDuration > $this->timeToMinutes($booked['start_time']) - $buffer) {
                                $inRange = false;
                                break;
                            }
                        }
                    }
                    if ($inRange) {
                        $isAvailableAtTime = true;
                        break;
                    }
                }
            }

            // Option B — service-radius gating (flag-gated; config: availability.radius_enforcement).
            // When enforcement is ON, exclude a photographer whose service radius does not cover this
            // shoot's distance. Completely no-op (historical behavior) when the flag is OFF.
            $radiusEval = ['eligible' => true, 'reason' => 'enforcement_off', 'distance' => $distanceMiles, 'radius' => null];
            if (RadiusEligibility::enforced()) {
                $radiusEval = RadiusEligibility::evaluate(
                    RadiusEligibility::radiusFromMetadata($metadata),
                    is_numeric($distanceMiles) ? (float) $distanceMiles : null
                );
                // A sequence of nearby appointments cannot extend the profile's
                // home territory indefinitely. Check the home radius separately.
                if ($distanceFrom === 'previous_shoot') {
                    [$homeLat, $homeLng] = RadiusEligibility::coordsFromMetadata($metadata);
                    $homeDistance = null;
                    try {
                        $homeLookup = app(AddressLookupService::class)->getDistance(
                            ['address' => $homeAddress, 'city' => $homeCity, 'state' => $homeState, 'zip' => $homeZip,
                                'latitude' => $homeLat, 'longitude' => $homeLng],
                            ['address' => $shootAddress, 'city' => $shootCity, 'state' => $shootState, 'zip' => $shootZip,
                                'latitude' => $validated['shoot_latitude'] ?? null, 'longitude' => $validated['shoot_longitude'] ?? null],
                            $distanceLookupPolicy
                        );
                        if (is_numeric($homeLookup['distance_value'] ?? null)) $homeDistance = round((float) $homeLookup['distance_value'] / 1609.34, 1);
                    } catch (\Throwable) {
                    }
                    $homeDistance ??= RadiusEligibility::distanceMiles($homeLat, $homeLng,
                        $validated['shoot_latitude'] ?? null, $validated['shoot_longitude'] ?? null);
                    $homeEval = RadiusEligibility::evaluate(RadiusEligibility::radiusFromMetadata($metadata), $homeDistance);
                    if (! $homeEval['eligible']) $radiusEval = $homeEval + ['origin' => 'home'];
                }
                if (!$radiusEval['eligible']) {
                    \Log::debug('Radius gate excluded photographer from for-booking', [
                        'photographer_id' => $photographerId,
                        'reason' => $radiusEval['reason'],
                        'distance' => $radiusEval['distance'],
                        'radius' => $radiusEval['radius'],
                    ]);
                    if (! $includeLocationExceptions) continue;
                }
            }

            // Service-area labels are administrator-authored public descriptions.
            // Authenticated clients only receive distance; home city/state and area
            // labels stay off their booking payload. Anonymous callers still receive
            // an authored label when one exists, and never a home-profile fallback.
            $serviceAreaLabel = $photographer->serviceAreas
                ->map(fn ($area) => trim((string) ($area->label ?? '')))
                ->first(fn ($label) => $label !== '');
            $serviceAreaLabel = $isAuthenticatedClient ? null : ($serviceAreaLabel ?: null);

            // Job window for last/next uses real shoot duration (default 60).
            // Availability booked blocks remain 120 elsewhere — unchanged here.
            $jobStartLocal = null;
            if ($requestedTime) {
                $time24 = $this->convertTo24Hour($requestedTime);
                $timeParts = [];
                if (preg_match('/^(\d{2}):(\d{2})$/', $time24, $timeParts)) {
                    $jobStartLocal = $date->copy()->setTime((int) $timeParts[1], (int) $timeParts[2], 0);
                }
            }

            $jobPayload = [
                'address' => $shootAddress,
                'city' => $shootCity,
                'state' => $shootState,
                'zip' => $shootZip,
                'latitude' => $validated['shoot_latitude'] ?? null,
                'longitude' => $validated['shoot_longitude'] ?? null,
            ];

            $map = null;
            if ($includeSensitiveSlotFields) {
                try {
                    $map = app(\App\Services\Photographers\PhotographerPickerMapBuilder::class)->build(
                        $photographer,
                        $jobPayload,
                        $jobStartLocal,
                        $requestedDuration,
                        $shootsOnDate,
                        is_numeric($distanceMiles) ? (float) $distanceMiles : null,
                        is_array($distanceData) ? $distanceData : null,
                        $distanceLookupPolicy,
                        $hasOrigin && $hasShoot
                    );
                } catch (\Throwable $e) {
                    // Fail open: picker still lists photographers without map bits.
                    \Log::debug('Photographer picker map enrichment skipped', [
                        'photographer_id' => $photographerId,
                        'error' => $e->getMessage(),
                    ]);
                    $map = null;
                }
            }

            $photographerResult = [
                'id' => $photographerId,
                'hybrid_travel_enabled' => (bool) app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled(),
                'travel_check_required' => (bool) app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled(),
                'name' => $photographer->name,
                'eligible_service_ids' => isset($requiredServices) ? $requiredServices
                    ->filter(fn ($service) => $capability->canPerform($photographer, $service))->pluck('id')->values()->all() : [],
                'distance' => $distanceMiles,
                'miles_to_job' => is_numeric($distanceMiles) ? (float) $distanceMiles : null,
                'distance_from' => $distanceFrom,
                'location_exception_required' => ! $radiusEval['eligible'],
                'radius_eligibility' => $radiusEval,
                'service_area_label' => $serviceAreaLabel,
                'availability_slots' => $availabilitySlots->map(fn($s) => [
                    'start_time' => $s->start_time,
                    'end_time' => $s->end_time,
                    'date' => $s->date,
                    'day_of_week' => $s->day_of_week,
                    'status' => $s->status ?? 'available',
                ])->values()->toArray(),
                'unavailable_slots' => $unavailableSlots->map(fn($s) => [
                    'start_time' => $s->start_time,
                    'end_time' => $s->end_time,
                    'date' => $s->date,
                    'day_of_week' => $s->day_of_week,
                    'status' => $s->status ?? 'unavailable',
                ])->values()->toArray(),
                'booked_slots' => $bookedSlots,
                'net_available_slots' => $netAvailableSlots,
                'is_available_at_time' => $isAvailableAtTime,
                'has_availability' => count($netAvailableSlots) > 0,
                'shoots_count_today' => $shootsOnDate->count(),
                'map' => $map,
            ];


            if ($includeSensitiveSlotFields) {
                $photographerResult['previous_shoot_id'] = $previousShootId;
            }

            $result[] = $photographerResult;
        }

        $jobPin = app(\App\Services\Photographers\PhotographerPickerMapBuilder::class)->jobPin([
            'address' => $shootAddress,
            'city' => $shootCity,
            'state' => $shootState,
            'zip' => $shootZip,
            'latitude' => $validated['shoot_latitude'] ?? null,
            'longitude' => $validated['shoot_longitude'] ?? null,
        ], $distanceLookupPolicy);

        return response()->json([
            'data' => $result,
            'job' => $jobPin,
            'hybrid_travel_enabled' => (bool) app(\App\Services\Scheduling\SchedulingBufferSettings::class)->enabled(),
            'travel_feasibility' => $travelFeasibility,
        ]);
    }

    /** Legacy calendar endpoints can evaluate one selected itinerary on demand. */
    private function selectedTravelFeasibility(Request $request): ?array
    {
        if (! $request->has('selected_visit')) {
            return null;
        }
        $data = $request->validate(['selected_visit' => 'required|array']);
        abort_unless($request->user(), 401);
        $rateKey = 'selected-travel-preview:'.$request->user()->id;
        abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateKey, 60), 429, 'Too many travel checks. Please try again shortly.');
        \Illuminate\Support\Facades\RateLimiter::hit($rateKey, 60);
        $preview = Request::create('/api/photographer/availability/feasibility', 'POST', $data['selected_visit']);
        $preview->setUserResolver(fn () => $request->user());
        $response = app(ScheduleFeasibilityController::class)($preview, app(\App\Services\Scheduling\ScheduleFeasibilityService::class));

        return $response->getData(true)['data'];
    }

    /**
     * Subtract booked times from an availability slot
     */
    /** Identifying details are only added after the caller's availability authorization. */
    protected function bookedShootDetails(\App\Models\Shoot $shoot, ?int $serviceItemId = null): array
    {
        $item = $serviceItemId ? $shoot->serviceItems->firstWhere('id', $serviceItemId) : null;
        $services = $item ? collect([$item->service])->filter() : $shoot->services->filter(function ($service) use ($shoot) {
            return ($service->pivot?->is_deliverable ?? true)
                && ($service->pivot?->workflow_status ?? null) !== 'cancelled'
                && ($service->pivot?->delivery_status ?? null) !== 'cancelled'
                && $service->requiresPhotographer()
                && (! $service->pivot?->photographer_id || (int) $service->pivot->photographer_id === (int) $shoot->photographer_id);
        });
        return [
            'shoot_id' => $shoot->id,
            'client_name' => $shoot->client?->name,
            'address' => $shoot->property_address ?? $shoot->address,
            'city' => $shoot->city,
            'state' => $shoot->state,
            'zip' => $shoot->zip,
            'services' => $services->map(fn ($service) => ['id' => $service->id, 'name' => $service->name])->values()->all(),
        ];
    }

    protected function subtractBookedTimes(string $slotStart, string $slotEnd, array $bookedSlots): array
    {
        $slotStartMin = $this->timeToMinutes($slotStart);
        $slotEndMin = $this->timeToMinutes($slotEnd);
        
        // Sort booked slots by start time
        usort($bookedSlots, fn($a, $b) => 
            $this->timeToMinutes($a['start_time']) - $this->timeToMinutes($b['start_time'])
        );

        $availableRanges = [];
        $currentStart = $slotStartMin;

        foreach ($bookedSlots as $booked) {
            $bookedStart = $this->timeToMinutes($booked['start_time']);
            $bookedEnd = $this->timeToMinutes($booked['end_time']);

            // Skip if booked slot is outside our range
            if ($bookedEnd <= $slotStartMin || $bookedStart >= $slotEndMin) {
                continue;
            }

            // If there's a gap before this booking, add it as available
            if ($currentStart < $bookedStart && $bookedStart <= $slotEndMin) {
                $availableRanges[] = [
                    'start' => $this->minutesToTimeStr($currentStart),
                    'end' => $this->minutesToTimeStr(min($bookedStart, $slotEndMin)),
                ];
            }

            // Move current start past this booking
            $currentStart = max($currentStart, $bookedEnd);
        }

        // Add remaining time after last booking
        if ($currentStart < $slotEndMin) {
            $availableRanges[] = [
                'start' => $this->minutesToTimeStr($currentStart),
                'end' => $this->minutesToTimeStr($slotEndMin),
            ];
        }

        return $availableRanges;
    }

    protected function minutesToTimeStr(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }

    protected function calculateMilesBetweenCoordinates(float $originLatitude, float $originLongitude, float $destinationLatitude, float $destinationLongitude): float
    {
        $earthRadiusMiles = 3958.8;
        $latDelta = deg2rad($destinationLatitude - $originLatitude);
        $lonDelta = deg2rad($destinationLongitude - $originLongitude);
        $originLat = deg2rad($originLatitude);
        $destinationLat = deg2rad($destinationLatitude);
        $a = sin($latDelta / 2) ** 2 + cos($originLat) * cos($destinationLat) * sin($lonDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMiles * $c;
    }

    protected function convertTo24Hour(string $time): string
    {
        // Trim whitespace and normalize
        $time = trim($time);
        
        // Handle 12-hour format with AM/PM (e.g., "09:15 AM", "9:15AM", "09:15am")
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $time, $matches)) {
            $hours = (int)$matches[1];
            $minutes = $matches[2];
            $period = strtoupper($matches[3]);
            if ($period === 'PM' && $hours !== 12) $hours += 12;
            if ($period === 'AM' && $hours === 12) $hours = 0;
            return sprintf('%02d:%s', $hours, $minutes);
        }
        
        // Handle 24-hour format (e.g., "09:15", "9:15")
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            $hours = (int)$matches[1];
            $minutes = $matches[2];
            return sprintf('%02d:%s', $hours, $minutes);
        }
        
        // Fallback - try to parse with Carbon
        try {
            $parsed = \Carbon\Carbon::parse($time);
            return $parsed->format('H:i');
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'warning');
            return $time;
        }
    }

    protected function isTimeInRange(string $time, string $start, string $end): bool
    {
        // Ensure times are properly formatted
        $time = trim($time);
        $start = trim($start);
        $end = trim($end);
        
        $timeMin = $this->timeToMinutes($time);
        $startMin = $this->timeToMinutes($start);
        $endMin = $this->timeToMinutes($end);
        
        $result = $timeMin >= $startMin && $timeMin < $endMin;
        
        \Log::debug('isTimeInRange calculation', [
            'time' => $time,
            'start' => $start,
            'end' => $end,
            'timeMin' => $timeMin,
            'startMin' => $startMin,
            'endMin' => $endMin,
            'result' => $result,
        ]);
        
        return $result;
    }

    /**
     * Clear availability cache for a photographer
     * Since we can't use wildcards with Cache::forget, we'll use a tag-based approach
     * or clear based on a pattern. For now, we'll use a simple key prefix.
     * 
     * @param int $photographerId
     */
    protected function clearAvailabilityCache(int $photographerId): void
    {
        // Clear cache using tags if your cache driver supports it (Redis, Memcached)
        // Otherwise, we'll need to track cache keys or use a different strategy
        try {
            Cache::tags(["availability:{$photographerId}"])->flush();
        } catch (\Exception $e) {
            // Cache driver doesn't support tags (like file cache)
            // We can't easily clear all related keys, but that's okay
            // The cache will expire naturally after 5 minutes
            \App\Services\ApiErrorResponder::log($e, 'info');
        }
    }

    protected function denyUnlessCanManageAvailability(?User $user, int $photographerId)
    {
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($this->isStaffAvailabilityManager($user)) {
            return null;
        }

        if ($user->role === 'photographer' && (int) $user->id === $photographerId) {
            return null;
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    protected function denyUnlessCanViewAvailability(?User $user, int $photographerId)
    {
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($this->isStaffAvailabilityViewer($user)) {
            return null;
        }

        if ($user->role === 'photographer' && (int) $user->id === $photographerId) {
            return null;
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    protected function denyUnlessCanManageAllPhotographers(?User $user, array $photographerIds)
    {
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($this->isStaffAvailabilityManager($user)) {
            return null;
        }

        if ($user->role === 'photographer') {
            $requestedIds = collect($photographerIds)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($requestedIds->count() === 1 && (int) $requestedIds->first() === (int) $user->id) {
                return null;
            }
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    protected function denyUnlessCanViewAllPhotographers(?User $user, array $photographerIds)
    {
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($this->isStaffAvailabilityViewer($user)) {
            return null;
        }

        if ($user->role === 'photographer') {
            $requestedIds = collect($photographerIds)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($requestedIds->count() === 1 && (int) $requestedIds->first() === (int) $user->id) {
                return null;
            }
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    protected function isStaffAvailabilityManager(User $user): bool
    {
        return in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true);
    }

    protected function isStaffAvailabilityViewer(User $user): bool
    {
        return app(\App\Services\Shoots\ShootAuthorizationSupport::class)->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'salesRep']);
    }



}
