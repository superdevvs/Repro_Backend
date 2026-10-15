<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootEditingDispatch;
use App\Services\Schedule\ScheduleInstantResolver;
use App\Support\ShootAddress;

/** Card metadata only: a dispatch does not grant access to the whole shoot. */
class EditorTaskShootSummary
{
    public function present(Shoot $shoot, ?ShootEditingDispatch $dispatch = null): array
    {
        $resolver = app(ScheduleInstantResolver::class);
        $instant = $resolver->forShoot($shoot);
        $day = $instant?->format('Y-m-d');
        $canViewVideoEditorContext = app(ShootAuthorizationSupport::class)->canVideoEditorReadClientContext($shoot, auth()->user());
        $viewer = auth()->user();
        // A scoped video task may expose this small brief without opening the
        // operational shoot, notes routes, other files, or client contacts.
        if (! $canViewVideoEditorContext && $viewer?->role === 'editor' && $viewer->canEditLane('video')
            && $dispatch && (int) $dispatch->shoot_id === (int) $shoot->id && ! $shoot->isImportDraft()
            && ! in_array($shoot->status, ['cancelled', 'declined'], true)
            && ! in_array($shoot->workflow_status, ['cancelled', 'declined'], true)) {
            $canViewVideoEditorContext = $dispatch->items->contains(fn ($item) =>
                (int) $item->editor_id === (int) $viewer->id && $item->lane === 'video'
                && $item->destination === 'human' && ! in_array($item->status, ['completed', 'cancelled'], true));
        }

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
            'clientName' => $canViewVideoEditorContext ? $shoot->client?->name : null,
            'companyNotes' => $canViewVideoEditorContext ? $shoot->company_notes : null,
            'can_view_video_editor_context' => $canViewVideoEditorContext,
            'canViewVideoEditorContext' => $canViewVideoEditorContext,
            'isFlagged' => (bool) $shoot->is_flagged,
            'services' => $shoot->services->map(fn ($service) => ['label' => $service->name, 'type' => $service->category?->name ?? 'service'])->values()->all(),
            'hasPendingEditorWork' => true,
            'hasScopedEditingTasks' => true,
        ];
    }
}
