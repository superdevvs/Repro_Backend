<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use Illuminate\Http\Request;

class RequestCancellationAction
{
    public function __construct(protected ShootActivityLogger $activityLogger)
    {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $currentStatus = $shoot->workflow_status ?? $shoot->status;

        if (in_array($currentStatus, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)) {
            throw new \InvalidArgumentException('This shoot cannot be cancelled');
        }

        if ($shoot->cancellation_requested_at) {
            throw new \InvalidArgumentException('A cancellation request is already pending for this shoot');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $shoot->cancellation_requested_at = now();
        $shoot->cancellation_requested_by = $user->id;
        $shoot->cancellation_reason = $validated['reason'];
        $shoot->save();

        $this->activityLogger->log(
            $shoot,
            'cancellation_requested',
            [
                'by' => $user->name,
                'reason' => $validated['reason'],
            ],
            $user
        );

        return $shoot;
    }
}
