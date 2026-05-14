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

/**
 * Editing Manager (or admin/superadmin) approves the editor's submitted edits
 * and promotes the shoot from STATUS_REVIEW to STATUS_READY.
 */
class ApproveEditingReviewAction
{
    private const APPROVER_ROLES = [
        'admin',
        'superadmin',
        'super_admin',
        'editing_manager',
    ];

    private const ALLOWED_FROM_STATUSES = [
        Shoot::STATUS_REVIEW,
        'review',
    ];

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
        if (!$user || !in_array(strtolower((string) $user->role), self::APPROVER_ROLES, true)) {
            return [
                'status' => 403,
                'payload' => [
                    'error_type' => 'forbidden',
                    'message' => 'You do not have permission to approve edits for this shoot.',
                    'workflow_status_changed' => false,
                ],
            ];
        }

        $lock = Cache::lock('shoot:approve-editing-review:' . $shoot->id, 15);
        if (!$lock->get()) {
            return [
                'status' => 409,
                'payload' => [
                    'error_type' => 'concurrent_action',
                    'message' => 'Another approval is already in progress for this shoot. Please try again in a moment.',
                    'workflow_status_changed' => false,
                ],
            ];
        }

        try {
            $workflowStatusChanged = false;
            $previousStatus = null;

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

                $allowedFrom = array_map('strtolower', self::ALLOWED_FROM_STATUSES);
                $idempotent = array_map('strtolower', self::IDEMPOTENT_STATUSES);

                if (!in_array($currentStatus, $allowedFrom, true)) {
                    DB::commit();

                    if (in_array($currentStatus, $idempotent, true)) {
                        return [
                            'status' => 200,
                            'payload' => [
                                'message' => 'These edits are already approved.',
                                'workflow_status_changed' => false,
                                'shoot_status' => $shoot->workflow_status,
                            ],
                        ];
                    }

                    return [
                        'status' => 409,
                        'payload' => [
                            'error_type' => 'invalid_workflow_state',
                            'message' => sprintf(
                                'Cannot approve edits while shoot is in state "%s". Editor must submit edits for review first.',
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
                            'message' => 'No edited files found for this shoot. There is nothing to approve.',
                            'workflow_status_changed' => false,
                            'shoot_status' => $shoot->workflow_status,
                            'edited_photo_count' => $shoot->edited_photo_count,
                        ],
                    ];
                }

                $shoot->updateWorkflowStatus(Shoot::STATUS_READY, $user->id);
                $workflowStatusChanged = true;

                DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();

                return [
                    'status' => 500,
                    'payload' => [
                        'error_type' => 'server_error',
                        'message' => 'Failed to approve edits.',
                        'error' => $exception->getMessage(),
                        'workflow_status_changed' => false,
                    ],
                ];
            }

            try {
                $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
                $context = $this->automationService->buildShootContext($shoot);
                if ($shoot->rep) {
                    $context['rep'] = $shoot->rep;
                }
                $this->automationService->handleEvent('EDITING_COMPLETE', $context);
            } catch (\Throwable $e) {
                Log::warning('Automation dispatch failed during editing-review approval', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $this->activityLogger->log(
                    $shoot,
                    'shoot_editing_review_approved',
                    [
                        'from_status' => $previousStatus,
                        'to_status' => $shoot->workflow_status,
                        'approved_by_role' => $user->role,
                        'approved_by_user_id' => $user->id,
                        'edited_photo_count' => (int) $shoot->edited_photo_count,
                    ],
                    $user
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to log shoot_editing_review_approved activity', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'status' => 200,
                'payload' => [
                    'message' => 'Edits approved. Shoot is now Ready for finalization.',
                    'workflow_status_changed' => $workflowStatusChanged,
                    'shoot_status' => $shoot->workflow_status,
                    'edited_photo_count' => $shoot->edited_photo_count,
                ],
            ];
        } finally {
            optional($lock)->release();
        }
    }
}
