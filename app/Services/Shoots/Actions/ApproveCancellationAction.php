<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Http\Request;

class ApproveCancellationAction
{
    public function __construct(
        protected ShootWorkflowService $workflowService,
        protected ShootWorkflowTransitionSupportService $support
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        if (!$shoot->cancellation_requested_at) {
            throw new \InvalidArgumentException('No cancellation request pending for this shoot');
        }

        $this->workflowService->cancel($shoot, $user, $shoot->cancellation_reason);

        $shoot->cancellation_requested_at = null;
        $shoot->cancellation_requested_by = null;
        $shoot->save();

        $this->support->sendCancellationSideEffects($shoot, $user);

        return $shoot;
    }
}
