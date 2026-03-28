<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use Illuminate\Http\Request;

class RejectHoldAction
{
    public function __construct(protected ShootActivityLogger $activityLogger)
    {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        if (!$shoot->hold_requested_at) {
            throw new \InvalidArgumentException('No hold request pending for this shoot');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $shoot->hold_requested_at = null;
        $shoot->hold_requested_by = null;
        $shoot->hold_reason = null;
        $shoot->save();

        $this->activityLogger->log(
            $shoot,
            'hold_rejected',
            [
                'by' => $user->name,
                'rejection_reason' => $validated['reason'] ?? 'No reason provided',
            ],
            $user
        );

        return $shoot;
    }
}
