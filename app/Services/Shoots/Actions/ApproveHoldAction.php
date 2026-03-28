<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootWorkflowService;
use Illuminate\Http\Request;

class ApproveHoldAction
{
    public function __construct(protected ShootWorkflowService $workflowService)
    {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        if (!$shoot->hold_requested_at) {
            throw new \InvalidArgumentException('No hold request pending for this shoot');
        }

        $this->workflowService->putOnHold($shoot, $user, $shoot->hold_reason, 'hold_approved');

        $shoot->hold_requested_at = null;
        $shoot->hold_requested_by = null;
        $shoot->save();

        return $shoot;
    }
}
