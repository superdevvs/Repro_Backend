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

        $validated = $request->validate([
            'cancellation_fee' => 'nullable|numeric|min:0|max:10000',
            'waive_cancellation_fee' => 'nullable|boolean',
        ]);
        $cancellationFee = $validated['waive_cancellation_fee'] ?? false
            ? 0.0
            : (float) ($validated['cancellation_fee'] ?? $this->defaultCancellationFeeFor($shoot));

        $this->workflowService->cancel($shoot, $user, $shoot->cancellation_reason, $cancellationFee);

        $shoot->cancellation_requested_at = null;
        $shoot->cancellation_requested_by = null;
        $shoot->save();

        $this->support->sendCancellationSideEffects($shoot, $user);

        return $shoot;
    }

    protected function defaultCancellationFeeFor(Shoot $shoot): float
    {
        $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status));

        return in_array($currentStatus, [
            strtolower(Shoot::STATUS_SCHEDULED),
            'booked',
            strtolower(Shoot::STATUS_ON_HOLD),
        ], true) ? 60.0 : 0.0;
    }
}
