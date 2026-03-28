<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Http\Request;

class DeclineShootAction
{
    public function __construct(
        protected ShootWorkflowService $workflowService,
        protected ShootWorkflowTransitionSupportService $support
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $this->workflowService->decline($shoot, $user, $validated['reason'] ?? null);
        $this->support->sendDeclineSideEffects($shoot, $user);

        return $shoot;
    }
}
