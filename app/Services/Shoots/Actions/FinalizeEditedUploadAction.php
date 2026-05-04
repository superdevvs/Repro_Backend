<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
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
     * Statuses from which an edited-submit is valid (moves shoot to READY).
     */
    private const ALLOWED_FROM_STATUSES = [
        Shoot::STATUS_UPLOADED,
        'uploaded',
        Shoot::STATUS_EDITING,
        'editing',
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

    public function __construct(
        protected ShootMediaMutationSupportService $support,
        protected ShootActivityLogger $activityLogger,
        protected AutomationService $automationService
    ) {
    }

    public function execute(Shoot $shoot, ?User $user): array
    {
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

                $allowed = array_map('strtolower', self::ALLOWED_FROM_STATUSES);
                $idempotent = array_map('strtolower', self::IDEMPOTENT_STATUSES);

                if (!in_array($currentStatus, $allowed, true)) {
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

                if ((int) $shoot->edited_photo_count <= 0) {
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

                $shoot->updateWorkflowStatus(Shoot::STATUS_READY, $user?->id ?? auth()->id());
                $workflowStatusChanged = true;
                $shouldFireAutomations = true;

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

            if ($shouldFireAutomations) {
                try {
                    $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
                    $context = $this->automationService->buildShootContext($shoot);
                    if ($shoot->rep) {
                        $context['rep'] = $shoot->rep;
                    }
                    $this->automationService->handleEvent('EDITING_COMPLETE', $context);
                } catch (\Throwable $e) {
                    Log::warning('Automation dispatch failed during finalize-edited', [
                        'shoot_id' => $shoot->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $this->activityLogger->log(
                        $shoot,
                        'shoot_submitted_edited',
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

            return [
                'status' => 200,
                'payload' => [
                    'message' => $workflowStatusChanged
                        ? 'Edited files submitted successfully. Shoot is now Ready for finalization.'
                        : 'Edited upload queue finalized with no workflow change',
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
}
