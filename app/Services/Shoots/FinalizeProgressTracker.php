<?php

namespace App\Services\Shoots;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cache-backed progress record for the finalize (delivery) pipeline.
 *
 * Finalize is a fan-out of queued work: FinalizeShootJob commits the delivery
 * transaction, then hands off per-file local caching, Bright MLS auto publish
 * and the client delivery email to isolated jobs. Nothing in that chain is
 * observable from the 202 response, so this tracker records a single
 * per-shoot progress document that the jobs update as they run and the UI
 * polls while the finalize toast is on screen.
 *
 * Design notes:
 *  - Purely advisory. Every write is best-effort and never throws into the
 *    caller: losing progress must never break a delivery.
 *  - Weighted stages. Overall percentage is the sum of completed stage
 *    weights plus, for stages that know their unit count (local file cache),
 *    the fraction actually processed. Stages without a countable unit stay
 *    "indeterminate" while running instead of faking a percentage.
 *  - Sub-job failures are warnings, not delivery failures: they land in
 *    `failures` and the run still finishes as completed, matching the
 *    non-blocking contract of the side-effect jobs.
 */
class FinalizeProgressTracker
{
    public const STAGE_QUEUED = 'queued';
    public const STAGE_COMMIT = 'commit';
    public const STAGE_LOCAL_CACHE = 'local_cache';
    public const STAGE_MLS_PUBLISH = 'mls_publish';
    public const STAGE_DELIVERY_EMAIL = 'delivery_email';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    private const CACHE_PREFIX = 'shoot:finalize:progress:';
    private const CACHE_TTL_SECONDS = 1800;
    private const LOCK_TIMEOUT_SECONDS = 5;
    private const LOCK_WAIT_SECONDS = 3;

    /**
     * Stage blueprint. Weights are relative and normalised into the
     * percentage, so they only need to express "how much of the wait is this".
     *
     * @return array<int, array<string, mixed>>
     */
    private function blueprint(): array
    {
        return [
            [
                'key' => self::STAGE_QUEUED,
                'label' => 'Queued for processing',
                'weight' => 5,
            ],
            [
                'key' => self::STAGE_COMMIT,
                'label' => 'Verifying media and updating delivery status',
                'weight' => 35,
            ],
            [
                'key' => self::STAGE_LOCAL_CACHE,
                'label' => 'Caching delivered files',
                'weight' => 25,
            ],
            [
                'key' => self::STAGE_MLS_PUBLISH,
                'label' => 'Publishing to Bright MLS',
                'weight' => 15,
            ],
            [
                'key' => self::STAGE_DELIVERY_EMAIL,
                'label' => 'Notifying the client',
                'weight' => 20,
            ],
        ];
    }

    /**
     * Begin (or restart) tracking for a shoot. Called from the finalize
     * request handler so the UI has a document to poll immediately, before
     * any worker has picked the job up.
     */
    public function start(int $shootId, array $context = []): void
    {
        $now = now()->toIso8601String();

        $stages = [];
        foreach ($this->blueprint() as $stage) {
            $stages[$stage['key']] = $stage + [
                'status' => $stage['key'] === self::STAGE_QUEUED
                    ? self::STATUS_COMPLETED
                    : self::STATUS_PENDING,
                'message' => null,
                'processed' => null,
                'total' => null,
                'updated_at' => $now,
            ];
        }

        $this->put($shootId, $this->recalculate([
            'shoot_id' => $shootId,
            'run_id' => (string) Str::uuid(),
            'status' => self::STATUS_RUNNING,
            'message' => 'Finalize queued for background processing',
            'error' => null,
            'failures' => [],
            'context' => $context,
            'started_at' => $now,
            'updated_at' => $now,
            'completed_at' => null,
            'stages' => $stages,
        ]));
    }

    public function stageRunning(int $shootId, string $stageKey, ?string $message = null, ?int $total = null): void
    {
        $this->mutate($shootId, function (array $progress) use ($stageKey, $message, $total) {
            $progress = $this->applyStage($progress, $stageKey, [
                'status' => self::STATUS_RUNNING,
                'message' => $message,
                'processed' => $total === null ? null : 0,
                'total' => $total,
            ]);
            $progress['message'] = $message ?? $progress['stages'][$stageKey]['label'] ?? $progress['message'];

            return $progress;
        });
    }

    /**
     * Record N units of countable work done inside a stage (one cached file,
     * for example). Completes the stage once every unit is accounted for.
     */
    public function stageAdvanced(int $shootId, string $stageKey, int $units = 1, ?string $message = null): void
    {
        $this->mutate($shootId, function (array $progress) use ($stageKey, $units, $message) {
            $stage = $progress['stages'][$stageKey] ?? null;
            if (!$stage) {
                return $progress;
            }

            $total = $stage['total'];
            $processed = (int) ($stage['processed'] ?? 0) + max($units, 0);
            if ($total !== null) {
                $processed = min($processed, (int) $total);
            }

            $isDone = $total !== null && $processed >= (int) $total;

            return $this->applyStage($progress, $stageKey, [
                'status' => $isDone ? self::STATUS_COMPLETED : self::STATUS_RUNNING,
                'processed' => $processed,
                'message' => $message ?? $stage['message'],
            ]);
        });
    }

    public function stageCompleted(int $shootId, string $stageKey, ?string $message = null): void
    {
        $this->mutate($shootId, fn (array $progress) => $this->applyStage($progress, $stageKey, [
            'status' => self::STATUS_COMPLETED,
            'message' => $message,
        ]));
    }

    public function stageSkipped(int $shootId, string $stageKey, ?string $message = null): void
    {
        $this->mutate($shootId, fn (array $progress) => $this->applyStage($progress, $stageKey, [
            'status' => self::STATUS_SKIPPED,
            'message' => $message,
        ]));
    }

    /**
     * Mark a stage as failed. Side-effect stages are non-blocking, so this
     * records a warning and lets the run finish; only fail() marks the whole
     * finalize as failed.
     */
    public function stageFailed(int $shootId, string $stageKey, string $error): void
    {
        $this->mutate($shootId, function (array $progress) use ($stageKey, $error) {
            $progress = $this->applyStage($progress, $stageKey, [
                'status' => self::STATUS_FAILED,
                'message' => $error,
            ]);
            $progress['failures'][] = [
                'stage' => $stageKey,
                'label' => $progress['stages'][$stageKey]['label'] ?? $stageKey,
                'error' => $error,
                'failed_at' => now()->toIso8601String(),
            ];

            return $progress;
        });
    }

    /**
     * Mark the whole finalize as failed (the delivery transaction itself did
     * not go through). Any stage still waiting is left as-is so the UI can
     * show where the pipeline stopped.
     */
    public function fail(int $shootId, string $error, ?string $stageKey = null): void
    {
        $this->mutate($shootId, function (array $progress) use ($error, $stageKey) {
            if ($stageKey) {
                $progress = $this->applyStage($progress, $stageKey, [
                    'status' => self::STATUS_FAILED,
                    'message' => $error,
                ]);
            }

            $progress['status'] = self::STATUS_FAILED;
            $progress['error'] = $error;
            $progress['message'] = $error;
            $progress['completed_at'] = now()->toIso8601String();

            return $progress;
        }, recalculate: false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $shootId): ?array
    {
        $progress = Cache::get($this->cacheKey($shootId));

        return is_array($progress) ? $this->present($progress) : null;
    }

    public function forget(int $shootId): void
    {
        Cache::forget($this->cacheKey($shootId));
    }

    /**
     * Shape the stored document for API consumers: stages as an ordered list
     * and no internal-only bookkeeping.
     *
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    private function present(array $progress): array
    {
        $stages = [];
        foreach ($this->blueprint() as $stage) {
            $stored = $progress['stages'][$stage['key']] ?? null;
            if (!$stored) {
                continue;
            }

            $stages[] = [
                'key' => $stage['key'],
                'label' => $stored['label'] ?? $stage['label'],
                'status' => $stored['status'] ?? self::STATUS_PENDING,
                'message' => $stored['message'] ?? null,
                'processed' => $stored['processed'] ?? null,
                'total' => $stored['total'] ?? null,
                'indeterminate' => ($stored['status'] ?? null) === self::STATUS_RUNNING
                    && ($stored['total'] ?? null) === null,
            ];
        }

        $progress['stages'] = $stages;

        return $progress;
    }

    /**
     * @param  array<string, mixed>  $progress
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function applyStage(array $progress, string $stageKey, array $changes): array
    {
        if (!isset($progress['stages'][$stageKey])) {
            return $progress;
        }

        $stage = $progress['stages'][$stageKey];
        foreach ($changes as $key => $value) {
            // A null message/total means "leave whatever is there"; the
            // callers that genuinely reset counts pass explicit values.
            if ($value === null && in_array($key, ['message', 'total'], true)) {
                continue;
            }
            $stage[$key] = $value;
        }
        $stage['updated_at'] = now()->toIso8601String();
        $progress['stages'][$stageKey] = $stage;

        return $progress;
    }

    /**
     * Recompute percentage + terminal status from the stage table.
     *
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    private function recalculate(array $progress): array
    {
        $totalWeight = 0;
        $earnedWeight = 0.0;
        $allTerminal = true;
        $indeterminate = false;
        $runningLabel = null;

        foreach ($progress['stages'] as $stage) {
            $weight = (int) ($stage['weight'] ?? 0);
            $totalWeight += $weight;
            $status = $stage['status'] ?? self::STATUS_PENDING;

            if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_SKIPPED, self::STATUS_FAILED], true)) {
                $earnedWeight += $weight;
                continue;
            }

            $allTerminal = false;

            if ($status !== self::STATUS_RUNNING) {
                continue;
            }

            $runningLabel ??= $stage['label'] ?? null;
            $total = $stage['total'];
            if ($total !== null && (int) $total > 0) {
                $earnedWeight += $weight * (min((int) $stage['processed'], (int) $total) / (int) $total);
                continue;
            }

            // Running with no countable unit: report it as indeterminate
            // rather than inventing a fraction.
            $indeterminate = true;
        }

        $progress['percentage'] = $totalWeight > 0
            ? (int) min(100, round(($earnedWeight / $totalWeight) * 100))
            : 0;
        $progress['indeterminate'] = $indeterminate;
        $progress['updated_at'] = now()->toIso8601String();

        if (($progress['status'] ?? null) === self::STATUS_FAILED) {
            return $progress;
        }

        if ($allTerminal) {
            $progress['status'] = self::STATUS_COMPLETED;
            $progress['percentage'] = 100;
            $progress['indeterminate'] = false;
            $progress['completed_at'] ??= now()->toIso8601String();
            $progress['message'] = empty($progress['failures'])
                ? 'Finalize complete'
                : 'Finalize complete with warnings';

            return $progress;
        }

        $progress['status'] = self::STATUS_RUNNING;
        if ($runningLabel) {
            $progress['message'] = $runningLabel;
        }

        return $progress;
    }

    /**
     * Read-modify-write the progress document under a short atomic lock so
     * parallel workers (one per cached file) cannot clobber each other's
     * increments.
     */
    private function mutate(int $shootId, callable $callback, bool $recalculate = true): void
    {
        $apply = function () use ($shootId, $callback, $recalculate) {
            $progress = Cache::get($this->cacheKey($shootId));
            if (!is_array($progress)) {
                // Nothing is tracking this shoot (expired, or finalize was
                // triggered outside the tracked path) — stay silent.
                return;
            }

            $progress = $callback($progress);
            $this->put($shootId, $recalculate ? $this->recalculate($progress) : $progress);
        };

        try {
            // block() with a callback releases the lock for us.
            Cache::lock($this->cacheKey($shootId) . ':lock', self::LOCK_TIMEOUT_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, $apply);
        } catch (LockTimeoutException) {
            // Could not get the lock in time: still record progress, a rare
            // lost increment is better than a stalled bar.
            $apply();
        } catch (\Throwable $e) {
            Log::debug('Finalize progress update skipped', [
                'shoot_id' => $shootId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function put(int $shootId, array $progress): void
    {
        Cache::put($this->cacheKey($shootId), $progress, self::CACHE_TTL_SECONDS);
    }

    private function cacheKey(int $shootId): string
    {
        return self::CACHE_PREFIX . $shootId;
    }
}
