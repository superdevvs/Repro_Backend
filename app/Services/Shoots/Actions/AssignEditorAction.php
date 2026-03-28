<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Http\Request;

class AssignEditorAction
{
    public function __construct(
        protected ShootWorkflowTransitionSupportService $support,
        protected ShootActivityLogger $activityLogger
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $validated = $request->validate([
            'editor_id' => 'nullable|exists:users,id',
        ]);

        $selectedEditor = $this->support->resolveEditor($validated['editor_id'] ?? null);

        $shoot->editor_id = $selectedEditor->id;
        $shoot->save();

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
