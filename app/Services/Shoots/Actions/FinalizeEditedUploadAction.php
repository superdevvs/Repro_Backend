<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeEditedUploadAction
{
    /**
     * Statuses from which an edited-submit is valid.
     * Editors move shoot to REVIEW, admin/editing_manager/superadmin move shoot to READY.
     */
    private const ALLOWED_FROM_STATUSES = [
        Shoot::STATUS_UPLOADED,
        'uploaded',
        Shoot::STATUS_EDITING,
        'editing',
    ];

    /**
     * Statuses from which an editing-manager-style "go-to-ready" submit is also valid
     * (e.g. resubmitting edits while a shoot is already in review).
     */
    private const READY_ALLOWED_FROM_STATUSES = [
        Shoot::STATUS_UPLOADED,
        'uploaded',
        Shoot::STATUS_EDITING,
        'editing',
        Shoot::STATUS_REVIEW,
        'review',
    ];

    /**
     * Statuses that are considered "already past edited-submit" (idempotent no-op).
     */
    private const IDEMPOTENT_STATUSES = [
        Shoot::STATUS_READY,
        'ready',
        'ready_for_client',
        Shoot::STATUS_DELIVERED,
        'delivered',
        'admin_verified',
        'client_delivered',
        'workflow_completed',
    ];

    /**
     * Roles that can immediately mark edits as ready, skipping the editing-manager review step.
     */
    private const READY_SUBMIT_ROLES = [
        'admin',
        'superadmin',
        'super_admin',
        'editing_manager',
    ];

    public function __construct(
        protected ShootMediaMutationSupportService $support,
        protected ShootActivityLogger $activityLogger,
        protected AutomationService $automationService
    ) {
    }

    public function execute(Shoot $shoot, ?User $user): array
    {
        $assignments = app(\App\Services\Shoots\ShootEditingAssignmentService::class);
        $capabilities = app(\App\Services\Shoots\ShootSubmissionCapabilityService::class);
        if ($user?->role === 'editor' && !$assignments->editorHasAssignment($shoot, $user)) {
            return ['status' => 403, 'payload' => ['error_type' => 'forbidden', 'message' => 'This shoot is not assigned to you.', 'workflow_status_changed' => false]];
        }
        $lock = Cache::lock('shoot:finalize-edited:' . $shoot->id, 15);

        if (!$lock->get()) {
            return [
                'status' => 409,
                'payload' => [
                    'error_type' => 'concurrent_finalize',
                    'message' => 'Another submission is already in progress for this shoot. Please try again in a moment.',
                    'workflow_status_changed' => false,
                ],
            ];
        }

        try {
            $workflowStatusChanged = false;
            $previousStatus = null;
            $shouldFireAutomations = false;
            $editingSubmissionChanged = false;

            DB::beginTransaction();

            try {
                /** @var Shoot $locked */
                $locked = Shoot::query()->whereKey($shoot->id)->lockForUpdate()->first();
                if (!$locked) {
                    DB::rollBack();
                    return [
                        'status' => 404,
                        'payload' => [
                            'error_type' => 'not_found',
                            'message' => 'Shoot not found.',
                            'workflow_status_changed' => false,
                        ],
                    ];
                }

                $shoot = $this->support->refreshMediaCounters($locked);
                $this->support->clearShootFilesCache($shoot);

                $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status ?? ''));
                $previousStatus = $currentStatus;

                $userRole = strtolower((string) ($user->role ?? ''));
                $canSkipReview = in_array($userRole, self::READY_SUBMIT_ROLES, true)
                    && ! app(\App\Services\Studio\WorkspaceShootReview::class)->isPending($shoot);

                $allowedFromStatuses = $canSkipReview
                    ? array_map('strtolower', self::READY_ALLOWED_FROM_STATUSES)
                    : array_map('strtolower', self::ALLOWED_FROM_STATUSES);
                $idempotent = array_map('strtolower', self::IDEMPOTENT_STATUSES);

                $canResubmitReady = in_array($currentStatus, [Shoot::STATUS_READY, 'ready'], true)
                    && $this->hasNewEditedFilesSinceSubmit($shoot, $user);
                $pendingEditorLane = $user?->role === 'editor' && $capabilities->pendingEditorAssignments($shoot, $user);
                $canSubmitPendingLane = $pendingEditorLane && in_array($currentStatus, array_merge(['review', 'ready'], $capabilities::DELIVERED_STATUSES), true);
                if ($user?->role === 'editor' && !$pendingEditorLane && !$canResubmitReady && $assignments->getTrackedServiceAssignments($shoot)->isNotEmpty()) {
                    DB::commit();
                    return ['status' => 200, 'payload' => ['message' => 'Your assigned edits are already submitted.', 'workflow_status_changed' => false,
                        'editing_submission_changed' => false, 'shoot_status' => $shoot->workflow_status]];
                }

                if (!in_array($currentStatus, $allowedFromStatuses, true) && !$canResubmitReady && !$canSubmitPendingLane) {
                    DB::commit();

                    if (in_array($currentStatus, $idempotent, true)) {
                        return [
                            'status' => 200,
                            'payload' => [
                                'message' => 'Shoot has already been submitted for client review.',
                                'workflow_status_changed' => false,
                                'shoot_status' => $shoot->workflow_status,
                                'raw_photo_count' => $shoot->raw_photo_count,
                                'edited_photo_count' => $shoot->edited_photo_count,
                            ],
                        ];
                    }

                    if (!$canSkipReview && in_array($currentStatus, [Shoot::STATUS_REVIEW, 'review'], true)) {
                        return [
                            'status' => 200,
                            'payload' => [
                                'message' => 'Edits already submitted to the editing manager for review.',
                                'workflow_status_changed' => false,
                                'shoot_status' => $shoot->workflow_status,
                                'raw_photo_count' => $shoot->raw_photo_count,
                                'edited_photo_count' => $shoot->edited_photo_count,
                            ],
                        ];
                    }

                    return [
                        'status' => 409,
                        'payload' => [
                            'error_type' => 'invalid_workflow_state',
                            'message' => sprintf(
                                'Cannot submit edited files while shoot is in state "%s".',
                                $currentStatus
                            ),
                            'workflow_status_changed' => false,
                            'shoot_status' => $shoot->workflow_status,
                        ],
                    ];
                }

                if ($capabilities->editedFiles($shoot, $user)->isEmpty() && !$capabilities->hasVideoLinkOutput($shoot, $user)) {
                    DB::rollBack();
                    return [
                        'status' => 422,
                        'payload' => [
                            'error_type' => 'no_files',
                            'message' => 'No edited files found for this shoot. Upload at least one edited file before submitting.',
                            'workflow_status_changed' => false,
                            'shoot_status' => $shoot->workflow_status,
                            'edited_photo_count' => $shoot->edited_photo_count,
                        ],
                    ];
                }

                if ($user) {
                    $assignments->markAssignedServicesReadyForUser($shoot, $user);
                }
                $allLanesReady = $assignments->allTrackedLanesReady($shoot->fresh(['services.category']));
                $targetStatus = in_array($currentStatus, $capabilities::DELIVERED_STATUSES, true) ? $currentStatus
                    : ($allLanesReady ? ($canSkipReview ? Shoot::STATUS_READY : Shoot::STATUS_REVIEW) : Shoot::STATUS_EDITING);
                $editingSubmissionChanged = true;
                if ($targetStatus !== $currentStatus) {
                    $shoot->updateWorkflowStatus($targetStatus, $user?->id ?? auth()->id());
                    $workflowStatusChanged = true;
                }
                $shouldFireAutomations = $workflowStatusChanged && in_array($targetStatus, [Shoot::STATUS_READY, Shoot::STATUS_REVIEW], true);

                DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();

                return [
                    'status' => 500,
                    'payload' => [
                        'error_type' => 'server_error',
                        'message' => 'Failed to finalize edited upload queue',
                        'error' => $exception->getMessage(),
                        'workflow_status_changed' => false,
                    ],
                ];
            }

            $finalStatus = strtolower((string) ($shoot->workflow_status ?? ''));
            $movedToReview = $finalStatus === Shoot::STATUS_REVIEW;

            if ($shouldFireAutomations) {
                try {
                    $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
                    $context = $this->automationService->buildShootContext($shoot);
                    if ($shoot->rep) {
                        $context['rep'] = $shoot->rep;
                    }
                    $automationEvent = $movedToReview ? 'EDITING_PENDING_REVIEW' : 'EDITING_COMPLETE';
                    $this->automationService->handleEvent($automationEvent, $context);
                } catch (\Throwable $e) {
                    Log::warning('Automation dispatch failed during finalize-edited', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }

            }

            if ($editingSubmissionChanged) {
                try {
                    $this->activityLogger->log(
                        $shoot,
                        $movedToReview ? 'shoot_submitted_for_editing_review' : 'shoot_submitted_edited',
                        [
                            'from_status' => $previousStatus,
                            'to_status' => $shoot->workflow_status,
                            'role' => $user?->role,
                            'user_id' => $user?->id,
                            'edited_photo_count' => (int) $shoot->edited_photo_count,
                        ],
                        $user
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to log shoot_submitted_edited activity', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $message = 'Edited upload queue finalized with no workflow change';
            if ($editingSubmissionChanged && $finalStatus === Shoot::STATUS_EDITING) {
                $message = 'Your edits were submitted. Waiting for the remaining editing assignments.';
            } elseif ($editingSubmissionChanged && !$workflowStatusChanged) {
                $message = 'Your editing assignment was submitted. The shoot delivery status is unchanged.';
            } elseif ($workflowStatusChanged) {
                $message = $movedToReview
                    ? 'Edits submitted to the editing manager for review.'
                    : 'Edited files submitted successfully. Shoot is now Ready for finalization.';
            }

            return [
                'status' => 200,
                'payload' => [
                    'message' => $message,
                    'workflow_status_changed' => $workflowStatusChanged,
                    'editing_submission_changed' => $editingSubmissionChanged,
                    'shoot_status' => $shoot->workflow_status,
                    'raw_photo_count' => $shoot->raw_photo_count,
                    'edited_photo_count' => $shoot->edited_photo_count,
                    'raw_missing_count' => $shoot->raw_missing_count,
                    'edited_missing_count' => $shoot->edited_missing_count,
                    'missing_raw' => $shoot->missing_raw,
                    'missing_final' => $shoot->missing_final,
                ],
            ];
        } finally {
            optional($lock)->release();
        }
    }

    private function hasNewEditedFilesSinceSubmit(Shoot $shoot, ?User $user): bool
    {
        if (!$shoot->editing_completed_at) {
            return true;
        }

        return app(\App\Services\Shoots\ShootSubmissionCapabilityService::class)->editedFiles($shoot, $user)
            ->contains(fn ($file) => $file->created_at > $shoot->editing_completed_at);
    }
}
