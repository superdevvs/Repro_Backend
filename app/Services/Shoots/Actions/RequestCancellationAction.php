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
        $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status));
        $allowedStatuses = [
            strtolower(Shoot::STATUS_SCHEDULED),
            'booked',
            strtolower(Shoot::STATUS_ON_HOLD),
            strtolower(Shoot::STATUS_EDITING),
            strtolower(Shoot::STATUS_UPLOADED),
        ];

        if (in_array($currentStatus, [strtolower(Shoot::STATUS_CANCELLED), strtolower(Shoot::STATUS_DECLINED)], true)) {
            throw new \InvalidArgumentException('This shoot cannot be cancelled');
        }
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            throw new \InvalidArgumentException('Cancellation requests are only available for scheduled or in-progress shoots');
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
