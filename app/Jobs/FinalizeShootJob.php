<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\ShootActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fast-path finalize.
 *
 * Turns the user-facing delivery transition into a single short DB
 * transaction:
 *   - Bulk-flip STAGE_COMPLETED -> STAGE_VERIFIED for the targeted files.
 *   - Sync service-item rollups.
 *   - Set shoot workflow_status to DELIVERED when the full order is covered.
 *   - Write a single finalize_completed workflow log.
 *
 * All I/O-heavy / third-party work (Dropbox local cache, Bright MLS auto
 * publish, ready email, automation event) is handed off to isolated,
 * retryable jobs so finalize stays sub-second and never blocks on an
 * unhealthy Dropbox/MLS/mail provider.
 *
 * Backwards-compatible with the previous workflow-log vocabulary:
 * emits finalize_started / finalize_completed / finalize_failed and
 * shoot_finalized_delivered activity entries.
 */
class FinalizeShootJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [15, 60];
    public int $timeout = 120;

    public function __construct(
        public int $shootId,
        public int $userId,
        public ?string $finalStatus = null,
        public ?int $shootServiceId = null,
        public bool $allowNoMediaDelivery = false
    ) {
        $this->onQueue('default');
    }

    public function handle(ShootActivityLogger $activityLogger): void
    {
        $lock = Cache::lock("shoot:finalize:{$this->shootId}", 120);
        if (!$lock->get()) {
            Log::info('Finalize skipped: another finalize is already running', [
                'shoot_id' => $this->shootId,
            ]);
            return;
        }

        try {
            $result = $this->commit();
            if (!$result) {
                return; // commit() already wrote a failure log.
            }

            $shoot = $result['shoot'];
            $isFullOrderDelivery = $result['is_full_order_delivery'];
            $processedFileIds = $result['processed_file_ids'];
            $previousStatus = $result['previous_status'];

            // ------ Side effects (async, non-blocking) ------
            // Media-dependent side effects (Bright MLS publish + the
            // per-file local-cache jobs) only make sense when media was
            // actually delivered, so they stay gated on processed files. The
            // client delivery email, however, is a mandatory transactional
            // notification and must fire on every full-order delivered
            // transition (see below).
            $this->dispatchLocalCacheJobs($processedFileIds);
            if (!empty($processedFileIds)) {
                $this->dispatchMlsPublish($isFullOrderDelivery);
            }

            // The client delivery email is the canonical, mandatory
            // notification for the full-order delivered transition. It must
            // fire on every full-order delivery — including no-media
            // (fast-forward) deliveries that have no processed files — so it
            // is intentionally NOT gated on $processedFileIds.
            if ($isFullOrderDelivery) {
                $this->dispatchReadyEmail($isFullOrderDelivery);
            }

            // Activity log parity with the previous implementation.
            try {
                $actor = User::find($this->userId);
                $activityLogger->log(
                    $shoot,
                    'shoot_finalized_delivered',
                    [
                        'finalized_by_role' => $actor?->role,
                        'finalized_by_name' => $actor?->name,
                        'processed_files' => count($processedFileIds),
                        'total_files' => count($processedFileIds),
                        'result_status' => $isFullOrderDelivery ? Shoot::STATUS_DELIVERED : 'partially_delivered',
                        'final_status' => $this->finalStatus,
                        'shoot_service_id' => $this->shootServiceId,
                        'full_order_delivery' => $isFullOrderDelivery,
                        'previous_status' => $previousStatus,
                        'allow_no_media_delivery' => $this->allowNoMediaDelivery,
                        'shoot_type' => $shoot->shoot_type,
                        'product_status' => $shoot->product_status,
                    ],
                    $actor
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to log finalize activity', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $shoot->workflowLogs()->create([
                'user_id' => $this->userId,
                'action' => 'finalize_completed',
                'details' => 'Finalize committed (fast path); side effects queued',
                'metadata' => [
                    'processed_files' => count($processedFileIds),
                    'completed_at' => now()->toISOString(),
                    'result_status' => $isFullOrderDelivery ? Shoot::STATUS_DELIVERED : 'partially_delivered',
                    'final_status' => $this->finalStatus,
                    'shoot_service_id' => $this->shootServiceId,
                    'full_order_delivery' => $isFullOrderDelivery,
                    'allow_no_media_delivery' => $this->allowNoMediaDelivery,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Finalize job failed (fast path)', [
                'shoot_id' => $this->shootId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            try {
                $shoot = Shoot::query()->find($this->shootId);
                $shoot?->workflowLogs()->create([
                    'user_id' => $this->userId,
                    'action' => 'finalize_failed',
                    'details' => 'Finalize fast-path failed',
                    'metadata' => [
                        'final_status' => $this->finalStatus,
                        'shoot_service_id' => $this->shootServiceId,
                        'failed_at' => now()->toISOString(),
                        'error' => $e->getMessage(),
                    ],
                ]);
            } catch (\Throwable $logEx) {
                Log::warning('Failed to persist finalize_failed log', [
                    'shoot_id' => $this->shootId,
                    'error' => $logEx->getMessage(),
                ]);
            }

            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Single short DB transaction that performs the user-visible "finalize"
     * step. Returns a context array on success, or null on a validated
     * failure (e.g. no files, invalid status) after writing the appropriate
     * finalize_failed workflow log.
     *
     * @return array{shoot: Shoot, is_full_order_delivery: bool, processed_file_ids: array<int,int>, previous_status: ?string}|null
     */
    protected function commit(): ?array
    {
        return DB::transaction(function () {
            /** @var Shoot|null $shoot */
            $shoot = Shoot::query()->whereKey($this->shootId)->lockForUpdate()->first();
            if (!$shoot) {
                Log::warning('Finalize aborted: shoot not found', [
                    'shoot_id' => $this->shootId,
                ]);
                return null;
            }

            $previousStatus = $shoot->workflow_status;

            // Resolve optional service-item scope.
            if ($this->shootServiceId) {
                $serviceExists = $shoot->serviceItems()->whereKey($this->shootServiceId)->exists();
                if (!$serviceExists) {
                    $shoot->workflowLogs()->create([
                        'user_id' => $this->userId,
                        'action' => 'finalize_failed',
                        'details' => 'Finalize aborted: selected service item does not belong to this shoot',
                        'metadata' => [
                            'shoot_service_id' => $this->shootServiceId,
                            'failed_at' => now()->toISOString(),
                        ],
                    ]);
                    return null;
                }
            }

            // Fetch file IDs we will flip. Intentionally do NOT hydrate file
            // contents (no N+1, no byte copy).
            $fileQuery = ShootFile::query()
                ->where('shoot_id', $shoot->id)
                ->where('workflow_stage', ShootFile::STAGE_COMPLETED);
            if ($this->shootServiceId) {
                $fileQuery->where('shoot_service_id', $this->shootServiceId);
            }
            $completedIds = $fileQuery->pluck('id')->all();

            $rawCount = ShootFile::query()
                ->where('shoot_id', $shoot->id)
                ->where('workflow_stage', ShootFile::STAGE_TODO)
                ->when($this->shootServiceId, fn ($q) => $q->where('shoot_service_id', $this->shootServiceId))
                ->count();
            $hasEditedWithoutRaw = !empty($completedIds) && $rawCount === 0;
            // Revalidate eligibility inside the job so a forged/stale queued
            // payload cannot bypass the controller's no-media restrictions.
            $allowNoMediaDelivery = $this->allowNoMediaDelivery
                && !$this->shootServiceId
                && $shoot->allowsNoMediaDelivery();

            $allowedStatuses = [Shoot::STATUS_EDITING, Shoot::STATUS_READY, Shoot::STATUS_UPLOADED];
            if (!in_array($shoot->workflow_status, $allowedStatuses, true) && !$hasEditedWithoutRaw && !$allowNoMediaDelivery) {
                $shoot->workflowLogs()->create([
                    'user_id' => $this->userId,
                    'action' => 'finalize_failed',
                    'details' => 'Finalize aborted: invalid shoot status for finalization',
                    'metadata' => [
                        'current_status' => $shoot->workflow_status,
                        'final_status' => $this->finalStatus,
                        'failed_at' => now()->toISOString(),
                    ],
                ]);
                return null;
            }

            if (empty($completedIds) && !$allowNoMediaDelivery) {
                $shoot->workflowLogs()->create([
                    'user_id' => $this->userId,
                    'action' => 'finalize_failed',
                    'details' => 'Finalize aborted: no edited files found',
                    'metadata' => [
                        'current_status' => $shoot->workflow_status,
                        'final_status' => $this->finalStatus,
                        'failed_at' => now()->toISOString(),
                    ],
                ]);
                return null;
            }

            $shoot->workflowLogs()->create([
                'user_id' => $this->userId,
                'action' => 'finalize_started',
                'details' => 'Finalize (fast path) started',
                'metadata' => [
                    'started_at' => now()->toISOString(),
                    'total_files' => count($completedIds),
                    'final_status' => $this->finalStatus,
                    'shoot_service_id' => $this->shootServiceId,
                    'allow_no_media_delivery' => $allowNoMediaDelivery,
                    'shoot_type' => $shoot->shoot_type,
                    'product_status' => $shoot->product_status,
                ],
            ]);

            // ---- The hot path: one UPDATE instead of N Http downloads + N saves.
            if (!empty($completedIds)) {
                ShootFile::query()
                    ->whereIn('id', $completedIds)
                    ->update([
                        'workflow_stage' => ShootFile::STAGE_VERIFIED,
                        'verified_at' => now(),
                        'verified_by' => $this->userId,
                    ]);
            }

            // ---- Service-item rollups.
            $isFullOrderDelivery = true;
            if ($this->shootServiceId) {
                $serviceItem = $shoot->serviceItems()->whereKey($this->shootServiceId)->first();
                if ($serviceItem) {
                    $serviceItem->forceFill([
                        'workflow_status' => ShootService::WORKFLOW_DELIVERED,
                        'delivery_status' => ShootService::DELIVERY_DELIVERED,
                        'delivered_at' => now(),
                        'ready_at' => $serviceItem->ready_at ?? now(),
                    ])->save();
                }

                $rollups = $shoot->fresh()->syncServiceItemRollups();
                $isFullOrderDelivery = ($rollups['delivery_status'] ?? null) === 'delivered';
                $shoot->refresh();
            } else {
                $deliverableItems = $shoot->serviceItems()
                    ->where('is_deliverable', true)
                    ->where('workflow_status', '!=', ShootService::WORKFLOW_CANCELLED)
                    ->where('delivery_status', '!=', ShootService::DELIVERY_CANCELLED)
                    ->get();

                if ($deliverableItems->isNotEmpty()) {
                    foreach ($deliverableItems as $item) {
                        $item->forceFill([
                            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
                            'delivery_status' => ShootService::DELIVERY_DELIVERED,
                            'delivered_at' => $item->delivered_at ?? now(),
                            'ready_at' => $item->ready_at ?? now(),
                        ])->save();
                    }

                    $shoot->fresh()->syncServiceItemRollups();
                    $shoot->refresh();
                }
            }

            if ($isFullOrderDelivery) {
                $shoot->updateWorkflowStatus(Shoot::STATUS_DELIVERED, $this->userId);
            }

            return [
                'shoot' => $shoot,
                'is_full_order_delivery' => $isFullOrderDelivery,
                'processed_file_ids' => $completedIds,
                'previous_status' => $previousStatus,
            ];
        });
    }

    /**
     * Dispatch per-file best-effort local-cache jobs. These only do meaningful
     * work for files that have a dropbox_path and no local copy; the job
     * itself is idempotent.
     */
    protected function dispatchLocalCacheJobs(array $fileIds): void
    {
        if (empty($fileIds)) {
            return;
        }

        $cacheableIds = ShootFile::query()
            ->whereIn('id', $fileIds)
            ->whereNotNull('dropbox_path')
            ->where('dropbox_path', '!=', '')
            ->pluck('id')
            ->all();

        foreach ($cacheableIds as $id) {
            try {
                CacheShootFinalToLocalJob::dispatch((int) $id);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch CacheShootFinalToLocalJob', [
                    'shoot_file_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function dispatchMlsPublish(bool $isFullOrderDelivery): void
    {
        if (!$isFullOrderDelivery) {
            return;
        }

        try {
            PublishShootToBrightMlsJob::dispatch($this->shootId, $this->userId);
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch PublishShootToBrightMlsJob', [
                'shoot_id' => $this->shootId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function dispatchReadyEmail(bool $isFullOrderDelivery): void
    {
        try {
            SendShootReadyEmailJob::dispatch(
                $this->shootId,
                $this->shootServiceId,
                $isFullOrderDelivery,
                $isFullOrderDelivery
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch SendShootReadyEmailJob', [
                'shoot_id' => $this->shootId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
