<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShootWorkflowService
{
    // Status constants matching the spec
    const STATUS_HOLD_ON = 'hold_on';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_EDITING = 'editing';
    const STATUS_READY_FOR_REVIEW = 'ready_for_review';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Valid transitions
    private const VALID_TRANSITIONS = [
        self::STATUS_HOLD_ON => [self::STATUS_SCHEDULED, self::STATUS_CANCELLED],
        self::STATUS_SCHEDULED => [self::STATUS_IN_PROGRESS, self::STATUS_HOLD_ON, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_EDITING, self::STATUS_HOLD_ON],
        self::STATUS_EDITING => [self::STATUS_READY_FOR_REVIEW, self::STATUS_HOLD_ON],
        self::STATUS_READY_FOR_REVIEW => [self::STATUS_COMPLETED, self::STATUS_EDITING],
        self::STATUS_COMPLETED => [], // Terminal state
        self::STATUS_CANCELLED => [], // Terminal state
    ];

    protected ShootActivityLogger $activityLogger;

    public function __construct(ShootActivityLogger $activityLogger)
    {
        $this->activityLogger = $activityLogger;
    }

    /**
     * Schedule a shoot (move from hold_on to scheduled)
     */
    public function schedule(Shoot $shoot, \DateTime $scheduledAt, ?User $user = null): void
    {
        $this->validateTransition($shoot, self::STATUS_SCHEDULED);

        DB::transaction(function () use ($shoot, $scheduledAt, $user) {
            $shoot->status = self::STATUS_SCHEDULED;
            $shoot->scheduled_at = $scheduledAt;
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $this->activityLogger->log(
                $shoot,
                'shoot_scheduled',
                [
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                    'by' => $user?->name ?? auth()->user()?->name,
                ],
                $user
            );
        });
    }

    /**
     * Start a shoot (move from scheduled to in_progress)
     */
    public function start(Shoot $shoot, ?User $user = null): void
    {
        $this->validateTransition($shoot, self::STATUS_IN_PROGRESS);

        DB::transaction(function () use ($shoot, $user) {
            $shoot->status = self::STATUS_IN_PROGRESS;
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $this->activityLogger->log(
                $shoot,
                'shoot_started',
                ['by' => $user?->name ?? auth()->user()?->name],
                $user
            );
        });
    }

    /**
     * Move to editing (photographer has uploaded media)
     */
    public function startEditing(Shoot $shoot, ?User $user = null): void
    {
        $this->validateTransition($shoot, self::STATUS_EDITING);

        DB::transaction(function () use ($shoot, $user) {
            $shoot->status = self::STATUS_EDITING;
            $shoot->workflow_status = Shoot::WORKFLOW_EDITING;
            $shoot->photos_uploaded_at = now();
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $this->activityLogger->log(
                $shoot,
                'shoot_editing_started',
                ['by' => $user?->name ?? auth()->user()?->name],
                $user
            );
        });
    }

    /**
     * Mark as ready for review (editor has completed editing)
     */
    public function markReadyForReview(Shoot $shoot, ?User $user = null): void
    {
        $this->validateTransition($shoot, self::STATUS_READY_FOR_REVIEW);

        DB::transaction(function () use ($shoot, $user) {
            $shoot->status = self::STATUS_READY_FOR_REVIEW;
            $shoot->workflow_status = Shoot::WORKFLOW_PENDING_REVIEW;
            $shoot->editing_completed_at = now();
            $shoot->submitted_for_review_at = now();
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $this->activityLogger->log(
                $shoot,
                'shoot_submitted_for_review',
                ['by' => $user?->name ?? auth()->user()?->name],
                $user
            );
        });
    }

    /**
     * Mark as completed (admin/super admin finalizes)
     */
    public function markCompleted(Shoot $shoot, ?User $user = null): void
    {
        $this->validateTransition($shoot, self::STATUS_COMPLETED);

        DB::transaction(function () use ($shoot, $user) {
            $shoot->status = self::STATUS_COMPLETED;
            $shoot->workflow_status = Shoot::WORKFLOW_COMPLETED;
            $shoot->completed_at = now();
            $shoot->admin_verified_at = now();
            $shoot->verified_by = $user?->id ?? auth()->id();
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $this->activityLogger->log(
                $shoot,
                'shoot_completed',
                ['by' => $user?->name ?? auth()->user()?->name],
                $user
            );

            // Trigger any completion jobs (archiving, notifications, etc.)
            // This can be dispatched as a job if needed
        });
    }

    /**
     * Put shoot on hold
     */
    public function putOnHold(Shoot $shoot, ?User $user = null, ?string $reason = null): void
    {
        $this->validateTransition($shoot, self::STATUS_HOLD_ON);

        DB::transaction(function () use ($shoot, $user, $reason) {
            $shoot->status = self::STATUS_HOLD_ON;
            $shoot->workflow_status = Shoot::WORKFLOW_ON_HOLD;
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $this->activityLogger->log(
                $shoot,
                'shoot_put_on_hold',
                [
                    'by' => $user?->name ?? auth()->user()?->name,
                    'reason' => $reason,
                ],
                $user
            );
        });
    }

    /**
     * Cancel a shoot
     */
    public function cancel(Shoot $shoot, ?User $user = null, ?string $reason = null): void
    {
        $this->validateTransition($shoot, self::STATUS_CANCELLED);

        DB::transaction(function () use ($shoot, $user, $reason) {
            $shoot->status = self::STATUS_CANCELLED;
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $this->activityLogger->log(
                $shoot,
                'shoot_cancelled',
                [
                    'by' => $user?->name ?? auth()->user()?->name,
                    'reason' => $reason,
                ],
                $user
            );
        });
    }

    /**
     * Validate that a transition is allowed
     */
    protected function validateTransition(Shoot $shoot, string $targetStatus): void
    {
        $currentStatus = $shoot->status ?? self::STATUS_HOLD_ON;
        $allowedTransitions = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($targetStatus, $allowedTransitions)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$currentStatus} to {$targetStatus}. " .
                "Allowed transitions: " . implode(', ', $allowedTransitions)
            );
        }
    }

    /**
     * Get allowed transitions for a shoot
     */
    public function getAllowedTransitions(Shoot $shoot): array
    {
        $currentStatus = $shoot->status ?? self::STATUS_HOLD_ON;
        return self::VALID_TRANSITIONS[$currentStatus] ?? [];
    }
}

