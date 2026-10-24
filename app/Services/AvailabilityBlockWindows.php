<?php

namespace App\Services;

use App\Models\PhotographerAvailability;

/** Keep working hours intact beneath a block so editing/removing it restores open time. */
class AvailabilityBlockWindows
{
    public function preserve(int $photographerId, ?string $date, ?string $weekday, ?int $replacedId): void
    {
        $weekday = $date ? strtolower(date('l', strtotime($date))) : $weekday;
        if ($date) {
            $specific = PhotographerAvailability::where('photographer_id', $photographerId)
                ->whereDate('date', $date)->where('status', 'available')->get();
            if ($specific->isEmpty()) {
                $weekly = PhotographerAvailability::where('photographer_id', $photographerId)
                    ->whereNull('date')->where('day_of_week', $weekday)->where('status', 'available')->get();
                foreach ($weekly as $slot) {
                    PhotographerAvailability::create([
                        'photographer_id' => $photographerId, 'date' => $date, 'day_of_week' => $weekday,
                        'start_time' => $slot->start_time, 'end_time' => $slot->end_time,
                        'status' => 'available', 'note' => $slot->note,
                    ]);
                }
            }
        }
        if ($replacedId) {
            $original = PhotographerAvailability::where('photographer_id', $photographerId)
                ->where('status', 'available')->find($replacedId);
            if ($original) {
                $copy = $original->replicate();
                $copy->save();
            }
        }
    }
}
