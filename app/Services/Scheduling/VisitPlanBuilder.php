<?php

namespace App\Services\Scheduling;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Schedule\ShootScheduleFromServices;
use App\Services\Shoots\MultiUnitBookingService;
use App\Services\Shoots\ShootDurationResolver;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\Shoots\ShootMutationSupportService;
use Carbon\Carbon;

/** Converts preview inputs or server-prepared mutation rows to absolute onsite visits. */
class VisitPlanBuilder
{
    public function build(array $payload, ?Shoot $shoot = null, ?User $actor = null): array
    {
        $timezone = $payload['timezone'] ?? $shoot?->timezone ?: (string) config('app.timezone', 'UTC');
        if (! empty($payload['requested_date'])) {
            $payload['scheduled_at'] = $payload['requested_date'].' '.($payload['requested_time'] ?? '09:00');
        }
        if (array_key_exists('_schedule_visits', $payload)) {
            return $this->canonical($payload['_schedule_visits'], $timezone);
        }
        $data = $this->absoluteInput($payload, $timezone);
        $anchor = isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at'])->utc() : $shoot?->scheduled_at?->copy()->utc();
        $photographer = (int) ($data['photographer_id'] ?? $shoot?->photographer_id ?? 0);
        $resolver = app(ShootDurationResolver::class);
        $multi = app(MultiUnitBookingService::class);
        if ($multi->handles($shoot, $data)) {
            $rows = $multi->prepare($shoot, $data, $actor)['services'];
        } elseif ($shoot) {
            $rows = app(ShootEditablePayloadService::class)->targetServicesFor($shoot, $data, $actor);
        } else {
            $services = $data['services'] ?? array_map(fn ($item) => ['id' => $item['service_id']] + $item, $data['service_items'] ?? []);
            $rows = app(ShootMutationSupportService::class)->mergeServiceItemPayload(
                $services, $data['service_items'] ?? null, $data['service_photographers'] ?? null, $anchor
            );
            $sqft = data_get($data, 'property_details.sqft') ?? data_get($data, 'property_details.squareFeet');
            $rows = $resolver->withDurations($rows, is_numeric($sqft) ? (int) $sqft : null);
        }
        if ($shoot) {
            $rows = $this->inheritChanges($rows, $data, $shoot, $anchor);
        }
        // Existing helpers normalize persisted and incoming clocks to database UTC.
        foreach ($rows as &$row) {
            $serviceId = (int) ($row['id'] ?? $row['service_id'] ?? 0);
            $assignment = collect($data['service_photographers'] ?? [])->firstWhere('service_id', $serviceId);
            if ($assignment && array_key_exists('photographer_id', $assignment)) {
                $row['photographer_id'] = $assignment['photographer_id'];
            }
            $row['photographer_id'] ??= $photographer ?: null;
            if (! empty($row['scheduled_at'])) {
                $row['scheduled_at'] = Carbon::parse($row['scheduled_at'], 'UTC')->toIso8601String();
            }
        }
        unset($row);
        $windows = $resolver->windowsForServices($rows, null, $anchor, 'UTC', null);
        if ($rows === [] && $anchor && $photographer) {
            $windows = [['start' => $anchor, 'minutes' => $resolver->defaultMinutes(), 'photographer_id' => $photographer, 'row_indexes' => []]];
        }

        $windows = app(WriteSchedulePlan::class)->normalizeWindowsTimezone($windows, $payload['timezone'] ?? $shoot?->timezone);

        return array_values(array_map(fn ($window) => $this->visit($window, $timezone), array_filter(
            $windows, fn ($window) => $window['start'] && $window['photographer_id'] && $window['minutes'] > 0
        )));
    }

    private function canonical(array $rows, string $timezone): array
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            $instant = $row['scheduled_at'] ?? $row['start'] ?? null;
            $minutes = (int) ($row['duration_minutes'] ?? $row['minutes'] ?? 0);
            $id = (int) ($row['photographer_id'] ?? 0);
            if (! $instant || ! $id || $minutes <= 0) {
                continue;
            }
            $start = Carbon::parse($instant, $timezone)->utc();
            $key = $id.':'.$start->getTimestamp().':'.($row['visit_group'] ?? 'property');
            $groups[$key] ??= ['start' => $start, 'minutes' => 0, 'photographer_id' => $id, 'row_indexes' => [], 'timezone' => $row['timezone'] ?? $timezone];
            $groups[$key]['minutes'] += $minutes;
            $groups[$key]['row_indexes'] = array_merge($groups[$key]['row_indexes'], $row['row_indexes'] ?? [$index]);
        }

        return array_values(array_map(fn ($window) => $this->visit($window, $timezone), $groups));
    }

    private function visit(array $window, string $timezone): array
    {
        $start = Carbon::parse($window['start'])->utc();

        return ['photographer_id' => (int) $window['photographer_id'], 'start' => $start->toIso8601String(),
            'end' => $start->copy()->addMinutes($window['minutes'])->toIso8601String(),
            'scheduled_at' => $start->toIso8601String(), 'duration_minutes' => (int) $window['minutes'],
            'timezone' => $window['timezone'] ?? $timezone, 'row_indexes' => $window['row_indexes'] ?? []];
    }

    private function absoluteInput(array $payload, string $timezone): array
    {
        if (! empty($payload['scheduled_at'])) {
            $payload['scheduled_at'] = Carbon::parse($payload['scheduled_at'], $timezone)->utc()->toIso8601String();
        }
        foreach (['services', 'service_items', 'service_lines'] as $field) {
            foreach ($payload[$field] ?? [] as $index => $row) {
                if (! empty($row['scheduled_at'])) {
                    $payload[$field][$index]['scheduled_at'] = Carbon::parse($row['scheduled_at'], $timezone)->utc()->toIso8601String();
                }
            }
        }

        return $payload;
    }

    private function inheritChanges(array $rows, array $payload, Shoot $shoot, ?Carbon $anchor): array
    {
        $shoot->loadMissing('serviceItems');
        $explicit = collect(array_merge($payload['services'] ?? [], $payload['service_items'] ?? [], $payload['service_lines'] ?? []));
        $byService = $shoot->serviceItems->keyBy('service_id');
        $byId = $shoot->serviceItems->keyBy('id');
        $moveAll = in_array($payload['action_mode'] ?? '', ['schedule', 'reschedule', 'alternate'], true);
        $planned = $anchor && $moveAll ? app(ShootScheduleFromServices::class)->plannedBookingSchedules($shoot, $anchor) : [];
        foreach ($rows as &$row) {
            $current = isset($row['shoot_service_id']) ? $byId->get($row['shoot_service_id']) : $byService->get($row['id'] ?? $row['service_id'] ?? 0);
            $submitted = $explicit->first(fn ($item) => isset($row['shoot_service_id'])
                ? (int) ($item['shoot_service_id'] ?? 0) === (int) $row['shoot_service_id']
                : (int) ($item['id'] ?? $item['service_id'] ?? 0) === (int) ($row['id'] ?? $row['service_id'] ?? 0));
            if ($anchor && array_key_exists('scheduled_at', $payload) && ! array_key_exists('scheduled_at', $submitted ?? [])) {
                $inherits = ! $current?->scheduled_at || ($shoot->scheduled_at && $current->scheduled_at->equalTo($shoot->scheduled_at));
                if ($moveAll || $inherits) {
                    $row['scheduled_at'] = ($planned[$current?->id] ?? $anchor)->toIso8601String();
                }
            }
            if (array_key_exists('photographer_id', $payload) && ! array_key_exists('photographer_id', $submitted ?? [])
                && (! $current?->photographer_id || (int) $current->photographer_id === (int) $shoot->photographer_id)) {
                $row['photographer_id'] = $payload['photographer_id'];
            }
        }
        unset($row);

        return $rows;
    }
}
