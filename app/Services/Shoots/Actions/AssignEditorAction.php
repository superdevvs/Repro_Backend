<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Http\Request;

class AssignEditorAction
{
    public function __construct(
        protected ShootWorkflowTransitionSupportService $support,
        protected ShootEditingAssignmentService $editingAssignmentService,
        protected ShootActivityLogger $activityLogger
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $validated = $request->validate([
            'editor_id' => 'nullable|exists:users,id',
            'shoot_unit_id' => 'nullable|integer',
        ]);

        $selectedEditor = $this->support->resolveEditor($validated['editor_id'] ?? null);
        $trackedAssignments = $this->editingAssignmentService->getTrackedServiceAssignments($shoot);
        if (! empty($validated['shoot_unit_id'])) {
            abort_unless($shoot->units()->whereKey($validated['shoot_unit_id'])->exists(), 422, 'This unit does not belong to the shoot.');
            $trackedAssignments = $trackedAssignments->where('shoot_unit_id', (int) $validated['shoot_unit_id']);
            abort_if($trackedAssignments->isEmpty(), 422, 'This unit has no services requiring editing.');
        }

        if ($trackedAssignments->isNotEmpty()) {
            $unsupportedLane = $trackedAssignments
                ->pluck('lane')
                ->filter()
                ->unique()
                ->first(fn ($lane) => !$selectedEditor->canEditLane($lane));

            if ($unsupportedLane) {
                throw new \App\Exceptions\PublicBusinessRuleException("Selected editor cannot handle the {$unsupportedLane} editing lane.");
            }

            foreach ($trackedAssignments as $assignment) {
                \Illuminate\Support\Facades\DB::table('shoot_service')->where('shoot_id', $shoot->id)->where('id', $assignment['shoot_service_id'])
                    ->update([$assignment['editor_column'] => $selectedEditor->id, $assignment['completed_column'] => null, 'updated_at' => now()]);
            }

            $this->editingAssignmentService->syncLegacyShootEditor($shoot->fresh(['services.category']));
        } else {
            $shoot->editor_id = $selectedEditor->id;
            $shoot->save();
        }

        $this->activityLogger->log(
            $shoot,
            'editor_assigned',
            [
                'editor_id' => $selectedEditor->id,
                'editor_name' => $selectedEditor->name,
            ],
            $user
        );

        return $shoot;
    }
}
