<?php

namespace App\Services\Scheduling;

use App\Models\Shoot;
use App\Services\Shoots\ShootDurationResolver;
use Illuminate\Validation\ValidationException;

/** Only the selected visit's booked capture lines may change duration. */
class DayScheduleDurations
{
    public function items(Shoot $shoot, array $lineIds): array
    {
        return $shoot->serviceItems->whereIn('id', $lineIds)->map(function ($line) {
            return ['key' => (string) $line->id,
                'name' => $line->service?->name.($line->unit ? ' · '.$line->unit->label : ''),
                'duration_minutes' => app(ShootDurationResolver::class)->forServiceItem($line),
                'min_minutes' => (int) config('availability.min_shoot_duration_minutes', 5),
                'max_minutes' => (int) config('availability.max_shoot_duration_minutes', 300)];
        })->values()->all();
    }

    public function resolve(Shoot $shoot, array $window, array $changes): int
    {
        $items = collect($this->items($shoot, $window['row_indexes']))->keyBy('key');
        $seen = [];
        foreach ($changes as $change) {
            $key = (string) $change['shoot_service_id'];
            $item = $items->get($key);
            if (! $item || isset($seen[$key]) || $change['duration_minutes'] < $item['min_minutes'] || $change['duration_minutes'] > $item['max_minutes']) {
                throw ValidationException::withMessages(['schedule_adjustments' => 'Choose a valid duration for a service in the selected visit.']);
            }
            $seen[$key] = true;
            $items->put($key, [...$item, 'duration_minutes' => (int) $change['duration_minutes']]);
        }

        return $items->isNotEmpty() ? (int) $items->sum('duration_minutes') : (int) $window['minutes'];
    }
}
