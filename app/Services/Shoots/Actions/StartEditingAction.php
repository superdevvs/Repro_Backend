<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootWorkflowService;
use Illuminate\Http\Request;

class StartEditingAction
{
    public function __construct(protected ShootWorkflowService $workflowService)
    {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $this->workflowService->startEditing($shoot, $user);

        return $shoot;
    }
}
