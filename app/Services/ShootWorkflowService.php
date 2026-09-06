<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Invoices\InvoiceAdjustmentService;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootListingService;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShootWorkflowService
{
    // Unified status constants (aligned with Shoot model)
    const STATUS_REQUESTED = Shoot::STATUS_REQUESTED;   // client-submitted, awaiting approval

    const STATUS_SCHEDULED = Shoot::STATUS_SCHEDULED;

    const STATUS_IN_PROGRESS = Shoot::STATUS_SCHEDULED;

    const STATUS_COMPLETED = Shoot::STATUS_COMPLETED;

    const STATUS_UPLOADED = Shoot::STATUS_UPLOADED;     // photos uploaded by photographer/admin

    const STATUS_EDITING = Shoot::STATUS_EDITING;       // sent to editor, in progress

    const STATUS_REVIEW = Shoot::STATUS_REVIEW;         // editor submitted, awaiting editing-manager review

    const STATUS_DELIVERED = Shoot::STATUS_DELIVERED;   // finalized and delivered to client

    const STATUS_READY = Shoot::STATUS_READY;            // edited files uploaded, awaiting finalize

    const STATUS_BOOKED = Shoot::STATUS_SCHEDULED;

    const STATUS_RAW_UPLOAD_PENDING = Shoot::STATUS_SCHEDULED;

    const STATUS_RAW_UPLOADED = Shoot::STATUS_UPLOADED;

    const STATUS_RAW_ISSUE = Shoot::STATUS_UPLOADED;

    const STATUS_ADMIN_VERIFIED = Shoot::STATUS_DELIVERED;

    const STATUS_READY_FOR_CLIENT = Shoot::STATUS_READY;

    const STATUS_ON_HOLD = Shoot::STATUS_ON_HOLD;

    const STATUS_CANCELLED = Shoot::STATUS_CANCELLED;

    const STATUS_DECLINED = Shoot::STATUS_DECLINED;     // admin/rep declined the request

    // Valid transitions for the simplified pipeline
    // requested → scheduled → uploaded → editing → ready → delivered
    private const VALID_TRANSITIONS = [
        self::STATUS_REQUESTED => [self::STATUS_SCHEDULED, self::STATUS_DECLINED, self::STATUS_CANCELLED, self::STATUS_ON_HOLD],
        self::STATUS_SCHEDULED => [self::STATUS_UPLOADED, self::STATUS_ON_HOLD, self::STATUS_CANCELLED],
        self::STATUS_UPLOADED => [self::STATUS_EDITING, self::STATUS_ON_HOLD, self::STATUS_CANCELLED],
        self::STATUS_EDITING => [self::STATUS_REVIEW, self::STATUS_READY, self::STATUS_DELIVERED, self::STATUS_ON_HOLD, self::STATUS_CANCELLED],
        self::STATUS_REVIEW => [self::STATUS_READY, self::STATUS_EDITING, self::STATUS_ON_HOLD, self::STATUS_CANCELLED],
        self::STATUS_READY => [self::STATUS_DELIVERED, self::STATUS_ON_HOLD, self::STATUS_CANCELLED],
        self::STATUS_DELIVERED => [],   // terminal
        // on_hold can resume back into the pipeline
        self::STATUS_ON_HOLD => [self::STATUS_SCHEDULED, self::STATUS_UPLOADED, self::STATUS_EDITING, self::STATUS_REVIEW, self::STATUS_READY, self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],   // terminal
        self::STATUS_DECLINED => [],    // terminal
    ];

    protected ShootActivityLogger $activityLogger;

    protected ShootShareLinkService $shootShareLinkService;

    protected ShootEditingAssignmentService $shootEditingAssignmentService;

    public function __construct(
        ShootActivityLogger $activityLogger,
        ShootShareLinkService $shootShareLinkService,
        ShootEditingAssignmentService $shootEditingAssignmentService
    ) {
        $this->activityLogger = $activityLogger;
        $this->shootShareLinkService = $shootShareLinkService;
        $this->shootEditingAssignmentService = $shootEditingAssignmentService;
    }

    /**
     * Clear all dashboard caches to reflect changes immediately
     */
    protected function clearDashboardCache(): void
    {
        // Clear dashboard overview caches for all admin users
        $adminUsers = User::whereIn('role', ['admin', 'superadmin'])->pluck('id');
        foreach ($adminUsers as $userId) {
            Cache::forget('dashboard_overview_admin_'.$userId);
            Cache::forget('dashboard_overview_superadmin_'.$userId);
        }

        ShootListingService::flushCachedListings();
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
        if (! $isAlreadyScheduled && ! $isResumingFromHold) {
            $this->validateTransition($shoot, self::STATUS_SCHEDULED);
        }

        $scheduleScope = app(ScheduleDateScopeService::class);
        // Capture the shoot's current (old) local calendar day before mutating it so a
        // reschedule that moves the shoot to a different day busts both buckets (Req 8.1, 8.3).
        $previousLocalDate = $scheduleScope->localDateForShoot($shoot);

        DB::transaction(function () use ($shoot, $scheduledAt, $user, $isAlreadyScheduled, $isResumingFromHold, $scheduleScope) {
            // Update status if resuming from hold or if not already scheduled
            $shoot->workflow_status = self::STATUS_SCHEDULED;
            $shoot->status = self::STATUS_SCHEDULED;
            $shoot->scheduled_at = $scheduledAt;
            $shoot->scheduled_date = $scheduleScope->localDateForScheduledAt($scheduledAt, $shoot->timezone)
                ?? $scheduledAt->format('Y-m-d');
            $shoot->time = $scheduleScope->localTimeForScheduledAt($scheduledAt, $shoot->timezone)
                ?? $scheduledAt->format('H:i');
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            // Log if this is a new scheduling or resuming from hold
            if (! $isAlreadyScheduled || $isResumingFromHold) {
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

        // Clear dashboard cache so changes reflect immediately
        $this->clearDashboardCache();

        // Bust the per-date schedule buckets for both the old and the new calendar day so the
        // Schedule_View reflects the create/reschedule within the same request (Req 8.1, 8.3).
        $scheduleScope->invalidateDates([
            $previousLocalDate,
            $scheduleScope->localDateForShoot($shoot),
        ]);
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
        $isAlreadyEditing = $this->isAlreadyInStatus($shoot, self::STATUS_EDITING);
        if (! $isAlreadyEditing) {
            $this->validateTransition($shoot, self::STATUS_EDITING);
        }

        if ($isAlreadyEditing) {
            return;
        }

        $laneAssignments = [];

        DB::transaction(function () use ($shoot, $user, &$laneAssignments) {
            $shoot->status = self::STATUS_EDITING;
            $shoot->workflow_status = Shoot::WORKFLOW_EDITING;
            $shoot->photos_uploaded_at = now();
            $shoot->updated_by = $user?->id ?? auth()->id();

            $laneAssignments = $this->shootEditingAssignmentService->autoAssignEditorsForShoot($shoot);

            if (empty($laneAssignments) && empty($shoot->editor_id)) {
                $shoot->editor_id = $this->resolvePrimaryEditorId();
            }

            $shoot->save();
            $freshForLegacySync = $shoot->fresh(['services.category']) ?? $shoot;
            $shoot->editor_id = $this->shootEditingAssignmentService->syncLegacyShootEditor($freshForLegacySync);

            $this->activityLogger->log(
                $shoot,
                'shoot_editing_started',
                ['by' => $user?->name ?? auth()->user()?->name],
                $user
            );
        });

        $freshShoot = $shoot->fresh(['services.category']);
        foreach ($laneAssignments as $lane => $assignment) {
            $assignedEditor = $assignment['editor'] ?? null;
            if (! $assignedEditor instanceof User) {
                continue;
            }

            try {
                $mediaStage = match ($lane) {
                    ShootEditingAssignmentService::LANE_VIDEO => 'raw_video',
                    ShootEditingAssignmentService::LANE_PHOTO => 'raw_photo',
                    default => 'raw',
                };

                $this->shootShareLinkService->ensureActiveShootShareLink($freshShoot, $assignedEditor, $mediaStage);
            } catch (\Throwable $exception) {
                Log::warning('Failed to auto-create lane share link when starting editing', [
                    'shoot_id' => $shoot->id,
                    'editor_id' => $assignedEditor->id,
                    'lane' => $lane,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (empty($laneAssignments) && $freshShoot?->editor_id) {
            $assignedEditor = User::find($freshShoot->editor_id);
            if ($assignedEditor) {
                try {
                    $this->shootShareLinkService->ensureActiveShootShareLink($freshShoot, $assignedEditor, 'raw');
                } catch (\Throwable $exception) {
                    Log::warning('Failed to auto-create raw share link when starting editing', [
                        'shoot_id' => $shoot->id,
                        'editor_id' => $freshShoot->editor_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
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

            // Log delivery notification for client
            $this->activityLogger->log(
                $shoot,
                'shoot_delivered',
                [
                    'by' => $user?->name ?? auth()->user()?->name,
                    'message' => 'Your photos are ready for download!',
                ],
                $user
            );

            // Trigger any completion jobs (archiving, notifications, etc.)
            // This can be dispatched as a job if needed
        });

        // Clear dashboard cache so changes reflect immediately
        $this->clearDashboardCache();

        // Auto-publish to Bright MLS when shoot is delivered
        try {
            $brightMlsService = app(BrightMlsService::class);
            if ($brightMlsService->isAutoPublishAvailable()) {
                $mlsResult = $brightMlsService->autoPublishForShoot($shoot->fresh());
                if ($mlsResult && $mlsResult['success']) {
                    Log::info('Bright MLS auto-published on markCompleted', [
                        'shoot_id' => $shoot->id,
                        'manifest_id' => $mlsResult['manifest_id'] ?? null,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Bright MLS auto-publish failed in markCompleted (non-blocking)', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Put shoot on hold
     */
    public function putOnHold(
        Shoot $shoot,
        ?User $user = null,
        ?string $reason = null,
        string $activityType = 'shoot_put_on_hold'
    ): void {
        $this->validateTransition($shoot, self::STATUS_ON_HOLD);

        DB::transaction(function () use ($shoot, $user, $reason, $activityType) {
            $shoot->status = self::STATUS_ON_HOLD;
            $shoot->workflow_status = self::STATUS_ON_HOLD;
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $this->activityLogger->log(
                $shoot,
                $activityType,
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
    public function cancel(
        Shoot $shoot,
        ?User $user = null,
        ?string $reason = null,
        float $cancellationFee = 0.0,
        bool $suppressNotifications = false
    ): void {
        $this->validateTransition($shoot, self::STATUS_CANCELLED);

        DB::transaction(function () use ($shoot, $user, $reason, $cancellationFee, $suppressNotifications) {
            $originalFinancials = [
                'base_quote' => (float) ($shoot->base_quote ?? 0),
                'tax_amount' => (float) ($shoot->tax_amount ?? 0),
                'total_quote' => (float) ($shoot->total_quote ?? 0),
            ];

            $shoot->status = self::STATUS_CANCELLED;
            $shoot->workflow_status = self::STATUS_CANCELLED;
            $shoot->cancellation_requested_at = null;
            $shoot->cancellation_requested_by = null;
            $shoot->updated_by = $user?->id ?? auth()->id();

            if ($cancellationFee > 0) {
                $billableAdjustments = app(InvoiceAdjustmentService::class)
                    ->billableItemsForShoot($shoot)
                    ->sum(fn ($item) => (float) $item->total_amount);

                $shoot->base_quote = round($cancellationFee, 2);
                $shoot->discount_type = null;
                $shoot->discount_value = null;
                $shoot->discount_amount = 0;
                $shoot->tax_region = 'none';
                $shoot->tax_percent = 0;
                $shoot->tax_amount = 0;
                // The cancellation fee replaces the cancelled service charge,
                // but invoice adjustments remain independent payable order
                // lines and must survive the workflow transition.
                $shoot->total_quote = round($cancellationFee + $billableAdjustments, 2);
            }

            $shoot->save();
            if ($cancellationFee > 0) {
                $shoot->syncPaymentStatusFromRecords();
            }

            $this->activityLogger->log(
                $shoot,
                'shoot_cancelled',
                [
                    'by' => $user?->name ?? auth()->user()?->name,
                    'reason' => $reason,
                    'cancellation_fee' => round($cancellationFee, 2),
                    'original_financials' => $originalFinancials,
                    'suppress_notifications' => $suppressNotifications,
                ],
                $user
            );
        });
    }

    /**
     * Approve a requested shoot (move from requested to scheduled)
     */
    public function approve(Shoot $shoot, \DateTime $scheduledAt, ?User $user = null, ?string $notes = null): void
    {
        $this->validateTransition($shoot, self::STATUS_SCHEDULED);

        DB::transaction(function () use ($shoot, $scheduledAt, $user, $notes) {
            $shoot->status = self::STATUS_SCHEDULED;
            $shoot->workflow_status = self::STATUS_SCHEDULED;
            $shoot->scheduled_at = $scheduledAt;
            $shoot->scheduled_date = $scheduledAt->format('Y-m-d');
            $shoot->approved_at = now();
            $shoot->approved_by = $user?->id ?? auth()->id();
            if ($notes) {
                $shoot->approval_notes = $notes;
            }
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $scheduledAtCarbon = \Carbon\Carbon::instance($scheduledAt);
            $this->activityLogger->log(
                $shoot,
                'shoot_approved',
                [
                    'scheduled_at' => $scheduledAtCarbon->toIso8601String(),
                    'by' => $user?->name ?? auth()->user()?->name,
                    'notes' => $notes,
                ],
                $user
            );
        });

        // Clear dashboard cache so changes reflect immediately
        $this->clearDashboardCache();
    }

    /**
     * Decline a requested shoot
     */
    public function decline(Shoot $shoot, ?User $user = null, ?string $reason = null): void
    {
        $this->validateTransition($shoot, self::STATUS_DECLINED);

        DB::transaction(function () use ($shoot, $user, $reason) {
            $shoot->status = self::STATUS_DECLINED;
            $shoot->workflow_status = self::STATUS_DECLINED;
            $shoot->declined_at = now();
            $shoot->declined_by = $user?->id ?? auth()->id();
            $shoot->declined_reason = $reason;
            $shoot->updated_by = $user?->id ?? auth()->id();
            $shoot->save();

            $this->activityLogger->log(
                $shoot,
                'shoot_declined',
                [
                    'by' => $user?->name ?? auth()->user()?->name,
                    'reason' => $reason,
                ],
                $user
            );
        });

        // Clear dashboard cache so changes reflect immediately
        $this->clearDashboardCache();
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
            'editing_uploaded' => self::STATUS_EDITING,
            'editing_complete' => self::STATUS_REVIEW,
            'editing_issue' => self::STATUS_EDITING,
            'pending_review' => self::STATUS_REVIEW,
            'ready_for_review' => self::STATUS_REVIEW,
            'qc' => self::STATUS_REVIEW,
            'ready_for_client' => self::STATUS_DELIVERED,
            'admin_verified' => self::STATUS_DELIVERED,
            'hold_on' => self::STATUS_ON_HOLD,
        ];
        if (isset($legacyMap[$currentStatus])) {
            $currentStatus = $legacyMap[$currentStatus];
        }

        $allowedTransitions = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($targetStatus, $allowedTransitions)) {
            throw new \App\Exceptions\PublicBusinessRuleException(
                "Cannot transition from {$currentStatus} to {$targetStatus}. ".
                'Allowed transitions: '.implode(', ', $allowedTransitions)
            );
        }
    }

    protected function isAlreadyInStatus(Shoot $shoot, string $targetStatus): bool
    {
        $currentStatus = $shoot->workflow_status ?? $shoot->status ?? self::STATUS_ON_HOLD;
        $legacyMap = [
            'booked' => self::STATUS_SCHEDULED,
            'raw_upload_pending' => self::STATUS_SCHEDULED,
            'raw_uploaded' => self::STATUS_UPLOADED,
            'photos_uploaded' => self::STATUS_UPLOADED,
            'in_progress' => self::STATUS_UPLOADED,
            'raw_issue' => self::STATUS_UPLOADED,
            'editing_uploaded' => self::STATUS_EDITING,
            'editing_complete' => self::STATUS_REVIEW,
            'editing_issue' => self::STATUS_EDITING,
            'pending_review' => self::STATUS_REVIEW,
            'ready_for_review' => self::STATUS_REVIEW,
            'qc' => self::STATUS_REVIEW,
            'ready_for_client' => self::STATUS_DELIVERED,
            'admin_verified' => self::STATUS_DELIVERED,
            'hold_on' => self::STATUS_ON_HOLD,
        ];

        return ($legacyMap[$currentStatus] ?? $currentStatus) === $targetStatus;
    }

    /**
     * Get allowed transitions for a shoot
     */
    public function getAllowedTransitions(Shoot $shoot): array
    {
        $currentStatus = $shoot->status ?? self::STATUS_ON_HOLD;

        return self::VALID_TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * Resolve the primary editor ID for auto-assignment.
     * Uses round-robin among active editors (least current editing load).
     */
    protected function resolvePrimaryEditorId(): ?int
    {
        $editors = \App\Models\User::where('role', 'editor')->get(['id']);
        if ($editors->isEmpty()) {
            return null;
        }
        if ($editors->count() === 1) {
            return $editors->first()->id;
        }

        // Round-robin: pick editor with fewest active editing shoots
        $editorIds = $editors->pluck('id');
        $loadMap = Shoot::whereIn('editor_id', $editorIds)
            ->whereIn('status', [self::STATUS_UPLOADED, self::STATUS_EDITING])
            ->selectRaw('editor_id, count(*) as total')
            ->groupBy('editor_id')
            ->pluck('total', 'editor_id');

        return $editors->sortBy(fn ($e) => $loadMap[$e->id] ?? 0)->first()->id;
    }
}
