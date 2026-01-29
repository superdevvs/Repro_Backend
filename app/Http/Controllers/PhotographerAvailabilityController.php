<?php

namespace App\Http\Controllers;

use App\Models\PhotographerAvailability;
use App\Services\PhotographerAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            
            // Get all specific date slots and filter in PHP to avoid DB-specific DAYNAME()
            $specificSlots = (clone $query)
                ->whereNotNull('date')
                ->get()
                ->filter(function ($slot) use ($dayOfWeek) {
                    if (!$slot->date) {
                        return false;
                    }
                    return strtolower(date('l', strtotime($slot->date))) === $dayOfWeek;
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
        
        // Clear availability cache for this photographer
        $this->clearAvailabilityCache($availability->photographer_id);

        return response()->json(['data' => $availability], 201);
    }

    public function destroy($id)
    {
        $availability = PhotographerAvailability::findOrFail($id);
        $photographerId = $availability->photographer_id;
        
        $availability->delete();
        
        // Clear availability cache for this photographer
        $this->clearAvailabilityCache($photographerId);
        
        return response()->json(['message' => 'Availability removed']);
    }

    public function checkAvailability(Request $request)
    {
        try {
            $validated = $request->validate([
                'photographer_id' => 'required|exists:users,id',
                'date' => 'required|date',
            ]);

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
                $date // Same date for from and to (single day)
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
                $date
            );

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

            return response()->json(['data' => $allSlots]);
        } catch (\Exception $e) {
            \Log::error('[Availability Check] Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'photographer_id' => $request->input('photographer_id'),
                'date' => $request->input('date'),
            ]);

            return response()->json([
                'message' => 'Failed to check availability',
                'error' => $e->getMessage()
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
                $slotType = isset($availability['date']) ? "specific date slot" : "recurring slot";
                $errors[] = "Slot #" . ($index + 1) . " ({$slotType}) overlaps with an existing availability";
            }
        }

        // Then, check for overlaps within the batch itself
        for ($i = 0; $i < count($validated['availabilities']); $i++) {
            for ($j = $i + 1; $j < count($validated['availabilities']); $j++) {
                $slot1 = $validated['availabilities'][$i];
                $slot2 = $validated['availabilities'][$j];
                
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
        
        // Clear availability cache for this photographer
        $this->clearAvailabilityCache($validated['photographer_id']);

        return response()->json(['data' => $created], 201);
    }

    public function availablePhotographers(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        // Create cache key from request parameters
        $cacheKey = 'available_photographers_' . md5(json_encode($validated));
        
        $merged = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addSeconds(30), function () use ($validated) {
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

            return $specific->concat($recurring)->values();
        });

        return response()->json(['data' => $merged]);
    }

    public function clearAll($photographerId)
    {
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

        $photographerIds = $validated['photographer_ids'];
        sort($photographerIds); // Sort for consistent cache key
        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        // Create cache key based on parameters
        $cacheKey = 'bulk_availability_' . md5(json_encode($photographerIds) . $fromDate . $toDate);
        
        $grouped = \Illuminate\Support\Facades\Cache::remember($cacheKey, 60, function () use ($photographerIds, $fromDate, $toDate) {
            // Get all availability slots for the given photographers
            $query = PhotographerAvailability::whereIn('photographer_id', $photographerIds);
            
            // Optionally filter by date range for specific date slots
            if ($fromDate && $toDate) {
                $query->where(function ($q) use ($fromDate, $toDate) {
                    // Include slots with specific dates within range
                    $q->whereBetween('date', [$fromDate, $toDate])
                      // Or include recurring slots (no specific date)
                      ->orWhereNull('date');
                });
            }

            $availabilities = $query->get();

            // Group by photographer_id for easier frontend processing
            return $availabilities->groupBy('photographer_id')->map(function ($slots) {
                return $slots->values();
            });
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

        $photographerId = $validated['photographer_id'];
        $fromDate = \Carbon\Carbon::parse($validated['from_date']);
        $toDate = \Carbon\Carbon::parse($validated['to_date']);

        // Get shoots (bookings) for this photographer within the date range
        $shoots = \App\Models\Shoot::where('photographer_id', $photographerId)
            ->whereNotNull('scheduled_at')
            ->whereDate('scheduled_at', '>=', $fromDate->toDateString())
            ->whereDate('scheduled_at', '<=', $toDate->toDateString())
            ->whereIn('status', [
                \App\Services\ShootWorkflowService::STATUS_SCHEDULED,
                \App\Services\ShootWorkflowService::STATUS_IN_PROGRESS,
                \App\Services\ShootWorkflowService::STATUS_EDITING,
            ])
            ->with(['client:id,name,email,phone', 'services:id,name,price'])
            ->orderBy('scheduled_at')
            ->get();

        $bookedSlots = $shoots->map(function ($shoot) {
            $scheduledAt = \Carbon\Carbon::parse($shoot->scheduled_at);
            $durationMinutes = $this->calculateShootDurationFromShoot($shoot);
            $endTime = $scheduledAt->copy()->addMinutes($durationMinutes);

            return [
                'id' => 'shoot_' . $shoot->id,
                'shoot_id' => $shoot->id,
                'photographer_id' => $shoot->photographer_id,
                'date' => $scheduledAt->toDateString(),
                'day_of_week' => strtolower($scheduledAt->format('l')),
                'start_time' => $scheduledAt->format('H:i'),
                'end_time' => $endTime->format('H:i'),
                'status' => 'booked',
                'shoot_details' => [
                    'id' => $shoot->id,
                    'title' => $shoot->title ?? 'Shoot #' . $shoot->id,
                    'address' => $shoot->property_address ?? $shoot->address,
                    'shoot_status' => $shoot->status,
                    'client' => $shoot->client ? [
                        'id' => $shoot->client->id,
                        'name' => $shoot->client->name,
                        'email' => $shoot->client->email,
                        'phone' => $shoot->client->phone,
                    ] : null,
                    'services' => $shoot->services->map(fn($s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'price' => $s->price,
                    ])->toArray(),
                    'notes' => $shoot->notes,
                    'duration_minutes' => $durationMinutes,
                ],
            ];
        });

        return response()->json(['data' => $bookedSlots]);
    }

    /**
     * Calculate shoot duration from shoot model
     */
    protected function calculateShootDurationFromShoot($shoot): int
    {
        $defaultDurationMinutes = config('availability.default_shoot_duration_minutes', 120);
        $minDurationMinutes = config('availability.min_shoot_duration_minutes', 60);
        $maxDurationMinutes = config('availability.max_shoot_duration_minutes', 240);

        $services = $shoot->services;
        if ($services && $services->isNotEmpty()) {
            $maxDurationHours = $services->max('delivery_time') ?? ($defaultDurationMinutes / 60);
            $calculatedDurationMinutes = (int)($maxDurationHours * 60);
            return min(max($calculatedDurationMinutes, $minDurationMinutes), $maxDurationMinutes);
        }

        return $defaultDurationMinutes;
    }
    
    /**
     * Get comprehensive availability info for photographers for booking
     * Returns: distance, available slots, booked slots, net available slots
     */
    public function getPhotographersForBooking(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'sometimes|string',
            'shoot_address' => 'required|string',
            'shoot_city' => 'required|string',
            'shoot_state' => 'required|string',
            'shoot_zip' => 'sometimes|string',
            'photographer_ids' => 'sometimes|array',
        ]);

        $date = \Carbon\Carbon::parse($validated['date']);
        $shootAddress = $validated['shoot_address'];
        $shootCity = $validated['shoot_city'];
        $shootState = $validated['shoot_state'];
        $shootZip = $validated['shoot_zip'] ?? '';
        $requestedTime = $validated['time'] ?? null;
        $photographerIds = $validated['photographer_ids'] ?? null;

        // Get photographers
        $query = \App\Models\User::where('role', 'photographer')
            ->select('id', 'name', 'email', 'metadata');
        
        if ($photographerIds) {
            $query->whereIn('id', $photographerIds);
        }
        
        $photographers = $query->get();

        $result = [];

        foreach ($photographers as $photographer) {
            $photographerId = $photographer->id;
            $metadata = is_string($photographer->metadata) 
                ? json_decode($photographer->metadata, true) 
                : ($photographer->metadata ?? []);

            // Get photographer's home address
            $homeAddress = $metadata['address'] ?? $metadata['homeAddress'] ?? '';
            $homeCity = $metadata['city'] ?? '';
            $homeState = $metadata['state'] ?? '';
            $homeZip = $metadata['zip'] ?? $metadata['zipcode'] ?? '';

            // Get photographer's shoots on this date (to determine origin for distance)
            $shootsOnDate = \App\Models\Shoot::where('photographer_id', $photographerId)
                ->whereDate('scheduled_at', $date->toDateString())
                ->whereNotNull('scheduled_at')
                ->whereIn('status', [
                    \App\Services\ShootWorkflowService::STATUS_SCHEDULED,
                    \App\Services\ShootWorkflowService::STATUS_IN_PROGRESS,
                ])
                ->orderBy('scheduled_at')
                ->get();

            // Determine origin address for distance calculation
            $originAddress = $homeAddress;
            $originCity = $homeCity;
            $originState = $homeState;
            $originZip = $homeZip;
            $distanceFrom = 'home';
            $previousShootId = null;

            // If there are shoots before the requested time, use the last one's location
            if ($requestedTime && $shootsOnDate->isNotEmpty()) {
                $requestedDateTime = $date->copy();
                $timeParts = [];
                if (preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)/i', $requestedTime, $timeParts)) {
                    $hours = (int)$timeParts[1];
                    $minutes = (int)$timeParts[2];
                    $period = strtoupper($timeParts[3]);
                    if ($period === 'PM' && $hours !== 12) $hours += 12;
                    if ($period === 'AM' && $hours === 12) $hours = 0;
                    $requestedDateTime->setTime($hours, $minutes);
                }

                $shootsBefore = $shootsOnDate->filter(function ($shoot) use ($requestedDateTime) {
                    $shootTime = \Carbon\Carbon::parse($shoot->scheduled_at);
                    $duration = $this->calculateShootDurationFromShoot($shoot);
                    $shootEndTime = $shootTime->copy()->addMinutes($duration);
                    return $shootEndTime <= $requestedDateTime;
                })->sortByDesc('scheduled_at');

                if ($shootsBefore->isNotEmpty()) {
                    $lastShoot = $shootsBefore->first();
                    $originAddress = $lastShoot->property_address ?? $lastShoot->address ?? $homeAddress;
                    $originCity = $lastShoot->city ?? $homeCity;
                    $originState = $lastShoot->state ?? $homeState;
                    $originZip = $lastShoot->zip ?? $homeZip;
                    $distanceFrom = 'previous_shoot';
                    $previousShootId = $lastShoot->id;
                }
            }

            // Get availability slots for this day
            // Priority: specific date overrides > recurring day rules
            $dayOfWeek = strtolower($date->format('l'));
            
            // First check for specific date slots
            $specificDateSlots = PhotographerAvailability::where('photographer_id', $photographerId)
                ->where('date', $date->toDateString())
                ->where(function ($q) {
                    $q->where('status', '!=', 'unavailable')
                      ->orWhereNull('status');
                })
                ->get();
            
            // If no specific date slots, fall back to recurring day rules
            if ($specificDateSlots->isEmpty()) {
                $availabilitySlots = PhotographerAvailability::where('photographer_id', $photographerId)
                    ->whereNull('date')
                    ->where('day_of_week', $dayOfWeek)
                    ->where(function ($q) {
                        $q->where('status', '!=', 'unavailable')
                          ->orWhereNull('status');
                    })
                    ->get();
            } else {
                $availabilitySlots = $specificDateSlots;
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

            // Get booked slots for this day
            $bookedSlots = $shootsOnDate->map(function ($shoot) {
                $scheduledAt = \Carbon\Carbon::parse($shoot->scheduled_at);
                $duration = $this->calculateShootDurationFromShoot($shoot);
                $endTime = $scheduledAt->copy()->addMinutes($duration);
                
                return [
                    'shoot_id' => $shoot->id,
                    'start_time' => $scheduledAt->format('H:i'),
                    'end_time' => $endTime->format('H:i'),
                    'title' => $shoot->title ?? 'Shoot #' . $shoot->id,
                    'address' => $shoot->property_address ?? $shoot->address,
                    'status' => $shoot->status,
                ];
            })->values()->toArray();

            // Calculate net available slots (availability minus bookings)
            $netAvailableSlots = [];
            foreach ($availabilitySlots as $slot) {
                $slotStart = $slot->start_time;
                $slotEnd = $slot->end_time;
                
                // Subtract booked times from this slot
                $availableRanges = $this->subtractBookedTimes($slotStart, $slotEnd, $bookedSlots);
                
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
                    $inRange = $this->isTimeInRange($time24, $slot['start_time'], $slot['end_time']);
                    \Log::debug('Time range check', [
                        'time' => $time24,
                        'slot_start' => $slot['start_time'],
                        'slot_end' => $slot['end_time'],
                        'in_range' => $inRange,
                    ]);
                    if ($inRange) {
                        $isAvailableAtTime = true;
                        break;
                    }
                }
            }

            $result[] = [
                'id' => $photographerId,
                'name' => $photographer->name,
                'email' => $photographer->email,
                'home_address' => [
                    'address' => $homeAddress,
                    'city' => $homeCity,
                    'state' => $homeState,
                    'zip' => $homeZip,
                ],
                'origin_address' => [
                    'address' => $originAddress,
                    'city' => $originCity,
                    'state' => $originState,
                    'zip' => $originZip,
                ],
                'distance_from' => $distanceFrom,
                'previous_shoot_id' => $previousShootId,
                'availability_slots' => $availabilitySlots->map(fn($s) => [
                    'start_time' => $s->start_time,
                    'end_time' => $s->end_time,
                    'date' => $s->date,
                    'day_of_week' => $s->day_of_week,
                ])->values()->toArray(),
                'booked_slots' => $bookedSlots,
                'net_available_slots' => $netAvailableSlots,
                'is_available_at_time' => $isAvailableAtTime,
                'has_availability' => count($netAvailableSlots) > 0,
                'shoots_count_today' => $shootsOnDate->count(),
            ];
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Subtract booked times from an availability slot
     */
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
            \Log::warning('Failed to convert time to 24-hour format', ['time' => $time, 'error' => $e->getMessage()]);
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
            \Log::info("Cache tags not supported, cache will expire naturally", [
                'photographer_id' => $photographerId
            ]);
        }
    }



}
