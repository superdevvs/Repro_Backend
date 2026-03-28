<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use Illuminate\Http\Request;

class SubmitForReviewAction
{
    public function __construct(protected ShootActivityLogger $activityLogger)
    {
    }

    public function execute(Request $request, Shoot $shoot, User $user): Shoot
    {
        $shoot->status = Shoot::STATUS_READY;
        $shoot->workflow_status = Shoot::STATUS_READY;
        $shoot->editing_completed_at = now();
        $shoot->updated_by = $user->id;
        $shoot->save();

        $this->activityLogger->log(
            $shoot,
            'shoot_submitted_for_review',
            ['by' => $user->name],
            $user
        );

        return $shoot;
    }
}
