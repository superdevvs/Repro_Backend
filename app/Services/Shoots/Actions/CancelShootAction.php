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
            'suppress_notifications' => 'nullable|boolean',
            'cancellation_fee' => 'nullable|numeric|min:0|max:10000',
        ]);
        $suppressNotifications =
            (bool) ($validated['suppress_notifications'] ?? false)
            || (array_key_exists('notify_client', $validated) && (bool) $validated['notify_client'] === false);

        $this->workflowService->cancel(
            $shoot,
            $user,
            $validated['reason'] ?? 'Cancelled by admin',
            (float) ($validated['cancellation_fee'] ?? 0),
            $suppressNotifications
        );
        if (!$suppressNotifications) {
            $this->support->sendCancellationSideEffects($shoot, $user);
        }

        return $shoot;
    }
}
