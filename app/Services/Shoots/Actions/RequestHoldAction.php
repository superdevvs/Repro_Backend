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
        $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status));
        $allowedStatuses = [
            strtolower(Shoot::STATUS_REQUESTED),
            strtolower(Shoot::STATUS_SCHEDULED),
            'booked',
            strtolower(Shoot::STATUS_ON_HOLD),
            strtolower(Shoot::STATUS_EDITING),
            strtolower(Shoot::STATUS_UPLOADED),
        ];

        if (in_array($currentStatus, [strtolower(Shoot::STATUS_CANCELLED), strtolower(Shoot::STATUS_DECLINED), strtolower(Shoot::STATUS_ON_HOLD)], true)) {
            throw new \InvalidArgumentException('This shoot cannot be placed on hold');
        }
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            throw new \InvalidArgumentException('Hold requests are only available for requested, scheduled, or in-progress shoots');
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
