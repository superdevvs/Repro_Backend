<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Services\Schedule\ScheduleInstantResolver;
use App\Support\ShootAddress;

/** Card metadata only: a dispatch does not grant access to the whole shoot. */
class EditorTaskShootSummary
{
    public function present(Shoot $shoot): array
    {
        $resolver = app(ScheduleInstantResolver::class);
        $instant = $resolver->forShoot($shoot);
        $day = $instant?->format('Y-m-d');

        return [
            'id' => $shoot->id,
            'dayLabel' => $day ?? 'Unscheduled',
            'scheduledLocalDate' => $day,
            'scheduleTimezone' => $resolver->timezoneForShoot($shoot),
            'startTime' => $instant?->toIso8601String(),
            'scheduledInstant' => $instant?->toIso8601String(),
            'timeLabel' => $instant?->format('g:i A'),
            'addressLine' => ShootAddress::streetWithAptSuite($shoot->address, $shoot->property_details),
            'cityStateZip' => implode(', ', array_filter([$shoot->city, $shoot->state, $shoot->zip])),
            'status' => $shoot->status,
            'workflowStatus' => $shoot->workflow_status ?: $shoot->status,
            'clientName' => null,
            'isFlagged' => (bool) $shoot->is_flagged,
            'services' => $shoot->services->map(fn ($service) => ['label' => $service->name, 'type' => $service->category?->name ?? 'service'])->values()->all(),
            'hasPendingEditorWork' => true,
            'hasScopedEditingTasks' => true,
        ];
    }
}
