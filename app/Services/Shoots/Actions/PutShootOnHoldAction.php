<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootWorkflowService;
use Illuminate\Http\Request;

class PutShootOnHoldAction
{
    public function __construct(protected ShootWorkflowService $workflowService)
    {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $this->workflowService->putOnHold($shoot, $user, $validated['reason']);

        return $shoot;
    }
}
