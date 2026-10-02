<?php

namespace App\Services\Schedule;

use App\Models\Shoot;
use App\Services\Shoots\MultiUnitBookingService;
use App\Services\Shoots\ShootMutationSupportService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Normalize local edit-form fields before either validation or persistence consumes them. */
class ShootScheduleUpdateInput
{
    public function normalize(Shoot $shoot, array $payload): array
    {
        return $this->synchronizeAroundServices($shoot, $this->normalizeTimestamps($shoot, $payload));
    }

    /** Resolve input clocks without changing service inheritance or the booking anchor. */
    public function normalizeTimestamps(Shoot $shoot, array $payload): array
    {
        $timezone = \App\Support\Timezone::canonical(trim((string) (array_key_exists('timezone', $payload) ? $payload['timezone'] : $shoot->timezone)));
        // Existing unzoned bookings use wall-clock storage. Do not reinterpret them.
        if ($timezone === '') {
            return $payload;
        }

        // Normalize explicit service offsets before the service merger stores SQL timestamps.
        foreach (['services', 'service_items', 'service_lines'] as $collection) {
            foreach ($payload[$collection] ?? [] as $index => $service) {
                if (! empty($service['scheduled_at'])) {
                    $serviceId = $service['service_id'] ?? ($collection === 'services' ? ($service['id'] ?? null) : null);
                    $original = $collection === 'service_lines'
                        ? $shoot->serviceItems->firstWhere('id', $service['shoot_service_id'] ?? null)?->scheduled_at
                        : ($serviceId ? $shoot->serviceItems->firstWhere('service_id', $serviceId)?->scheduled_at : null);
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

    /**
     * Services are the schedule source of truth. When the booking-level appointment
     * moves, align the booking-defining service lines; then always recompute shoot
     * scheduled_at/date/time from the earliest scheduled service.
     */
    private function synchronizeAroundServices(Shoot $shoot, array $payload): array
    {
        if (app(MultiUnitBookingService::class)->handles($shoot, $payload)) {
            return $payload;
        }

        $status = $shoot->workflow_status ?: $shoot->status;
        if (! in_array($status, [Shoot::STATUS_REQUESTED, Shoot::STATUS_SCHEDULED], true)) {
            return $payload;
        }

        $fromServices = app(ShootScheduleFromServices::class);
        $support = app(ShootMutationSupportService::class);
        $bookingChanged = $this->bookingScheduleChanged($shoot, $payload, $support);

        if ($bookingChanged && ! empty($payload['scheduled_at'])) {
            $payload = $this->injectAlignedServices(
                $shoot,
                $payload,
                Carbon::parse($payload['scheduled_at'])->utc()
            );
        }

        if (! array_intersect(['services', 'service_items', 'service_lines'], array_keys($payload))) {
            return $payload;
        }

        $earliest = $this->earliestFromPayload($shoot, $payload, $support);
        if (! $earliest) {
            return $payload;
        }

        $timezone = trim((string) ($payload['timezone'] ?? $shoot->timezone));

        return array_replace($payload, $fromServices->shootFieldsFromInstant($earliest, $timezone !== '' ? $timezone : null));
    }

    private function bookingScheduleChanged(Shoot $shoot, array $payload, ShootMutationSupportService $support): bool
    {
        if (array_key_exists('scheduled_at', $payload)) {
            return $support->normalizeDateTimeForDatabase($payload['scheduled_at'])
                !== $shoot->scheduled_at?->format('Y-m-d H:i:s');
        }

        if (array_key_exists('scheduled_date', $payload)) {
            $incoming = $payload['scheduled_date']
                ? Carbon::parse($payload['scheduled_date'])->toDateString()
                : null;
            if ($incoming !== $shoot->scheduled_date?->toDateString()) {
                return true;
            }
        }

        if (array_key_exists('time', $payload)) {
            $incoming = $payload['time']
                ? Carbon::parse($payload['time'])->format('H:i:s')
                : null;
            $current = $shoot->time ? Carbon::parse($shoot->time)->format('H:i:s') : null;

            return $incoming !== $current;
        }

        return false;
    }

    /**
     * Ensure booking-defining service lines in the payload carry the moved
     * schedule so attachServices persists them. Explicit per-service
     * scheduled_at in the same payload wins for that line.
     */
    private function injectAlignedServices(Shoot $shoot, array $payload, Carbon $instant): array
    {
        $fromServices = app(ShootScheduleFromServices::class);
        $defining = $fromServices->bookingDefiningItems($shoot);
        $nullDeliverable = $shoot->serviceItems
            ->filter(fn ($item) => $item->scheduled_at === null && $item->is_deliverable !== false);

        $stamps = $defining->map(fn ($item) => $item->scheduled_at?->format('Y-m-d H:i:s'))
            ->filter()
            ->unique()
            ->values();
        $shootStamp = $shoot->scheduled_at?->format('Y-m-d H:i:s');
        $sharedOrMatched = $stamps->count() <= 1
            || ($shootStamp && $defining->every(
                fn ($item) => $item->scheduled_at?->format('Y-m-d H:i:s') === $shootStamp
            ));

        $targetIso = $instant->copy()->utc()->toIso8601String();
        $alignedByService = [];

        if ($sharedOrMatched || $defining->isEmpty()) {
            foreach ($defining->merge($nullDeliverable) as $item) {
                $alignedByService[(int) $item->service_id] = $targetIso;
            }
        } else {
            $anchor = $shoot->scheduled_at?->copy()->utc()
                ?? $fromServices->earliestInstant($defining);
            $deltaSeconds = $anchor ? ($instant->copy()->utc()->getTimestamp() - $anchor->getTimestamp()) : 0;
            foreach ($defining as $item) {
                $alignedByService[(int) $item->service_id] = $item->scheduled_at
                    ->copy()->utc()->addSeconds($deltaSeconds)->toIso8601String();
            }
            foreach ($nullDeliverable as $item) {
                $alignedByService[(int) $item->service_id] = $targetIso;
            }
        }

        if ($alignedByService === []) {
            return $payload;
        }

        $explicitByService = [];
        foreach (['services' => 'id', 'service_items' => 'service_id'] as $collection => $idKey) {
            foreach ($payload[$collection] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = (int) ($row[$idKey] ?? $row['service_id'] ?? $row['id'] ?? 0);
                if ($id > 0 && array_key_exists('scheduled_at', $row) && $row['scheduled_at'] !== null && $row['scheduled_at'] !== '') {
                    $explicitByService[$id] = true;
                }
            }
        }

        if (! isset($payload['services'])) {
            $payload['services'] = $shoot->serviceItems->map(function ($item) use ($alignedByService, $explicitByService) {
                $id = (int) $item->service_id;
                $row = ['id' => $id];
                if (isset($alignedByService[$id]) && empty($explicitByService[$id])) {
                    $row['scheduled_at'] = $alignedByService[$id];
                } elseif ($item->scheduled_at) {
                    $row['scheduled_at'] = $item->scheduled_at->copy()->utc()->toIso8601String();
                }

                return $row;
            })->values()->all();
        } else {
            // An explicit services array is the desired complete service set.
            // Align retained lines without restoring intentionally removed ones;
            // the service-change guard must still see and confirm each removal.
            foreach ($payload['services'] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? $row['service_id'] ?? 0);
                if ($id > 0 && isset($alignedByService[$id]) && empty($explicitByService[$id])) {
                    $payload['services'][$index]['scheduled_at'] = $alignedByService[$id];
                }
            }
        }

        if (isset($payload['service_items'])) {
            foreach ($payload['service_items'] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = (int) ($row['service_id'] ?? $row['id'] ?? 0);
                if ($id > 0 && isset($alignedByService[$id]) && empty($explicitByService[$id])) {
                    $payload['service_items'][$index]['scheduled_at'] = $alignedByService[$id];
                }
            }
        }

        return $payload;
    }

    private function earliestFromPayload(Shoot $shoot, array $payload, ShootMutationSupportService $support): ?Carbon
    {
        $current = $shoot->serviceItems->keyBy(fn ($item) => (int) $item->service_id);
        $services = $payload['services'] ?? $current->map(fn ($item) => [
            'id' => $item->service_id,
            'scheduled_at' => $item->scheduled_at?->format('Y-m-d H:i:s'),
        ])->values()->all();

        $merged = $support->mergeServiceItemPayload(
            $services,
            $payload['service_items'] ?? null,
            null,
            null,
            true
        );

        // Preserve omitted scheduled_at from current rows so earliest reflects the
        // full booking after a partial service_items edit.
        foreach ($merged as $index => $service) {
            $id = (int) ($service['id'] ?? 0);
            if (! array_key_exists('scheduled_at', $service) && $current->has($id)) {
                $merged[$index]['scheduled_at'] = $current->get($id)->scheduled_at?->format('Y-m-d H:i:s');
            }
        }

        // service_lines (multi-unit) are handled earlier; for single-unit also
        // honor any service_lines shaped rows if present without multi-unit flag.
        foreach ($payload['service_lines'] ?? [] as $line) {
            if (! empty($line['scheduled_at'])) {
                $merged[] = ['id' => (int) ($line['service_id'] ?? 0), 'scheduled_at' => $line['scheduled_at']];
            }
        }

        return app(ShootScheduleFromServices::class)->earliestInstant($merged);
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
