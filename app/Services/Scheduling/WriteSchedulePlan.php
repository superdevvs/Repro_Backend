<?php

namespace App\Services\Scheduling;

use App\Models\Shoot;
use App\Services\Shoots\ShootDurationResolver;
use Carbon\Carbon;
use DateTimeInterface;

/** Trusted action adapter: use the same resolved capture windows as the write. */
class WriteSchedulePlan
{
    /** Notes, pricing and unchanged form echoes do not revalidate an old itinerary. */
    public function changesItinerary(array $payload, Shoot $shoot, ?\App\Models\User $actor = null): bool
    {
        if (($payload['action_mode'] ?? 'update') !== 'update') {
            return true;
        }
        foreach (['address', 'city', 'state', 'zip', 'timezone'] as $field) {
            if (array_key_exists($field, $payload) && trim((string) $payload[$field]) !== trim((string) $shoot->{$field})) {
                return true;
            }
        }
        foreach (['status', 'workflow_status'] as $field) {
            if (array_key_exists($field, $payload) && (string) $payload[$field] !== (string) $shoot->{$field}) {
                return true;
            }
        }
        if (! empty($payload['travel_location_confirmed'])) {
            return true;
        }
        $normalize = function (array $rows): array {
            $keys = array_map(fn ($row) => (int) $row['photographer_id'].':'.Carbon::parse($row['scheduled_at'] ?? $row['start'])->getTimestamp().':'.(int) ($row['duration_minutes'] ?? $row['minutes']),
                array_filter($rows, fn ($row) => ! empty($row['photographer_id']) && ! empty($row['scheduled_at'] ?? $row['start'])));
            sort($keys, SORT_STRING);
            return $keys;
        };
        $before = app(ShootDurationResolver::class)->windowsForShoot($shoot);
        $after = app(VisitPlanBuilder::class)->build($payload, $shoot, $actor);

        return $normalize($before) !== $normalize($after);
    }

    public function services(array $payload, array $services, ?DateTimeInterface $at, ?int $photographerId, ?string $timezone, string $mode): array
    {
        $durations = app(ShootDurationResolver::class);
        $resolved = array_map(function (array $row) use ($photographerId) {
            $row['photographer_id'] = $row['photographer_id'] ?? $photographerId;
            return $row;
        }, $services);
        $windows = $durations->windowsForServices($resolved, null, $at, $timezone);
        if ($services === [] && $at && $photographerId) {
            $windows = [['start' => Carbon::instance($at), 'minutes' => $durations->defaultMinutes(),
                'photographer_id' => $photographerId, 'row_indexes' => []]];
        }
        $payload = array_filter($payload, fn ($key) => ! str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);
        $payload['action_mode'] = $mode;
        $windows = $this->normalizeWindowsTimezone($windows, $timezone);
        $payload['_schedule_visits'] = array_values(array_map(fn ($window) => [
            'photographer_id' => $window['photographer_id'],
            'scheduled_at' => $window['start']->toIso8601String(),
            'timezone' => $window['timezone'],
            'duration_minutes' => $window['minutes'],
            'row_indexes' => $window['row_indexes'],
            '_resolved_window' => true,
        ], array_filter($windows, fn ($window) => $window['start'] && $window['photographer_id'])));

        return $payload;
    }

    /** Match ScheduleInstantResolver: explicit zone = instant; unzoned = photographer local clock. */
    public function normalizeWindowsTimezone(array $windows, ?string $timezone): array
    {
        $zones = trim((string) $timezone) === ''
            ? \App\Models\User::whereIn('id', array_column($windows, 'photographer_id'))->pluck('timezone', 'id')->all() : [];
        return array_map(function ($window) use ($timezone, $zones) {
            $zone = $timezone ?: ($zones[$window['photographer_id']] ?? config('app.timezone', 'UTC'));
            $zone = in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
            if ($window['start']) {
                $window['start'] = $timezone ? $window['start']->copy() : $window['start']->copy()->shiftTimezone($zone);
            }
            $window['timezone'] = $zone;
            return $window;
        }, $windows);
    }

    public function storedServices(Shoot $shoot): array
    {
        $shoot->loadMissing(['serviceItems.service', 'serviceItems.unit']);

        return $shoot->serviceItems->map(fn ($line) => [
            'id' => (int) $line->service_id,
            'photographer_id' => $line->photographer_id,
            'scheduled_at' => $line->scheduled_at?->toIso8601String(),
            'duration_minutes' => app(ShootDurationResolver::class)->forServiceItem($line),
            'created_at' => $line->created_at,
            'is_deliverable' => $line->is_deliverable,
            'workflow_status' => $line->workflow_status,
            'delivery_status' => $line->delivery_status,
        ])->all();
    }

    public function returnVisit(Shoot $source, array $options, bool $editOptions = true): array
    {
        $rows = $options[$editOptions ? 'service_items' : 'items'] ?? [];
        $complimentary = ! $editOptions || ! ($options['client_pays'] ?? false);
        $at = $options['scheduled_at'] ?? ($complimentary ? collect($rows)->pluck('scheduled_at')->filter()->first() : null);
        if (! $at && ! empty($options['scheduled_date']) && ! empty($options['time'])) {
            $at = Carbon::parse($options['scheduled_date'].' '.$options['time'], $options['timezone'] ?? $source->timezone);
        }
        $at = $at ? Carbon::parse($at) : null;
        $photographer = $options['photographer_id'] ?? ($editOptions && $complimentary
            ? collect($rows)->pluck('photographer_id')->filter()->first() : null) ?? $source->photographer_id;
        $sourceItems = $source->serviceItems()->get()->keyBy('id');
        $services = array_map(function (array $row) use ($sourceItems, $photographer, $at) {
            $sourceItem = $sourceItems->get($row['source_shoot_service_id'] ?? null);
            return ['id' => $row['service_id'] ?? $sourceItem?->service_id,
                'photographer_id' => $row['photographer_id'] ?? $photographer ?? $sourceItem?->photographer_id,
                'scheduled_at' => $row['scheduled_at'] ?? $at?->toIso8601String()];
        }, $rows);
        $services = app(ShootDurationResolver::class)->withDurations($services, $source->propertySqft());
        $payload = array_merge($source->only(['address', 'city', 'state', 'zip', 'timezone', 'property_details', 'client_id', 'rep_id']),
            $options, ['source_shoot_id' => $source->id]);

        return $this->services($payload, $services, $at, $photographer, $options['timezone'] ?? $source->timezone, 'additional_work');
    }
}
