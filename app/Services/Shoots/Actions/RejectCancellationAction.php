<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Illuminate\Http\Request;

class RejectCancellationAction
{
    public function __construct(
        protected ShootActivityLogger $activityLogger,
        protected ShootWorkflowTransitionSupportService $support
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        if (!$shoot->cancellation_requested_at) {
            throw new \App\Exceptions\PublicBusinessRuleException('No cancellation request pending for this shoot');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $shoot->cancellation_requested_at = null;
        $shoot->cancellation_requested_by = null;
        $shoot->cancellation_reason = null;
        $shoot->save();

        $this->activityLogger->log(
            $shoot,
            'cancellation_rejected',
            [
                'by' => $user->name,
                'rejection_reason' => $validated['reason'] ?? 'No reason provided',
            ],
            $user
        );

        $this->support->sendCancellationRejectionSideEffects($shoot, $user, $validated['reason'] ?? null);

        return $shoot;
    }
}
