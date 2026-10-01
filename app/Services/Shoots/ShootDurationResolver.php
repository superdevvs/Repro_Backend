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
        $models = Service::whereIn('id', collect($rows)->map(fn (array $row) => $row['service_id'] ?? $row['id'] ?? null)->filter()->unique())
            ->get()->keyBy('id');
        $windows = [];
        foreach ($rows as $row) {
            $service = $models->get($row['service_id'] ?? $row['id'] ?? null);
            if (! $this->isActiveCapture($row, $service)) {
                continue;
            }
            if ($photographerId && ! empty($row['photographer_id']) && (int) $row['photographer_id'] !== $photographerId) {
                continue;
            }
            $minutes = (int) ($row['duration_minutes'] ?? 0);
            $windows[] = [
                'start' => empty($row['scheduled_at']) ? null : Carbon::parse($row['scheduled_at'], $timezone),
                'minutes' => $minutes > 0 ? $minutes : $this->forService($service, isset($row['sqft']) ? (int) $row['sqft'] : $sqft),
            ];
        }

        return $this->envelope($windows, $appointmentStart ? Carbon::instance($appointmentStart) : null);
    }

    public function forShoot(Shoot $shoot, ?int $photographerId = null): int
    {
        $shoot->loadMissing(['serviceItems.service', 'serviceItems.unit', 'services']);
        $photographerId ??= $shoot->photographer_id ? (int) $shoot->photographer_id : null;
        $instants = app(ScheduleInstantResolver::class);
        // Match the calendar builder when the aggregate has no appointment yet:
        // its event begins at the earliest scheduled service, not a date-only midnight.
        $usesParentStart = ! $photographerId || $photographerId === (int) $shoot->photographer_id;
        $anchor = $usesParentStart && $shoot->scheduled_at ? $instants->forShoot($shoot) : null;
        $windows = [];

        if ($shoot->serviceItems->isNotEmpty()) {
            foreach ($shoot->serviceItems as $item) {
                if (! $this->isActiveCapture($item->getAttributes(), $item->service)) {
                    continue;
                }
                if ($photographerId && (int) ($item->photographer_id ?: $shoot->photographer_id) !== $photographerId) {
                    continue;
                }
                $windows[] = [
                    'start' => $instants->forServiceItem($shoot, $item),
                    'minutes' => $this->forServiceItem($item),
                ];
            }
        } else {
            // Legacy/in-memory shoots may expose services without separate execution rows.
            foreach ($shoot->services as $service) {
                $pivot = $service->pivot?->getAttributes() ?? [];
                if (! $this->isActiveCapture($pivot, $service)) {
                    continue;
                }
                if ($photographerId && (int) ($pivot['photographer_id'] ?? $shoot->photographer_id) !== $photographerId) {
                    continue;
                }
                $minutes = (int) ($pivot['duration_minutes'] ?? 0);
                $windows[] = [
                    'start' => null,
                    'minutes' => $minutes > 0 ? $minutes : $service->getShootDurationMinutes($shoot->propertySqft(), false),
                ];
            }
        }

        return $this->envelope($windows, $anchor);
    }

    public function photographerIdForCalendar(Shoot $shoot, ?int $viewerId): ?int
    {
        $shoot->loadMissing('serviceItems.service');
        if ($viewerId && ($viewerId === (int) $shoot->photographer_id || $shoot->serviceItems->contains(
            fn (ShootService $item) => (int) $item->photographer_id === $viewerId
                && $this->isActiveCapture($item->getAttributes(), $item->service)
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

        return $shoot->serviceItems->filter(fn (ShootService $item) => $this->isActiveCapture($item->getAttributes(), $item->service)
            && (! $photographerId || (int) ($item->photographer_id ?: $shoot->photographer_id) === $photographerId)
        )->map(fn (ShootService $item) => $item->scheduled_at ?: $shoot->scheduled_at)
            ->filter()->sortBy(fn (Carbon $at) => $at->getTimestamp())->first();
    }

    private function isActiveCapture(array $row, ?Service $service): bool
    {
        return (! array_key_exists('is_deliverable', $row) || $row['is_deliverable'] === null || (bool) $row['is_deliverable'])
            && ($row['workflow_status'] ?? null) !== ShootService::WORKFLOW_CANCELLED
            && ($row['delivery_status'] ?? null) !== ShootService::DELIVERY_CANCELLED
            && ($service?->requiresPhotographer() ?? (bool) ($row['photographer_required'] ?? true));
    }

    private function envelope(array $windows, ?Carbon $anchor): int
    {
        $anchor ??= collect($windows)->pluck('start')->filter()->sortBy(fn (Carbon $at) => $at->getTimestamp())->first();
        $minutes = 0;
        foreach ($windows as $window) {
            $start = $window['start'];
            if (! $anchor || ! $start) {
                // Same-time services share the appointment; they are not added together.
                $minutes = max($minutes, $window['minutes']);

                continue;
            }
            $start = $start->copy()->setTimezone($anchor->timezone);
            if ($start->toDateString() !== $anchor->toDateString()) {
                // Independent visits on other days have their own service-item conflicts.
                continue;
            }
            $end = $start->copy()->addMinutes($window['minutes']);
            $minutes = max($minutes, (int) ceil(($end->getTimestamp() - $anchor->getTimestamp()) / 60));
        }

        return $minutes > 0 ? $minutes : $this->defaultMinutes();
    }
}
