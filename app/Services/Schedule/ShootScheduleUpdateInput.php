<?php

namespace App\Services\Schedule;

use App\Models\Shoot;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Normalize local edit-form fields before either validation or persistence consumes them. */
class ShootScheduleUpdateInput
{
    public function normalize(Shoot $shoot, array $payload): array
    {
        $timezone = trim((string) (array_key_exists('timezone', $payload) ? $payload['timezone'] : $shoot->timezone));
        // Existing unzoned bookings use wall-clock storage. Do not reinterpret them.
        if ($timezone === '') {
            return $payload;
        }

        // Normalize explicit service offsets before the service merger stores SQL timestamps.
        foreach (['services', 'service_items'] as $collection) {
            foreach ($payload[$collection] ?? [] as $index => $service) {
                if (! empty($service['scheduled_at'])) {
                    $serviceId = $service['service_id'] ?? ($collection === 'services' ? ($service['id'] ?? null) : null);
                    $original = $serviceId ? $shoot->serviceItems->firstWhere('service_id', $serviceId)?->scheduled_at : null;
                    $payload[$collection][$index]['scheduled_at'] = $this->parse(
                        (string) $service['scheduled_at'], $timezone, $original,
                        $collection.'.'.$index.'.scheduled_at'
                    )->toIso8601String();
                }
            }
        }
        if (! array_intersect(['scheduled_at', 'scheduled_date', 'time'], array_keys($payload))) {
            return $payload;
        }

        if (array_key_exists('scheduled_at', $payload)) {
            if (! $payload['scheduled_at']) {
                return array_replace($payload, ['scheduled_at' => null, 'scheduled_date' => null, 'time' => null]);
            }
            $value = (string) $payload['scheduled_at'];
            $field = 'scheduled_at';
        } else {
            $current = $shoot->scheduled_at?->copy()->setTimezone($timezone);
            $date = array_key_exists('scheduled_date', $payload)
                ? $payload['scheduled_date'] : ($current?->toDateString() ?? $shoot->scheduled_date?->toDateString());
            $time = array_key_exists('time', $payload)
                ? $payload['time'] : ($current?->format('H:i:s') ?? $shoot->time);
            if (! $date) {
                return array_replace($payload, ['scheduled_at' => null, 'scheduled_date' => null, 'time' => null]);
            }
            $value = Carbon::parse($date)->toDateString().' '.($time ?: '00:00:00');
            $field = 'time';
        }

        $instant = $this->parse($value, $timezone, $shoot->scheduled_at, $field);
        $local = $instant->copy()->setTimezone($timezone);

        return array_replace($payload, [
            'scheduled_at' => $instant->toIso8601String(),
            'scheduled_date' => $local->toDateString(),
            'time' => $local->format('H:i:s'),
        ]);
    }

    private function parse(string $value, string $timezone, ?Carbon $original, string $field): Carbon
    {
        $parsed = date_parse($value);

        return ! empty($parsed['is_localtime'])
            ? Carbon::parse($value)->utc()
            : $this->localInstant($value, $timezone, $original, $field);
    }

    private function localInstant(string $value, string $timezone, ?Carbon $original, string $field): Carbon
    {
        $wall = Carbon::parse($value, 'UTC');
        $stamp = $wall->format('Y-m-d H:i:s');
        // Saving unrelated fields during a repeated DST hour must retain the original occurrence.
        if ($original && $original->copy()->setTimezone($timezone)->format('Y-m-d H:i:s') === $stamp) {
            return $original->copy()->utc();
        }

        $zone = new \DateTimeZone($timezone);
        $transitions = $zone->getTransitions($wall->timestamp - 86400, $wall->timestamp + 86400);
        $offsets = array_unique(array_column($transitions ?: [], 'offset'));
        $candidates = [];
        foreach ($offsets as $offset) {
            $candidate = Carbon::createFromTimestampUTC($wall->timestamp - $offset);
            if ($candidate->copy()->setTimezone($timezone)->format('Y-m-d H:i:s') === $stamp) {
                $candidates[] = $candidate;
            }
        }
        if (count($candidates) !== 1) {
            throw ValidationException::withMessages([
                $field => [count($candidates) === 0
                    ? 'This time does not exist in the shoot timezone because of a daylight-saving change.'
                    : 'This time occurs twice in the shoot timezone. Supply an explicit offset or choose another time.'],
            ]);
        }

        return $candidates[0];
    }
}
