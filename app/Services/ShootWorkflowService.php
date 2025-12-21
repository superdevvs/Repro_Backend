<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShootWorkflowService
{
    // Unified status constants (aligned with Shoot model)
    const STATUS_SCHEDULED = Shoot::STATUS_SCHEDULED;
    const STATUS_IN_PROGRESS = Shoot::STATUS_SCHEDULED;
    const STATUS_COMPLETED = Shoot::STATUS_COMPLETED;
    const STATUS_UPLOADED = Shoot::STATUS_UPLOADED;     // photos uploaded by photographer/admin
    const STATUS_EDITING = Shoot::STATUS_EDITING;       // sent to editor, in progress
    const STATUS_REVIEW = Shoot::STATUS_REVIEW;         // editor submitted, admin review
    const STATUS_DELIVERED = Shoot::STATUS_DELIVERED;   // finalized and delivered to client
    const STATUS_READY = Shoot::STATUS_DELIVERED;
    const STATUS_READY_FOR_REVIEW = Shoot::STATUS_REVIEW;
    const STATUS_BOOKED = Shoot::STATUS_SCHEDULED;
    const STATUS_RAW_UPLOAD_PENDING = Shoot::STATUS_SCHEDULED;
    const STATUS_RAW_UPLOADED = Shoot::STATUS_UPLOADED;
    const STATUS_RAW_ISSUE = Shoot::STATUS_UPLOADED;
    const STATUS_PENDING_REVIEW = Shoot::STATUS_REVIEW;
    const STATUS_ADMIN_VERIFIED = Shoot::STATUS_DELIVERED;
    const STATUS_READY_FOR_CLIENT = Shoot::STATUS_DELIVERED;
    const STATUS_ON_HOLD = Shoot::STATUS_ON_HOLD;
    const STATUS_CANCELLED = Shoot::STATUS_CANCELLED;

    // Valid transitions for the simplified pipeline
    // scheduled → uploaded → editing → review → delivered
    private const VALID_TRANSITIONS = [
        self::STATUS_SCHEDULED => [self::STATUS_UPLOADED, self::STATUS_ON_HOLD, self::STATUS_CANCELLED],
        self::STATUS_UPLOADED => [self::STATUS_EDITING, self::STATUS_ON_HOLD],
        self::STATUS_EDITING => [self::STATUS_REVIEW, self::STATUS_ON_HOLD],
        self::STATUS_REVIEW => [self::STATUS_DELIVERED, self::STATUS_EDITING, self::STATUS_ON_HOLD],
        self::STATUS_DELIVERED => [],   // terminal
        // on_hold can resume back into the pipeline
        self::STATUS_ON_HOLD => [self::STATUS_SCHEDULED, self::STATUS_UPLOADED, self::STATUS_EDITING, self::STATUS_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],   // terminal
    ];

    protected ShootActivityLogger $activityLogger;

    public function __construct(ShootActivityLogger $activityLogger)
    {
        $this->activityLogger = $activityLogger;
    }

    /**
     * Schedule a shoot (move from hold_on to scheduled, or update scheduled time)
     */
    public function schedule(Shoot $shoot, \DateTime $scheduledAt, ?User $user = null): void
    {
        // If shoot is already scheduled, allow updating the scheduled time without transition validation
        $currentStatus = $shoot->workflow_status ?? $shoot->status ?? self::STATUS_ON_HOLD;
        $isAlreadyScheduled = in_array($currentStatus, [self::STATUS_SCHEDULED], true);
        $isResumingFromHold = in_array($currentStatus, [self::STATUS_ON_HOLD], true);
        
        // Only validate transition if not already scheduled and not resuming from hold
        if (!$isAlreadyScheduled && !$isResumingFromHold) {
            $this->validateTransition($shoot, self::STATUS_SCHEDULED);
        }

        DB::transaction(function () use ($shoot, $scheduledAt, $user, $isAlreadyScheduled, $isResumingFromHold) {
            // Update status if resuming from hold or if not already scheduled
            $shoot->workflow_status = self::STATUS_SCHEDULED;
            $shoot->status = self::STATUS_SCHEDULED;
            $shoot->scheduled_at = $scheduledAt;
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            // Log if this is a new scheduling or resuming from hold
            if (!$isAlreadyScheduled || $isResumingFromHold) {
                // Convert DateTime to Carbon for toIso8601String() method
                $scheduledAtCarbon = \Carbon\Carbon::instance($scheduledAt);
                $this->activityLogger->log(
                    $shoot,
                    $isResumingFromHold ? 'shoot_resumed_from_hold' : 'shoot_scheduled',
                    [
                        'scheduled_at' => $scheduledAtCarbon->toIso8601String(),
                        'by' => $user?->name ?? auth()->user()?->name,
                    ],
                    $user
                );
            }
        });
    }

    /**
     * Start a shoot (move from scheduled to in_progress)
     */
    public function start(Shoot $shoot, ?User $user = null): void
    {
        // In the simplified flow, "start" is equivalent to photos being uploaded
        $this->validateTransition($shoot, self::STATUS_UPLOADED);

        DB::transaction(function () use ($shoot, $user) {
            $shoot->workflow_status = self::STATUS_UPLOADED;
            $shoot->status = self::STATUS_UPLOADED;
            $shoot->photos_uploaded_at = now();
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
        $this->validateTransition($shoot, self::STATUS_REVIEW);

        DB::transaction(function () use ($shoot, $user) {
            $shoot->status = self::STATUS_REVIEW;
            $shoot->workflow_status = self::STATUS_REVIEW;
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
        $this->validateTransition($shoot, self::STATUS_DELIVERED);

        DB::transaction(function () use ($shoot, $user) {
            $shoot->status = self::STATUS_DELIVERED;
            $shoot->workflow_status = self::STATUS_DELIVERED;
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
        $this->validateTransition($shoot, self::STATUS_ON_HOLD);

        DB::transaction(function () use ($shoot, $user, $reason) {
            $shoot->status = self::STATUS_ON_HOLD;
            $shoot->workflow_status = self::STATUS_ON_HOLD;
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
        $currentStatus = $shoot->workflow_status ?? $shoot->status ?? self::STATUS_ON_HOLD;

        // Map any legacy values to the unified statuses
        $legacyMap = [
            'booked' => self::STATUS_SCHEDULED,
            'raw_upload_pending' => self::STATUS_SCHEDULED,
            'raw_uploaded' => self::STATUS_UPLOADED,
            'photos_uploaded' => self::STATUS_UPLOADED,
            'in_progress' => self::STATUS_UPLOADED,
            'raw_issue' => self::STATUS_UPLOADED,
            'editing_uploaded' => self::STATUS_REVIEW,
            'editing_complete' => self::STATUS_REVIEW,
            'editing_issue' => self::STATUS_REVIEW,
            'pending_review' => self::STATUS_REVIEW,
            'ready_for_review' => self::STATUS_REVIEW,
            'qc' => self::STATUS_REVIEW,
            'ready_for_client' => self::STATUS_DELIVERED,
            'admin_verified' => self::STATUS_DELIVERED,
            'ready' => self::STATUS_DELIVERED,
            'hold_on' => self::STATUS_ON_HOLD,
        ];
        if (isset($legacyMap[$currentStatus])) {
            $currentStatus = $legacyMap[$currentStatus];
        }
        
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
        $currentStatus = $shoot->status ?? self::STATUS_ON_HOLD;
        return self::VALID_TRANSITIONS[$currentStatus] ?? [];
    }
}

