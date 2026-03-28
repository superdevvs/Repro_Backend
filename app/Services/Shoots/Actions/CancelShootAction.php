<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Http\Request;

class CancelShootAction
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
            'notify_client' => 'nullable|boolean',
        ]);

        $this->workflowService->cancel($shoot, $user, $validated['reason'] ?? 'Cancelled by admin');
        $this->support->sendCancellationSideEffects($shoot, $user);

        return $shoot;
    }
}
