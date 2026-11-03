<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\SyncShootIguideJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        Shoot::STATUS_ACTIVE,
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

            $correlationId = (string) Str::uuid();
            try {
                $earlyResult = LockedWrite::run(function () use (
                    &$shoot, $user, &$workflowStatusChanged, &$previousStatus,
                    &$shouldQueueIguideSync, &$shouldFireAutomations
                ) {
                    $workflowStatusChanged = $shouldQueueIguideSync = $shouldFireAutomations = false;
                    return DB::transaction(function () use (
                        &$shoot, $user, &$workflowStatusChanged, &$previousStatus,
                        &$shouldQueueIguideSync, &$shouldFireAutomations
                    ) {
                    // Acquire SQLite's writer lock before reading the counters or
                    // workflow. A retry starts from a fresh model and snapshot.
                    DB::table('shoots')->where('id', $shoot->id)
                        ->update(['updated_at' => DB::raw('updated_at')]);
                    /** @var Shoot $locked */
                    $locked = Shoot::query()->whereKey($shoot->id)->lockForUpdate()->first();
                    if (!$locked) {
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

                    $currentStatus = strtolower((string) ($shoot->workflow_status ?? $shoot->status ?? ''));
                    $previousStatus = $currentStatus;
                    $activeCycle = app(\App\Services\Shoots\ShootActiveWorkLifecycle::class)->hasActiveWork($shoot);
                    $activeFiles = collect();
                    if ($activeCycle) {
                        if (in_array($currentStatus, ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'], true)) {
                            return ['status' => 409, 'payload' => ['error_type' => 'invalid_workflow_state',
                                'message' => 'Resume the additional work before submitting RAW files.', 'workflow_status_changed' => false]];
                        }
                        if (! $user || ! in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'photographer'], true)
                            || ($user->role === 'photographer' && ! app(\App\Services\Shoots\ShootActiveWorkLifecycle::class)->outstandingItems($shoot)
                                ->contains(fn ($item) => (int) $item->photographer_id === (int) $user->id))) {
                            return ['status' => 403, 'payload' => ['error_type' => 'forbidden',
                                'message' => 'Only the outstanding service photographer or production staff may submit this work.', 'workflow_status_changed' => false]];
                        }
                        $activeFiles = self::pendingActiveRawFiles($shoot, $user);
                        if ($activeFiles->isEmpty()) {
                            return ['status' => 422, 'payload' => ['error_type' => 'no_new_files',
                                'message' => 'No new RAW files are awaiting submission for your outstanding services.', 'workflow_status_changed' => false]];
                        }
                    }

                    // Strict state validation.
                    $allowed = array_map('strtolower', self::ALLOWED_FROM_STATUSES);
                    $idempotent = array_map('strtolower', self::IDEMPOTENT_STATUSES);

                    $canResubmitUploaded = in_array($currentStatus, [Shoot::STATUS_UPLOADED, 'uploaded'], true)
                        && $this->hasNewRawFilesSinceSubmit($shoot);

                    if (! $activeCycle && !in_array($currentStatus, $allowed, true) && !$canResubmitUploaded) {

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

                    if (! $activeCycle && (int) $shoot->raw_photo_count <= 0) {
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

                    if ($activeCycle) $this->recordActiveRawSubmission($shoot, $activeFiles);
                    $shoot->updateWorkflowStatus(Shoot::STATUS_UPLOADED, $user?->id ?? auth()->id());
                    $workflowStatusChanged = true;
                    $shouldQueueIguideSync = true;
                    $shouldFireAutomations = true;
                    return null;
                    });
                }, "shoot.{$shoot->id}.finalize-raw", 6);
            } catch (\Throwable $exception) {
                Log::channel('uploads')->error('RAW submission failed.', [
                    'shoot_id' => $shoot->id, 'correlation_id' => $correlationId,
                    'exception' => $exception::class,
                    'lock_contention' => LockedWrite::isLockContention($exception),
                ]);
                return [
                    'status' => LockedWrite::isLockContention($exception) ? 503 : 500,
                    'payload' => [
                        'error_type' => LockedWrite::isLockContention($exception) ? 'submission_busy' : 'server_error',
                        'message' => 'The server could not confirm submission. Your uploaded files are saved; please retry.',
                        'correlation_id' => $correlationId, 'workflow_status_changed' => false,
                    ],
                ];
            }
            try {
                LockedWrite::run(fn () => $this->support->clearShootFilesCache($shoot), "shoot.{$shoot->id}.finalize-cache", 6);
            } catch (\Throwable $exception) {
                Log::channel('uploads')->error('RAW submission cache refresh failed after commit.', [
                    'shoot_id' => $shoot->id, 'correlation_id' => $correlationId,
                    'exception' => $exception::class,
                ]);
            }
            if ($earlyResult !== null) {
                return $earlyResult;
            }

            app(\App\Services\Shoots\ShootArchivePrewarmService::class)->afterRawSubmission($shoot);

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
                try {
                    LockedWrite::run(fn () => SyncShootIguideJob::dispatch($shoot->id), "shoot.{$shoot->id}.finalize-iguide", 6);
                } catch (\Throwable $exception) {
                    // The submit is already committed. A secondary integration
                    // failure must not make a successful RAW handoff appear lost.
                    Log::channel('uploads')->error('RAW submission integration enqueue failed after commit.', [
                        'shoot_id' => $shoot->id, 'correlation_id' => $correlationId,
                        'exception' => $exception::class,
                    ]);
                }
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

    /** The capability and mutation share the same service-owned, cycle-owned input evidence. */
    public static function pendingActiveRawFiles(Shoot $shoot, ?User $user): \Illuminate\Support\Collection
    {
        $lifecycle = app(\App\Services\Shoots\ShootActiveWorkLifecycle::class);
        if (! $lifecycle->hasActiveWork($shoot) || ! $user
            || ! in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'photographer'], true)
            || in_array(strtolower((string) ($shoot->workflow_status ?: $shoot->status)), ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'], true)) return collect();
        $items = $lifecycle->outstandingItems($shoot);
        if ($user->role === 'photographer') $items = $items->where('photographer_id', $user->id);
        if ($items->isEmpty()) return collect();
        $files = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)
            ->whereIn('shoot_service_id', $items->modelKeys())
            ->where(fn ($query) => $query->whereNull('flag_reason')->orWhere('flag_reason', ''))
            ->where(fn ($query) => $query->whereNull('is_extra')->orWhere('is_extra', false)->orWhere('required_for_editing', true))
            ->get();
        $cycle = $shoot->active_work_started_at->toIso8601String();
        return app(\App\Services\Shoots\ServiceDeliveryEligibility::class)->currentCycleFiles($shoot, $files)
            ->filter(function (ShootFile $file) use ($cycle) {
                if ($file->isBlockedFromDelivery()) return false;
                $receipt = data_get($file->metadata, 'active_work_raw_submission', []);
                return ($receipt['cycle'] ?? null) !== $cycle
                    || (int) ($receipt['content_version'] ?? 0) !== (int) ($file->content_version ?: 1);
            })->values();
    }

    private function recordActiveRawSubmission(Shoot $shoot, \Illuminate\Support\Collection $files): void
    {
        $cycle = $shoot->active_work_started_at->toIso8601String();
        $submittedAt = now()->toIso8601String();
        foreach ($files as $file) {
            $metadata = is_array($file->metadata) ? $file->metadata : [];
            $metadata['active_work_raw_submission'] = ['cycle' => $cycle, 'submitted_at' => $submittedAt,
                'content_version' => (int) ($file->content_version ?: 1)];
            DB::table('shoot_files')->where('shoot_id', $shoot->id)->where('id', $file->id)
                ->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
        }
        $editing = app(\App\Services\Shoots\ShootEditingAssignmentService::class);
        foreach ($files->groupBy('shoot_service_id') as $serviceId => $inputs) {
            $item = $shoot->serviceItems()->whereKey($serviceId)->first();
            if (! $item) continue;
            $updates = ['ready_at' => null, 'updated_at' => now()];
            if ($item->workflow_status === 'ready') $updates['workflow_status'] = $item->scheduled_at ? 'scheduled' : 'pending';
            if ($item->delivery_status === 'ready') $updates['delivery_status'] = 'not_started';
            $lanes = $inputs->map(fn ($file) => $editing->getFileLane($file))->unique();
            if ($lanes->contains('photo')) $updates['editing_completed_at'] = null;
            if ($lanes->contains('video')) {
                $updates[count($editing->serviceEditingLanes($item)) > 1 ? 'video_editing_completed_at' : 'editing_completed_at'] = null;
            }
            DB::table('shoot_service')->where('shoot_id', $shoot->id)->where('id', $serviceId)->update($updates);
        }
    }
}
