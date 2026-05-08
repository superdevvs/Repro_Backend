<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootWorkflowTransitionSupportService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RequestCancellationAction
{
    public function __construct(
        protected ShootActivityLogger $activityLogger,
        protected ShootWorkflowTransitionSupportService $support
    ) {
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
        if ($user->role === 'client' && !in_array($currentStatus, [
            strtolower(Shoot::STATUS_SCHEDULED),
            'booked',
            strtolower(Shoot::STATUS_ON_HOLD),
        ], true)) {
            throw new \InvalidArgumentException('Client cancellation requests are only available for scheduled shoots');
        }

        if ($shoot->cancellation_requested_at) {
            throw new \InvalidArgumentException('A cancellation request is already pending for this shoot');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
            'cancellation_fee_notice_acknowledged' => 'nullable|boolean',
        ]);
        $isWithinFeeWindow = $this->isWithinCancellationFeeWindow($shoot);
        if ($user->role === 'client' && $isWithinFeeWindow && !($validated['cancellation_fee_notice_acknowledged'] ?? false)) {
            throw new \InvalidArgumentException('Please acknowledge the cancellation fee notice before submitting this request.');
        }

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
                'cancellation_fee_notice_acknowledged' => (bool) ($validated['cancellation_fee_notice_acknowledged'] ?? false),
                'within_cancellation_fee_window' => $isWithinFeeWindow,
            ],
            $user
        );

        $this->support->sendCancellationRequestSideEffects($shoot, $user);

        return $shoot;
    }

    protected function isWithinCancellationFeeWindow(Shoot $shoot): bool
    {
        $scheduledAt = $this->scheduledAt($shoot);
        if (!$scheduledAt) {
            return false;
        }

        $hoursUntilShoot = now($scheduledAt->timezone)->diffInMinutes($scheduledAt, false) / 60;

        return $hoursUntilShoot >= 0 && $hoursUntilShoot <= 4;
    }

    protected function scheduledAt(Shoot $shoot): ?Carbon
    {
        if ($shoot->scheduled_at) {
            return Carbon::parse($shoot->scheduled_at, $shoot->timezone ?: null);
        }

        if (!$shoot->scheduled_date || !$shoot->time) {
            return null;
        }

        return Carbon::parse($shoot->scheduled_date->format('Y-m-d') . ' ' . $shoot->time, $shoot->timezone ?: null);
    }
}
