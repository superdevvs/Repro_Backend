<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootEditingAssignmentService;
use Illuminate\Http\Request;

class SubmitForReviewAction
{
    public function __construct(
        protected ShootActivityLogger $activityLogger,
        protected ShootEditingAssignmentService $shootEditingAssignmentService
    )
    {
    }

    public function execute(Request $request, Shoot $shoot, User $user): array
    {
        $assignments = $this->shootEditingAssignmentService->markAssignedServicesReadyForUser($shoot, $user);
        $allTrackedLanesReady = $this->shootEditingAssignmentService->allTrackedLanesReady($shoot->fresh(['services.category']));

        if ($allTrackedLanesReady) {
            $pendingAiReview = app(\App\Services\Studio\WorkspaceShootReview::class)->isPending($shoot);
            $shoot->status = $pendingAiReview ? Shoot::STATUS_REVIEW : Shoot::STATUS_READY;
            $shoot->workflow_status = $shoot->status;
            if ($pendingAiReview) {
                $shoot->submitted_for_review_at = now();
            } else {
                $shoot->editing_completed_at = now();
            }
        } else {
            $shoot->status = Shoot::STATUS_EDITING;
            $shoot->workflow_status = Shoot::STATUS_EDITING;
        }

        $shoot->updated_by = $user->id;
        $shoot->save();

        $this->activityLogger->log(
            $shoot,
            $allTrackedLanesReady ? 'shoot_submitted_for_review' : 'editing_lane_submitted_for_review',
            [
                'by' => $user->name,
                'all_lanes_ready' => $allTrackedLanesReady,
            ],
            $user
        );

        return [
            'shoot' => $shoot,
            'all_lanes_ready' => $allTrackedLanesReady,
            'editor_assignments' => $assignments,
        ];
    }
}
