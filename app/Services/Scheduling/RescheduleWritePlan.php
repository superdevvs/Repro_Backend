<?php

namespace App\Services\Scheduling;

use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use App\Services\Shoots\MultiUnitRescheduleService;
use Illuminate\Validation\ValidationException;

class RescheduleWritePlan
{
    public function build(Shoot $shoot, ShootRescheduleRequest $request, User $actor, array $changes = []): array
    {
        $multi = app(MultiUnitRescheduleService::class);
        if ($shoot->units()->exists()) {
            if (! empty($changes['services'])) {
                throw ValidationException::withMessages(['services' => ['Identify each unit service by its booked service line.']]);
            }
            $plan = $multi->plan($shoot, $request, $actor, $changes['service_lines'] ?? []);
            return app(WriteSchedulePlan::class)->services($changes, $plan['services'], $plan['at'], $shoot->photographer_id, $shoot->timezone, 'reschedule');
        }
        if (! empty($changes['service_lines'])) {
            throw ValidationException::withMessages(['service_lines' => ['Use service durations for this single-property booking.']]);
        }
        $resolved = $multi->resolveRequestedWallClock($shoot, $request->requested_date, $request->requested_time);
        $rows = app(WriteSchedulePlan::class)->storedServices($shoot);
        $changesById = collect($changes['services'] ?? [])->keyBy('id');
        if ($changesById->keys()->diff(collect($rows)->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['services' => ['A duration can only be changed for a service already on this shoot.']]);
        }
        foreach ($rows as &$row) {
            if (isset($changesById[$row['id']])) {
                $row['duration_minutes'] = $changesById[$row['id']]['duration_minutes'];
            }
            if (($row['workflow_status'] ?? null) === 'cancelled' || ($row['delivery_status'] ?? null) === 'cancelled') {
                continue;
            }
            if (! $row['scheduled_at'] || \Carbon\Carbon::parse($row['scheduled_at'])->getTimestamp() === $shoot->scheduled_at?->getTimestamp()) {
                $row['scheduled_at'] = $resolved['storage']->toIso8601String();
            }
        }
        unset($row);

        return app(WriteSchedulePlan::class)->services($changes, $rows, $resolved['storage'], $shoot->photographer_id, $shoot->timezone, 'reschedule');
    }
}
