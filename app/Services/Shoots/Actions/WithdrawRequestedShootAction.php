<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Http\Request;

class WithdrawRequestedShootAction
{
    public function __construct(
        protected ShootWorkflowService $workflowService,
        protected ShootWorkflowTransitionSupportService $support
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status));
        if ($currentStatus !== strtolower(Shoot::STATUS_REQUESTED)) {
            throw new \InvalidArgumentException('Only requested shoots can be withdrawn immediately');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $reason = $validated['reason'] ?? 'Client withdrew requested shoot';
        $this->workflowService->cancel($shoot, $user, $reason);
        $this->support->sendCancellationSideEffects($shoot, $user);

        return $shoot;
    }
}
