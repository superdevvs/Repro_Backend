<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use Illuminate\Http\Request;

class RequestHoldAction
{
    public function __construct(protected ShootActivityLogger $activityLogger)
    {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $currentStatus = $shoot->workflow_status ?? $shoot->status;

        if (in_array($currentStatus, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED, Shoot::STATUS_ON_HOLD], true)) {
            throw new \InvalidArgumentException('This shoot cannot be placed on hold');
        }

        if ($shoot->hold_requested_at) {
            throw new \InvalidArgumentException('A hold request is already pending for this shoot');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $shoot->hold_requested_at = now();
        $shoot->hold_requested_by = $user->id;
        $shoot->hold_reason = $validated['reason'];
        $shoot->save();

        $this->activityLogger->log(
            $shoot,
            'hold_requested',
            [
                'by' => $user->name,
                'reason' => $validated['reason'],
            ],
            $user
        );

        return $shoot;
    }
}
