<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\SyncShootIguideJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FinalizeRawUploadAction
{
    /**
     * Statuses from which a raw-submit is valid (moves shoot to UPLOADED).
     */
    private const ALLOWED_FROM_STATUSES = [
        Shoot::STATUS_SCHEDULED,
        'scheduled',
        'booked',
        'raw_upload_pending',
    ];

    /**
     * Statuses that are considered "already past raw-submit" (idempotent no-op).
     */
    private const IDEMPOTENT_STATUSES = [
        Shoot::STATUS_UPLOADED,
        'uploaded',
        Shoot::STATUS_EDITING,
        'editing',
        Shoot::STATUS_READY,
        'ready',
        'ready_for_client',
        Shoot::STATUS_DELIVERED,
        'delivered',
        'admin_verified',
        'client_delivered',
        'workflow_completed',
    ];

    public function __construct(
        protected ShootMediaMutationSupportService $support,
        protected ShootActivityLogger $activityLogger,
        protected AutomationService $automationService
    ) {
    }

    public function execute(Shoot $shoot, ?User $user): array
    {
        $lock = Cache::lock('shoot:finalize-raw:' . $shoot->id, 15);

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
            $shouldQueueIguideSync = false;
            $shouldFireAutomations = false;

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

                // Recalculate counters from DB — never trust client-supplied counts.
                $shoot = $this->support->refreshMediaCounters($locked);
                $this->support->clearShootFilesCache($shoot);

                $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status ?? ''));
                $previousStatus = $currentStatus;

                // Strict state validation.
                $allowed = array_map('strtolower', self::ALLOWED_FROM_STATUSES);
                $idempotent = array_map('strtolower', self::IDEMPOTENT_STATUSES);

                $canResubmitUploaded = in_array($currentStatus, [Shoot::STATUS_UPLOADED, 'uploaded'], true)
                    && $this->hasNewRawFilesSinceSubmit($shoot);

                if (!in_array($currentStatus, $allowed, true) && !$canResubmitUploaded) {
                    DB::commit();

                    if (in_array($currentStatus, $idempotent, true)) {
                        // Already submitted / past this stage — idempotent success.
                        return [
                            'status' => 200,
                            'payload' => [
                                'message' => 'Shoot has already been submitted.',
                                'workflow_status_changed' => false,
                                'shoot_status' => $shoot->workflow_status,
                                'raw_photo_count' => $shoot->raw_photo_count,
                                'edited_photo_count' => $shoot->edited_photo_count,
                            ],
                        ];
                    }

                    // Terminal / invalid state (cancelled, declined, on_hold, etc.).
                    return [
                        'status' => 409,
                        'payload' => [
                            'error_type' => 'invalid_workflow_state',
                            'message' => sprintf(
                                'Cannot submit raw files while shoot is in state "%s".',
                                $currentStatus
                            ),
                            'workflow_status_changed' => false,
                            'shoot_status' => $shoot->workflow_status,
                        ],
                    ];
                }

                if ((int) $shoot->raw_photo_count <= 0) {
                    DB::rollBack();
                    return [
                        'status' => 422,
                        'payload' => [
                            'error_type' => 'no_files',
                            'message' => 'No raw files found for this shoot. Upload at least one file before submitting.',
                            'workflow_status_changed' => false,
                            'shoot_status' => $shoot->workflow_status,
                            'raw_photo_count' => $shoot->raw_photo_count,
                        ],
                    ];
                }

                $shoot->updateWorkflowStatus(Shoot::STATUS_UPLOADED, $user?->id ?? auth()->id());
                $workflowStatusChanged = true;
                $shouldQueueIguideSync = true;
                $shouldFireAutomations = true;

                DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();

                return [
                    'status' => 500,
                    'payload' => [
                        'error_type' => 'server_error',
                        'message' => 'Failed to finalize raw upload queue',
                        'error' => $exception->getMessage(),
                        'workflow_status_changed' => false,
                    ],
                ];
            }

            // Post-commit side effects — only on a real status change.
            if ($shouldFireAutomations) {
                try {
                    $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
                    $context = $this->automationService->buildShootContext($shoot);
                    if ($shoot->rep) {
                        $context['rep'] = $shoot->rep;
                    }
                    $this->automationService->handleEvent('PHOTO_UPLOADED', $context);
                    $this->automationService->handleEvent('MEDIA_UPLOAD_COMPLETE', $context);
                } catch (\Throwable $e) {
                    \Log::warning('Automation dispatch failed during finalize-raw', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $this->activityLogger->log(
                        $shoot,
                        'shoot_submitted_raw',
                        [
                            'from_status' => $previousStatus,
                            'to_status' => $shoot->workflow_status,
                            'role' => $user?->role,
                            'user_id' => $user?->id,
                            'raw_photo_count' => (int) $shoot->raw_photo_count,
                        ],
                        $user
                    );
                } catch (\Throwable $e) {
                    \Log::warning('Failed to log shoot_submitted_raw activity', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($shouldQueueIguideSync) {
                SyncShootIguideJob::dispatch($shoot->id);
            }

            return [
                'status' => 200,
                'payload' => [
                    'message' => $workflowStatusChanged
                        ? 'Raw files submitted successfully. Shoot is now Uploaded.'
                        : 'Raw upload queue finalized with no workflow change',
                    'workflow_status_changed' => $workflowStatusChanged,
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

    private function hasNewRawFilesSinceSubmit(Shoot $shoot): bool
    {
        if (!$shoot->photos_uploaded_at) {
            return true;
        }

        return $shoot->files()
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->where('created_at', '>', $shoot->photos_uploaded_at)
            ->exists();
    }
}
