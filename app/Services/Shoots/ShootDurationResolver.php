<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Services\Schedule\ScheduleInstantResolver;
use Carbon\Carbon;
use DateTimeInterface;

/** Appointment time only: delivery turnaround never determines a booked window. */
class ShootDurationResolver
{
    public function defaultMinutes(): int
    {
        return max(1, (int) config('availability.default_shoot_duration_minutes', 60));
    }

    public function forService(?Service $service, ?int $sqft = null): int
    {
        return $service?->getShootDurationMinutes($sqft) ?? $this->defaultMinutes();
    }

    public function forServiceItem(ShootService $item): int
    {
        // A booked line is a snapshot. Later catalogue/default edits must not shorten it.
        if ((int) $item->duration_minutes > 0) {
            return (int) $item->duration_minutes;
        }

        $item->loadMissing(['service', 'unit']);
        if ($item->service && ! $item->service->requiresPhotographerForBooking($item->created_at)) {
            return 0;
        }
        $sqft = $item->unit?->sqft;
        if ($sqft === null && $item->shoot_id) {
            $sqft = $item->shoot?->propertySqft();
        }

        // Null execution rows predate catalogue defaults. Keep their previous
        // tier/default window without rewriting history when the catalogue changes.
        return $item->service?->getShootDurationMinutes($sqft === null ? null : (int) $sqft, false) ?? $this->defaultMinutes();
    }

    /** Snapshot new booking lengths before both availability validation and persistence. */
    public function withDurations(array $rows, ?int $sqft = null): array
    {
        $models = Service::whereIn('id', collect($rows)->map(fn (array $row) => $row['service_id'] ?? $row['id'] ?? null)->filter()->unique())
            ->get()->keyBy('id');

        return array_map(function (array $row) use ($models, $sqft) {
            $minutes = (int) ($row['duration_minutes'] ?? 0);
            $row['duration_minutes'] = $minutes > 0 ? $minutes : $this->forService(
                $models->get($row['service_id'] ?? $row['id'] ?? null),
                isset($row['sqft']) ? (int) $row['sqft'] : $sqft,
            );

            return $row;
        }, $rows);
    }

    public function forServices(
        array $rows,
        ?int $sqft = null,
        ?DateTimeInterface $appointmentStart = null,
        ?string $timezone = null,
        ?int $photographerId = null,
    ): int {
        $windows = $this->windowsForServices($rows, $sqft, $appointmentStart, $timezone, $photographerId);

        return $this->envelope($windows, $appointmentStart ? Carbon::instance($appointmentStart) : null, $rows === []);
    }

    /** Same-start capture lines are one visit; distinct start times stay separate. */
    public function windowsForServices(
        array $rows,
        ?int $sqft = null,
        ?DateTimeInterface $appointmentStart = null,
        ?string $timezone = null,
        ?int $photographerId = null,
    ): array {
        $models = Service::whereIn('id', collect($rows)->map(fn (array $row) => $row['service_id'] ?? $row['id'] ?? null)->filter()->unique())
            ->get()->keyBy('id');
        $windows = [];
        foreach ($rows as $index => $row) {
            $service = $models->get($row['service_id'] ?? $row['id'] ?? null);
            if (! $this->isActiveCapture($row, $service, isset($row['created_at']))) {
                continue;
            }
            if ($photographerId && ! empty($row['photographer_id']) && (int) $row['photographer_id'] !== $photographerId) {
                continue;
            }
            $minutes = (int) ($row['duration_minutes'] ?? 0);
            $windows[] = [
                'start' => empty($row['scheduled_at'])
                    ? ($appointmentStart ? Carbon::instance($appointmentStart) : null)
                    : Carbon::parse($row['scheduled_at'], $timezone),
                'minutes' => $minutes > 0 ? $minutes : $this->forService($service, isset($row['sqft']) ? (int) $row['sqft'] : $sqft),
                'photographer_id' => (int) ($row['photographer_id'] ?? $photographerId ?? 0),
                'row_indexes' => [$index],
            ];
        }

        return $this->groupWindows($windows);
    }

    public function forShoot(Shoot $shoot, ?int $photographerId = null): int
    {
        $photographerId ??= $shoot->photographer_id ? (int) $shoot->photographer_id : null;
        $anchor = (! $photographerId || $photographerId === (int) $shoot->photographer_id) && $shoot->scheduled_at
            ? app(ScheduleInstantResolver::class)->forShoot($shoot) : null;

        return $this->envelope($this->windowsForShoot($shoot, $photographerId), $anchor, false);
    }

    /** Resolved onsite intervals, without travel or artificial time between visits. */
    public function windowsForShoot(Shoot $shoot, ?int $photographerId = null): array
    {
        $shoot->loadMissing(['serviceItems.service', 'serviceItems.unit', 'services']);
        $instants = app(ScheduleInstantResolver::class);
        $windows = [];
        if ($shoot->serviceItems->isNotEmpty()) {
            foreach ($shoot->serviceItems->sortBy('id') as $item) {
                if (! $this->isActiveCapture($item->getAttributes(), $item->service, true)) {
                    continue;
                }
                $assignedId = (int) ($item->photographer_id ?: $shoot->photographer_id);
                if ($photographerId && $assignedId !== $photographerId) {
                    continue;
                }
                $windows[] = [
                    'start' => $instants->forServiceItem($shoot, $item),
                    'minutes' => $this->forServiceItem($item),
                    'photographer_id' => $assignedId,
                    'row_indexes' => [$item->id],
                ];
            }
        } else {
            foreach ($shoot->services as $service) {
                $pivot = $service->pivot?->getAttributes() ?? [];
                if (! $this->isActiveCapture($pivot, $service, true)) {
                    continue;
                }
                $assignedId = (int) ($pivot['photographer_id'] ?? $shoot->photographer_id);
                if ($photographerId && $assignedId !== $photographerId) {
                    continue;
                }
                $minutes = (int) ($pivot['duration_minutes'] ?? 0);
                $windows[] = [
                    'start' => $instants->forShoot($shoot),
                    'minutes' => $minutes > 0 ? $minutes : $service->getShootDurationMinutes($shoot->propertySqft(), false),
                    'photographer_id' => $assignedId,
                    'row_indexes' => [],
                ];
            }
            // Unclassified legacy shoots retain their existing default window.
            if ($shoot->services->isEmpty() && (! $photographerId || $photographerId === (int) $shoot->photographer_id)) {
                $windows[] = ['start' => $instants->forShoot($shoot), 'minutes' => $this->defaultMinutes(),
                    'photographer_id' => (int) $shoot->photographer_id, 'row_indexes' => []];
            }
        }

        return $this->groupWindows($windows);
    }

    /** Calendar service events run sequentially inside a shared same-start visit. */
    public function scheduledAtForServiceItem(Shoot $shoot, ShootService $item): ?Carbon
    {
        $scheduledAt = $item->scheduled_at ?: $shoot->scheduled_at;
        if (! $scheduledAt) {
            return null;
        }
        $shoot->loadMissing('serviceItems.service');
        $offset = 0;
        foreach ($shoot->serviceItems->sortBy('id') as $other) {
            if ((int) $other->id === (int) $item->id) {
                break;
            }
            if ($this->isActiveCapture($other->getAttributes(), $other->service, true)
                && (int) ($other->photographer_id ?: $shoot->photographer_id) === (int) ($item->photographer_id ?: $shoot->photographer_id)
                && ($other->scheduled_at ?: $shoot->scheduled_at)?->getTimestamp() === $scheduledAt->getTimestamp()) {
                $offset += $this->forServiceItem($other);
            }
        }

        return $scheduledAt->copy()->addMinutes($offset);
    }

    public function photographerIdForCalendar(Shoot $shoot, ?int $viewerId): ?int
    {
        $shoot->loadMissing('serviceItems.service');
        if ($viewerId && ($viewerId === (int) $shoot->photographer_id || $shoot->serviceItems->contains(
            fn (ShootService $item) => (int) $item->photographer_id === $viewerId
                && $this->isActiveCapture($item->getAttributes(), $item->service, true)
        ))) {
            return $viewerId;
        }

        return $shoot->photographer_id ? (int) $shoot->photographer_id : null;
    }

    /** Raw stored instant/clock; the calendar boundary applies the established timezone policy. */
    public function scheduledAtForShoot(Shoot $shoot, ?int $photographerId = null): ?Carbon
    {
        $photographerId ??= $shoot->photographer_id ? (int) $shoot->photographer_id : null;
        if ((! $photographerId || $photographerId === (int) $shoot->photographer_id) && $shoot->scheduled_at) {
            return $shoot->scheduled_at->copy();
        }
        $shoot->loadMissing('serviceItems.service');

        return $shoot->serviceItems->filter(fn (ShootService $item) => $this->isActiveCapture($item->getAttributes(), $item->service, true)
            && (! $photographerId || (int) ($item->photographer_id ?: $shoot->photographer_id) === $photographerId)
        )->map(fn (ShootService $item) => $item->scheduled_at ?: $shoot->scheduled_at)
            ->filter()->sortBy(fn (Carbon $at) => $at->getTimestamp())->first();
    }

    private function isActiveCapture(array $row, ?Service $service, bool $booked = false): bool
    {
        return (! array_key_exists('is_deliverable', $row) || $row['is_deliverable'] === null || (bool) $row['is_deliverable'])
            && ($row['workflow_status'] ?? null) !== ShootService::WORKFLOW_CANCELLED
            && ($row['delivery_status'] ?? null) !== ShootService::DELIVERY_CANCELLED
            && ($service ? ($booked
                ? $service->requiresPhotographerForBooking($row['created_at'] ?? null)
                : $service->requiresPhotographer()) : (bool) ($row['photographer_required'] ?? true));
    }

    private function groupWindows(array $windows): array
    {
        $groups = [];
        foreach ($windows as $window) {
            if ($window['minutes'] <= 0) {
                continue;
            }
            $key = ($window['photographer_id'] ?? 0).':'.($window['start']?->getTimestamp() ?? 'unscheduled');
            if (! isset($groups[$key])) {
                $groups[$key] = $window;
            } else {
                // Bundles are one line with their own duration. Only additional booked
                // capture lines add time; prices, delivery and quantity do not multiply it.
                $groups[$key]['minutes'] += $window['minutes'];
                $groups[$key]['row_indexes'] = array_merge($groups[$key]['row_indexes'], $window['row_indexes']);
            }
        }

        return array_values($groups);
    }

    private function envelope(array $windows, ?Carbon $anchor, bool $useDefault = true): int
    {
        $anchor ??= collect($windows)->pluck('start')->filter()->sortBy(fn (Carbon $at) => $at->getTimestamp())->first();
        $minutes = 0;
        foreach ($windows as $window) {
            $start = $window['start'];
            if (! $anchor || ! $start) {
                $minutes = max($minutes, $window['minutes']);
                continue;
            }
            $start = $start->copy()->setTimezone($anchor->timezone);
            if ($start->toDateString() !== $anchor->toDateString()) {
                continue;
            }
            $end = $start->copy()->addMinutes($window['minutes']);
            $minutes = max($minutes, (int) ceil(($end->getTimestamp() - $anchor->getTimestamp()) / 60));
        }

        return $minutes > 0 ? $minutes : ($useDefault ? $this->defaultMinutes() : 0);
    }
}
