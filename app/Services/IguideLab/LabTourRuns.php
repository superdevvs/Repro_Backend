<?php
namespace App\Services\IguideLab;

use App\Jobs\MonitorIguideLabTourRun;
use App\Models\IguideLabCase;
use App\Models\IguideLabComposite;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunChunk;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use App\Support\LockedWrite;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Tour runs (spec 9.1-9.6, 9.8, D3, D4): create, the self-rescheduling monitor tick, sequential chunk creation through
 * the unchanged LabWorkflows::start(), target state sync, cancel of waiting targets and the run DTO.
 *
 * Lab write transactions (write()) take the project row first, re-read, decide and write with predicates on the prior
 * state. Nothing inside them pushes a job, calls LabWorkflows::start()/cancel()/stop(), reads LabWorkflowPolicy, writes
 * the cache or logs: closures return what to do, and the caller does it at the baseline transaction level.
 * Bookkeeping (heartbeat, token, not_ready_ticks, start_attempts, diagnostics, fingerprints) uses toBase() writes,
 * which never move updated_at (spec conventions, 10.6).
 */
class LabTourRuns
{
    public const ACTIVE = ['creating', 'running'];
    public const TOUR_PRESETS = ['golden-hour', 'twilight', 'grass', 'declutter'];
    public const TERMINAL_TARGET_STATES = ['not_admitted', 'ready', 'held', 'failed', 'cancelled'];
    /** Workflow item status => target state (9.3; mirrors the stage map at LabWorkflows.php:407). Anything else is processing. */
    public const ITEM_STATES = ['queued' => 'queued', 'analyzing' => 'processing', 'preparing' => 'processing', 'editing' => 'processing',
        'repairing' => 'processing', 'checking' => 'processing', 'ready' => 'ready', 'needs_review' => 'held', 'failed' => 'failed', 'cancelled' => 'cancelled'];
    /** The call and text of ProcessIguideLabWorkflow::failed() (ProcessIguideLabWorkflow.php:34). */
    public const STALL_CODE = 'workflow_interrupted';
    public const STALL_MESSAGE = 'Processing stopped safely; existing requests and results were retained.';

    /** @var list<array{0:string,1:array}> warnings decided inside a transaction, logged after it */
    private array $deferredLogs = [];

    /**
     * POST runs (9.1). Input is validated by the controller (10.3 E6).
     *
     * @return array{0:IguideLabTourRun,1:bool,2:array} [run, created, run DTO]; the DTO of a new run is built inside the
     *                                                    creating transaction, a replay's in a read snapshot (10.7)
     */
    public function create(IguideLabTourAnalysis $analysis, array $input): array
    {
        $projectId = $analysis->project_id;
        $analysisId = $analysis->id;
        $requestId = strtolower((string) ($input['requestId'] ?? ''));
        $preset = (string) ($input['preset'] ?? '');
        $revision = $input['selectionRevision'] ?? null;
        $sha = strtolower((string) ($input['selectionSha256'] ?? ''));
        $hash = hash('sha256', json_encode(['analysisId' => $analysisId, 'preset' => $preset, 'selectionRevision' => $revision,
            'selectionSha256' => $sha], JSON_THROW_ON_ERROR));

        // 1. Replay before any check (LabWorkflows.php:36-38).
        if ($prior = $this->replay($projectId, $requestId, $hash)) return [$prior, false, $this->readDto($prior)];
        // 2. Facts outside every transaction: the fresh policy start() will freeze (8.1), runnability (10.5).
        $facts = ['policy' => app(LabWorkflowPolicy::class)->snapshot(), 'runnable' => $this->runnable($preset, false),
            'schedulerEnabled' => LabWorkflowScheduler::enabled(), 'tourBudgetEnabled' => LabTourProcessingBudget::enabled()];

        // 3. One lab write transaction.
        [$run, $created, $dto] = $this->write($projectId, 'run-create', function () use ($projectId, $analysisId, $requestId, $preset, $revision, $sha, $hash, $facts) {
            if ($prior = $this->replay($projectId, $requestId, $hash)) return [$prior, false, null];
            $analysis = IguideLabTourAnalysis::where('project_id', $projectId)->findOrFail($analysisId);
            if (! in_array($analysis->status, ['ready', 'cancelled'], true)) throw LabTourErrors::public('analysisNotReady');
            if (LabTourConfig::runsPaused()) throw LabTourErrors::public('runsPaused');
            if (! $facts['runnable']) throw LabTourErrors::public('presetNotRunnable');
            if (! is_int($revision) || $revision !== (int) ($analysis->selections[$preset]['revision'] ?? 0)) throw LabTourErrors::public('staleSelection');
            $view = LabTourView::load($projectId, $analysisId);
            $selections = app(LabTourSelections::class);
            $effective = $selections->effective($view, $preset);
            if (! hash_equals($selections->sha256($preset, $effective), $sha)) throw LabTourErrors::public('selectionMismatch');
            $positions = $view->captures->mapWithKeys(fn ($c) => [strtolower($c->case_id) => (int) $c->position])->all();
            $selected = [];
            foreach ($effective as $caseId => $ids) {
                $ids = array_values(array_unique(array_map('strval', (array) $ids)));
                if ($ids === []) continue;
                sort($ids, SORT_STRING);
                $selected[strtolower((string) $caseId)] = $ids;
            }
            uksort($selected, fn ($a, $b) => ($positions[$a] ?? PHP_INT_MAX) <=> ($positions[$b] ?? PHP_INT_MAX));
            $rooms = array_keys($selected);
            if ($this->duplicateActive($analysis, $preset, $rooms)) throw LabTourErrors::public('duplicateActiveRun');
            if (IguideLabTourRun::where('project_id', $projectId)->where('analysis_id', $analysisId)->count() >= LabTourConfig::maxRunsPerAnalysis()) {
                throw LabTourErrors::public('runLimitReached');
            }
            $project = IguideLabProject::findOrFail($projectId);
            $capacity = app(LabTourRunCapacity::class);
            $fit = $capacity->fit($project, $preset, $rooms, $facts, null);
            $estimate = $capacity->estimate($view, $preset, $facts, null, $fit);
            if ($estimate['roomsThatFit'] === 0) throw LabTourErrors::public('nothingFits');

            $run = IguideLabTourRun::create(['id' => (string) Str::uuid(), 'project_id' => $projectId, 'analysis_id' => $analysisId,
                'request_id' => $requestId, 'request_hash' => $hash, 'preset' => $preset, 'status' => 'creating', 'selection_revision' => $revision,
                'selection_sha256' => $sha, 'estimate' => $estimate, 'chunk_size' => LabTourConfig::chunkSize()]);
            $admitted = array_flip(array_slice($fit[0], 0, $estimate['roomsThatFit']));
            $rows = [];
            foreach ($selected as $caseId => $ids) {
                $in = isset($admitted[$caseId]);
                $rows[] = ['id' => (string) Str::uuid(), 'run_id' => $run->id, 'project_id' => $projectId, 'case_id' => $caseId,
                    'position' => $positions[$caseId], 'candidate_ids' => json_encode($ids), 'state' => $in ? 'waiting' : 'not_admitted',
                    'has_attempt' => false, 'reasons' => json_encode($in ? [] : [$estimate['limitingReason']]), 'acceptance_log' => '[]',
                    'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 100) as $chunk) IguideLabTourRunTarget::insert($chunk);
            LabTourRevisions::bump($analysisId);
            $view = LabTourView::load($projectId, $analysisId);
            $run = $view->runs->firstWhere('id', $run->id);

            return [$run, true, $this->dto($view, $run)];
        });
        if (! $created) return [$run, false, $this->readDto($run)];
        // 4. After commit: a new monitor chain (9.8). A lost push is recovered by the sweeper or a GET kick.
        try {
            LabTourQueue::push(new MonitorIguideLabTourRun($run->id));
        } catch (Throwable $error) {
            Log::warning('Tour run monitor push failed; the sweeper restarts it.', ['runId' => $run->id, 'exceptionClass' => get_class($error)]);
        }

        return [$run, true, $dto];
    }

    /** Run monitor tick (9.2); true = schedule the next tick. Runs after the job claimed the tick (9.8). */
    public function tick(string $runId): bool
    {
        $run = IguideLabTourRun::find($runId);
        if (! $run) return false;
        if (! LabTourConfig::analysisEnabled()) {
            $this->stopMonitor($runId);

            return false;
        }
        $this->syncAndExpire($run->project_id, $runId);   // T1
        $this->stopStalledWorkflows($runId);              // stall check, baseline level
        $this->advanceChunks($runId);                     // reserved chunks, then the next chunk
        $this->complete($run->project_id, $runId);        // T2
        $run = IguideLabTourRun::find($runId);
        if (! $run || ! $this->needsMonitor($run, false)) {
            $this->stopMonitor($runId);

            return false;
        }

        return true;
    }

    /** GET kick (9.8): a new chain for a stale monitor with no pending job, at most once per kick gate. Never throws. */
    public function kickIfStale(IguideLabTourRun $run): void
    {
        try {
            if (! $this->needsMonitor($run, true)) return;
            $seen = $run->monitor_heartbeat_at ?? $run->created_at;
            if ($seen && $seen->gte(now()->subSeconds(LabTourConfig::heartbeatStaleSeconds()))) return;
            if (LabTourQueue::pendingJobExists(LabTourConfig::queueConnection(), LabTourConfig::queue(), 'MonitorIguideLabTourRun', $run->id)) return;
            if (! Cache::add('iguide-lab-tour:kick:run:'.$run->id, 1, LabTourConfig::kickGateSeconds())) return;
            LabTourQueue::push(new MonitorIguideLabTourRun($run->id));
        } catch (Throwable $error) {
            Log::warning('Tour run monitor kick failed.', ['runId' => $run->id, 'exceptionClass' => get_class($error)]);
        }
    }

    /**
     * Lifetime rule (9.2 step 7): active, or cancelled/failed with a bound non-terminal target, and the project active.
     * The idle backstop (no bound item change for monitor_idle_hours) applies only when !$forGet, and only while a bound
     * target is still queued or processing: it is a backstop behind the stall rule for bound workflow items (7b). A run
     * without such a target waits for its own next chunk (runs paused, between chunks, no chunk yet) or for its
     * completion; only this monitor moves it on, so it never goes idle, and the scheduled sweeper restarts a dead chain
     * of it without any client GET (9.9, D4).
     */
    public function needsMonitor(IguideLabTourRun $run, bool $forGet): bool
    {
        $bound = fn () => IguideLabTourRunTarget::where('run_id', $run->id)->whereNotNull('workflow_item_id');
        $boundPending = $bound()->whereIn('state', ['queued', 'processing'])->exists();
        $live = in_array($run->status, self::ACTIVE, true) || (in_array($run->status, ['cancelled', 'failed'], true) && $boundPending);
        if (! $live) return false;
        if (! IguideLabProject::find($run->project_id)?->active()) return false;
        if ($forGet || ! $boundPending) return true;
        $itemIds = $bound()->pluck('workflow_item_id')->all();
        $latest = $run->updated_at;
        $item = IguideLabWorkflowItem::where('project_id', $run->project_id)->whereIn('id', $itemIds)->orderByDesc('updated_at')->first();
        if ($item?->updated_at && (! $latest || $item->updated_at->gt($latest))) $latest = $item->updated_at;

        return $latest !== null && $latest->gt(now()->subHours(LabTourConfig::monitorIdleHours()));
    }

    /**
     * Cancel (9.6 step 2), inside the caller's lab write transaction: waiting targets of every active run of the
     * analysis become cancelled ['cancelled'], reserved chunks are resolved (bound to an existing workflow, whose id is
     * returned for cancellation after the commit, or cancelled), and the run becomes cancelled. The caller bumps the
     * revision when 'changed' is true, cancels the returned workflows and writes the returned warnings ('logs', each
     * [message, context]) at the baseline level, after LockedWrite::run() returned. Nothing here registers a
     * DB::afterCommit callback, which would run after the commit and outside the transaction's try (conventions).
     *
     * @return array{changed:bool,cancelWorkflowIds:list<string>,logs:list<array{0:string,1:array}>}
     */
    public function cancelWaiting(IguideLabTourAnalysis $analysis): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('cancelWaiting() runs inside the caller\'s lab write transaction.');
        $this->deferredLogs = [];
        $changed = false;
        $cancel = [];
        $runs = IguideLabTourRun::where('project_id', $analysis->project_id)->where('analysis_id', $analysis->id)->whereIn('status', self::ACTIVE)->orderBy('created_at')->get();
        foreach ($runs as $run) {
            $cancel = [...$cancel, ...$this->resolveReservedChunks($run, 'cancelled', true)];
            $this->cancelWaitingTargets($run->id, 'cancelled');
            $changed = IguideLabTourRun::whereKey($run->id)->whereIn('status', self::ACTIVE)->toBase()
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'completed_at' => now(), 'updated_at' => now()]) > 0 || $changed;
        }
        $logs = array_values($this->deferredLogs);
        $this->deferredLogs = [];

        return ['changed' => $changed, 'cancelWorkflowIds' => array_values(array_unique($cancel)), 'logs' => $logs];
    }

    /** Run DTO (tourRunSchema, 10.7) from one loaded view; $run must be one of $view->runs. */
    public function dto(LabTourView $view, IguideLabTourRun $run): array
    {
        $targets = $run->targets->sortBy('position')->values();
        $count = fn (string ...$states) => $targets->filter(fn ($t) => in_array($t->state, $states, true))->count();
        if ($run->status === 'failed' && $run->error_code === null) throw new \LogicException('A failed tour run always has an error code.');
        $estimate = (array) $run->estimate;
        $estimate['estimatedMinutes'] = ['min' => (int) ($estimate['estimatedMinutes']['min'] ?? 0), 'max' => (int) ($estimate['estimatedMinutes']['max'] ?? 0)];

        return [
            'id' => $run->id,
            'requestId' => $run->request_id,
            'analysisId' => $run->analysis_id,
            'preset' => $run->preset,
            'status' => $run->status,
            'selectionRevision' => (int) $run->selection_revision,
            'selectionSha256' => $run->selection_sha256,
            'estimate' => $estimate,
            'counts' => ['selected' => $targets->count(), 'admitted' => $targets->count() - $count('not_admitted'),
                'pending' => $count('waiting', 'queued', 'processing'), 'ready' => $count('ready'), 'held' => $count('held'),
                'failed' => $count('failed'), 'cancelled' => $count('cancelled'), 'accepted' => $targets->whereNotNull('accepted_at')->count()],
            'workflows' => $run->chunks->sortBy('position')->filter(fn ($c) => $c->workflow_id !== null)
                ->map(fn ($c) => ['id' => $c->workflow_id, 'requestId' => $c->request_id, 'caseIds' => array_values((array) $c->case_ids)])->values()->all(),
            'targets' => $targets->map(fn (IguideLabTourRunTarget $t) => [
                'caseId' => $t->case_id,
                'candidateIds' => array_values((array) $t->candidate_ids),
                'state' => $t->state,
                'workflowId' => $t->workflow_id,
                'workflowItemId' => $t->workflow_item_id,
                'hasAttempt' => (bool) $t->has_attempt,
                'reasons' => array_values((array) ($t->reasons ?? [])),
                'acceptance' => $t->accepted_at ? ['acceptedAt' => $t->accepted_at->toIso8601String()] : null,
            ])->all(),
            'error' => $run->status === 'failed' ? $run->error_code : null,
            'createdAt' => $run->created_at->toIso8601String(),
            'updatedAt' => $run->updated_at->toIso8601String(),
            'completedAt' => $run->completed_at?->toIso8601String(),
        ];
    }

    public function activeRunId(LabTourView $view, string $preset): ?string
    {
        return $view->runs->first(fn (IguideLabTourRun $run) => $run->preset === $preset && in_array($run->status, self::ACTIVE, true))?->id;
    }

    // ------------------------------------------------------------------ create helpers

    private function replay(string $projectId, string $requestId, string $hash): ?IguideLabTourRun
    {
        $prior = IguideLabTourRun::where('project_id', $projectId)->where('request_id', $requestId)->first();
        if ($prior && ! hash_equals((string) $prior->request_hash, $hash)) throw LabTourErrors::public('requestReused');

        return $prior;
    }

    /** One read snapshot (10.7) for a run DTO outside a write transaction. */
    private function readDto(IguideLabTourRun $run): array
    {
        return DB::transaction(function () use ($run) {
            $view = LabTourView::load($run->project_id, $run->analysis_id);

            return $this->dto($view, $view->runs->firstWhere('id', $run->id));
        });
    }

    /** D1 runs lighting presets only (D1), and only when the capability says the preset is ready (10.5). Never in a transaction. */
    private function runnable(string $preset, bool $fresh): bool
    {
        return LabTourRunCapacity::presetRunsEnabled($preset) && app(LabTourCapabilities::class)->runnable($preset, $fresh) === true;
    }

    /** Duplicate-active guard of 9.1 step 7 (a)-(c). */
    private function duplicateActive(IguideLabTourAnalysis $analysis, string $preset, array $rooms): bool
    {
        $projectId = $analysis->project_id;
        if (IguideLabTourRun::where('project_id', $projectId)->where('analysis_id', $analysis->id)->where('preset', $preset)->whereIn('status', self::ACTIVE)->exists()) return true;
        if ($rooms === []) return false;
        if (IguideLabWorkflowItem::where('project_id', $projectId)->where('preset', $preset)->whereIn('case_id', $rooms)->whereNotIn('status', LabWorkflows::TERMINAL)->exists()) return true;

        return IguideLabTourRunTarget::where('project_id', $projectId)->whereIn('case_id', $rooms)->where('state', 'waiting')
            ->whereIn('run_id', IguideLabTourRun::where('project_id', $projectId)->where('preset', $preset)->whereIn('status', self::ACTIVE)->select('id'))->exists();
    }

    // ------------------------------------------------------------------ tick phases

    /** T1 (9.2 step 2): sync bound targets and finished chunks; on project expiry stop the run with project_expiry. */
    private function syncAndExpire(string $projectId, string $runId): void
    {
        $this->write($projectId, 'run-sync', function () use ($projectId, $runId) {
            $run = IguideLabTourRun::where('project_id', $projectId)->find($runId);
            if (! $run) return;
            $changed = $this->syncTargets($run);
            $this->finishChunks($run);
            if (in_array($run->status, self::ACTIVE, true) && ! IguideLabProject::find($projectId)?->active()) {
                $changed = $this->errorStop($run, 'project_expiry') || $changed;
            }
            if ($changed) $this->touchAndBump($run);
        });
    }

    /** Stall rule (9.3): a non-terminal bound workflow without progress and without a job is stopped like a failed job. */
    private function stopStalledWorkflows(string $runId): void
    {
        $chunks = IguideLabTourRunChunk::where('run_id', $runId)->where('status', 'started')->whereNotNull('workflow_id')->orderBy('position')->get();
        foreach ($chunks as $chunk) {
            $workflow = IguideLabWorkflow::where('project_id', $chunk->project_id)->find($chunk->workflow_id);
            if (! $workflow || $workflow->cancelled_at || in_array($workflow->status, LabWorkflows::TERMINAL, true)) continue;
            $newest = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->max('updated_at');
            $newest = max($workflow->updated_at->getTimestamp(), $newest ? Carbon::parse((string) $newest)->getTimestamp() : 0);
            if ($newest > now()->subSeconds(LabTourConfig::workflowStallSeconds())->getTimestamp()) continue;
            if (LabTourQueue::pendingJobExists('studio', 'studio', 'ProcessIguideLabWorkflow', $workflow->id)) continue;
            try {
                app(LabWorkflows::class)->stop($workflow, self::STALL_CODE, self::STALL_MESSAGE);
            } catch (Throwable $error) {
                Log::warning('Stopping a stalled tour workflow failed.', ['workflowId' => $workflow->id, 'exceptionClass' => get_class($error)]);
            }
        }
    }

    /** 9.2 steps 4-5: crash recovery of reserved chunks, then at most one new chunk (sequential, D3). */
    private function advanceChunks(string $runId): void
    {
        $run = IguideLabTourRun::find($runId);
        if (! $run) return;
        // A chunk left reserved (or cancelled before its in-flight start() created a workflow) is bound to that workflow
        // without calling start() again; only a reserved chunk without a workflow is started again (9.4 steps 4, 7).
        $chunks = IguideLabTourRunChunk::where('run_id', $runId)->whereNull('workflow_id')->whereIn('status', ['reserved', 'cancelled'])->orderBy('position')->get();
        foreach ($chunks as $chunk) {
            if ($workflow = $this->workflowFor($run->project_id, $chunk->request_id)) {
                $this->bind($run->project_id, $runId, $chunk->id, $workflow);
                continue;
            }
            if ($chunk->status === 'reserved' && ! $this->startChunk($runId, $chunk->id, null)) return;
        }
        $this->nextChunk($runId);
    }

    /** 9.4: readiness, source pre-check, reserve with a fresh capacity re-check, then start and bind. */
    private function nextChunk(string $runId): void
    {
        $run = IguideLabTourRun::find($runId);
        if (! $run || ! in_array($run->status, self::ACTIVE, true) || $run->error_code !== null || LabTourConfig::runsPaused()) return;
        if (! IguideLabProject::find($run->project_id)?->active()) return;
        if (IguideLabTourRunChunk::where('run_id', $runId)->whereIn('status', ['reserved', 'started'])->exists()) return;
        $head = IguideLabTourRunTarget::where('run_id', $runId)->where('state', 'waiting')->whereNull('chunk_id')->orderBy('position')->limit(max(1, (int) $run->chunk_size))->get();
        if ($head->isEmpty()) return;
        $facts = $this->readiness($run);
        if ($facts === null) return;
        $cases = IguideLabCase::where('project_id', $run->project_id)->whereIn('id', $head->pluck('case_id')->all())->get()->keyBy('id');
        $checked = [];
        $invalid = [];
        foreach ($head as $target) {
            $checked[] = $target->case_id;
            try {
                app(LabWorkflowSources::class)->capture($cases[$target->case_id] ?? throw new ModelNotFoundException);
            } catch (Throwable) {
                $invalid[] = $target->case_id;
            }
        }
        $chunkId = $this->reserve($run->project_id, $runId, $facts, $checked, $invalid);
        if ($chunkId !== null) $this->startChunk($runId, $chunkId, $facts);
    }

    /**
     * Step 1 of 9.4, in the job process and outside any transaction: runnable (uncached), scheduler and budget off, and a
     * lighting-repair policy when it is enabled. Not ready: not_ready_ticks += 1 (bookkeeping), and after
     * max_not_ready_ticks consecutive misses the run stops with the gate code. Ready resets the counter.
     *
     * @return ?array the facts for the capacity re-check, null when not ready
     */
    private function readiness(IguideLabTourRun $run): ?array
    {
        $code = null;
        $policy = null;
        try {
            if (! LabTourRunCapacity::presetRunsEnabled($run->preset)) $code = 'preset_not_runnable';
            elseif (! $this->runnable($run->preset, true)) $code = 'recipe_unavailable';
            elseif (LabWorkflowScheduler::enabled()) $code = 'scheduler_mode_unsupported';
            elseif (LabTourProcessingBudget::enabled()) $code = 'tour_budget_required';
            else {
                $policy = app(LabWorkflowPolicy::class)->snapshot();
                if (config('iguide-lab.lighting_repair_enabled', false) === true && in_array($run->preset, LabTourRunCapacity::LIGHTING, true)
                    && ! isset($policy['lightingRepair'])) $code = 'recipe_unavailable';
            }
        } catch (Throwable $error) {
            Log::warning('Tour run readiness check failed.', ['runId' => $run->id, 'exceptionClass' => get_class($error)]);
            $code = 'recipe_unavailable';
        }
        if ($code === null) {
            LockedWrite::run(fn () => IguideLabTourRun::whereKey($run->id)->where('not_ready_ticks', '>', 0)->toBase()->update(['not_ready_ticks' => 0]), 'iguide-lab.tour-run-ready');

            return ['policy' => $policy, 'runnable' => true, 'schedulerEnabled' => false, 'tourBudgetEnabled' => false];
        }
        LockedWrite::run(fn () => IguideLabTourRun::whereKey($run->id)->toBase()->increment('not_ready_ticks'), 'iguide-lab.tour-run-not-ready');
        if ((int) IguideLabTourRun::whereKey($run->id)->value('not_ready_ticks') >= LabTourConfig::maxNotReadyTicks()) {
            $this->write($run->project_id, 'run-not-ready', function () use ($run, $code) {
                $fresh = IguideLabTourRun::where('project_id', $run->project_id)->find($run->id);
                if ($fresh && in_array($fresh->status, self::ACTIVE, true) && $this->errorStop($fresh, $code)) $this->touchAndBump($fresh);
            });
        }

        return null;
    }

    /**
     * Reserve (9.4 step 3): capacity re-check over every remaining waiting target, source failures, the duplicate-active
     * re-check, then one chunk of the first chunk_size remaining rooms.
     *
     * @param list<string> $checked case ids that went through the source pre-check
     * @param list<string> $invalid case ids whose source failed it
     */
    private function reserve(string $projectId, string $runId, array $facts, array $checked, array $invalid): ?string
    {
        return $this->write($projectId, 'run-reserve', function () use ($projectId, $runId, $facts, $checked, $invalid) {
            $run = IguideLabTourRun::where('project_id', $projectId)->find($runId);
            if (! $run || ! in_array($run->status, self::ACTIVE, true) || $run->error_code !== null || LabTourConfig::runsPaused()) return null;
            $project = IguideLabProject::find($projectId);
            if (! $project?->active()) return null;
            if (IguideLabTourRunChunk::where('run_id', $runId)->whereIn('status', ['reserved', 'started'])->exists()) return null;
            $waiting = IguideLabTourRunTarget::where('run_id', $runId)->where('state', 'waiting')->whereNull('chunk_id')->orderBy('position')->get();
            if ($waiting->isEmpty()) return null;
            $changed = false;

            [$admitted, $reason] = app(LabTourRunCapacity::class)->fit($project, $run->preset, $waiting->pluck('case_id')->all(), $facts, $run->id);
            $admitted = array_flip($admitted);
            $out = $waiting->reject(fn ($t) => isset($admitted[strtolower($t->case_id)]));
            $changed = $this->setTargets($out, 'not_admitted', [$reason ?? 'capacity_changed']) || $changed;
            $checked = array_flip(array_map('strtolower', $checked));
            $invalid = array_flip(array_map('strtolower', $invalid));
            $candidates = $waiting->filter(fn ($t) => isset($admitted[strtolower($t->case_id)], $checked[strtolower($t->case_id)]));
            $changed = $this->setTargets($candidates->filter(fn ($t) => isset($invalid[strtolower($t->case_id)])), 'cancelled', ['invalid_source']) || $changed;
            $candidates = $candidates->reject(fn ($t) => isset($invalid[strtolower($t->case_id)]));
            $own = IguideLabTourRunChunk::where('run_id', $runId)->whereNotNull('workflow_id')->pluck('workflow_id')->all();
            $busy = $candidates->isEmpty() ? [] : array_flip(array_map('strtolower', IguideLabWorkflowItem::where('project_id', $projectId)
                ->where('preset', $run->preset)->whereIn('case_id', $candidates->pluck('case_id')->all())->whereNotIn('status', LabWorkflows::TERMINAL)
                ->when($own !== [], fn ($q) => $q->whereNotIn('workflow_id', $own))->pluck('case_id')->all()));
            $changed = $this->setTargets($candidates->filter(fn ($t) => isset($busy[strtolower($t->case_id)])), 'cancelled', ['duplicate_active']) || $changed;
            $chunkTargets = $candidates->reject(fn ($t) => isset($busy[strtolower($t->case_id)]))->take(max(1, (int) $run->chunk_size))->values();
            if ($chunkTargets->isEmpty()) {
                if ($changed) $this->touchAndBump($run);

                return null;
            }
            do $requestId = (string) Str::uuid(); while ($requestId === $run->request_id);
            $position = IguideLabTourRunChunk::where('run_id', $runId)->max('position');
            $chunk = IguideLabTourRunChunk::create(['id' => (string) Str::uuid(), 'run_id' => $runId, 'project_id' => $projectId,
                'position' => $position === null ? 0 : (int) $position + 1, 'request_id' => $requestId,
                'case_ids' => $chunkTargets->pluck('case_id')->all(), 'status' => 'reserved']);
            IguideLabTourRunTarget::whereIn('id', $chunkTargets->pluck('id')->all())->where('state', 'waiting')->whereNull('chunk_id')
                ->toBase()->update(['chunk_id' => $chunk->id]);
            if ($changed) $this->touchAndBump($run);

            return $chunk->id;
        });
    }

    /**
     * Pre-start check, start() at the baseline level, then Bind (9.4 steps 4-6), or the failure table (step 8).
     *
     * @return bool true when the chunk is now bound to its workflow
     */
    private function startChunk(string $runId, string $chunkId, ?array $facts): bool
    {
        $run = IguideLabTourRun::find($runId);
        $chunk = IguideLabTourRunChunk::where('run_id', $runId)->find($chunkId);
        if (! $run || ! $chunk || $chunk->status !== 'reserved') return false;
        if ($workflow = $this->workflowFor($run->project_id, $chunk->request_id)) return $this->bind($run->project_id, $runId, $chunkId, $workflow);
        $project = IguideLabProject::find($run->project_id);
        if (! in_array($run->status, self::ACTIVE, true) || $run->error_code !== null || LabTourConfig::runsPaused()
            || ! LabTourConfig::analysisEnabled() || ! $project?->active()) return false;
        $facts ??= $this->readiness($run);
        if ($facts === null) return false;
        LockedWrite::run(fn () => IguideLabTourRunChunk::whereKey($chunkId)->toBase()->increment('start_attempts'), 'iguide-lab.tour-chunk-attempt');
        try {
            // What the workflow controller validates (IguideLabWorkflowController.php:21-28), so start() sees a controller-shaped request.
            $caseIds = array_values((array) $chunk->case_ids);
            if (preg_match(LabTourErrors::UUID_RULE, (string) $chunk->request_id) !== 1 || ! in_array($run->preset, self::TOUR_PRESETS, true)
                || $caseIds === [] || count($caseIds) > LabWorkflowScheduler::maxItems() || array_filter($caseIds, fn ($id) => ! is_string($id) || ! Str::isUuid($id))) {
                throw new HttpException(422, 'The tour chunk is not a valid workflow request.');
            }
            $workflow = app(LabWorkflows::class)->start($project, ['requestId' => $chunk->request_id,
                'items' => array_map(fn ($caseId) => ['caseId' => $caseId, 'preset' => $run->preset, 'style' => null, 'instructions' => ''], $caseIds)]);
        } catch (Throwable $error) {
            // start() may have created the workflow before it threw (LabWorkflows.php:58,60): bind it and ignore the error.
            if ($workflow = $this->workflowFor($run->project_id, $chunk->request_id)) return $this->bind($run->project_id, $runId, $chunkId, $workflow);
            $this->startFailed($run->project_id, $runId, $chunkId, $error);

            return false;
        }

        return $this->bind($run->project_id, $runId, $chunkId, $workflow);
    }

    /**
     * Bind (9.4 step 6), conditional because a POST cancel may have committed while start() ran. A workflow bound to a
     * cancelled run is cancelled after the commit, so no paid step runs for rooms the user cancelled.
     */
    private function bind(string $projectId, string $runId, string $chunkId, IguideLabWorkflow $workflow): bool
    {
        [$bound, $cancel] = $this->write($projectId, 'run-bind', function () use ($projectId, $runId, $chunkId, $workflow) {
            $run = IguideLabTourRun::where('project_id', $projectId)->find($runId);
            $chunk = IguideLabTourRunChunk::where('run_id', $runId)->find($chunkId);
            if (! $run || ! $chunk) return [false, false];
            if (! $this->bindChunk($run, $chunk, $workflow)) return [$chunk->workflow_id === $workflow->id, false];
            LabTourRevisions::bump($run->analysis_id);

            return [true, $run->status === 'cancelled'];
        });
        if ($cancel) app(LabWorkflows::class)->cancel($workflow);

        return $bound;
    }

    /** Failure table of 9.4 step 8 (no workflow exists for the chunk). Only the exception class and HTTP status are kept. */
    private function startFailed(string $projectId, string $runId, string $chunkId, Throwable $error): void
    {
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : null;
        $diagnostic = ['exceptionClass' => get_class($error), 'httpStatus' => $status, 'recordedAt' => now()->toIso8601String()];
        [$kind, $code] = match (true) {
            $status === 401 => ['expire', 'project_expiry'],
            $status === 429 => ['ceiling', 'workflow_ceiling'],
            $status === 409 => ['reject', 'request_conflict'],
            $status === 422 => ['reject', 'chunk_rejected'],
            $error instanceof ModelNotFoundException => ['reject', 'source_missing'],
            default => ['retry', 'chunk_start_failed'],
        };
        $this->write($projectId, 'run-chunk-failed', function () use ($projectId, $runId, $chunkId, $diagnostic, $kind, $code) {
            $run = IguideLabTourRun::where('project_id', $projectId)->find($runId);
            $chunk = IguideLabTourRunChunk::where('run_id', $runId)->find($chunkId);
            if (! $run || ! $chunk || $chunk->status !== 'reserved') return;
            IguideLabTourRunChunk::whereKey($chunkId)->toBase()->update(['diagnostic' => json_encode($diagnostic)]);
            if ($kind === 'retry') {
                // Any other Throwable: the chunk stays reserved for the next tick, until max_chunk_start_attempts and
                // chunk_reserved_seconds are both reached; then it fails as a rejected chunk (chunk_start_failed).
                if ($chunk->start_attempts < LabTourConfig::staleness('max_chunk_start_attempts')
                    || $chunk->created_at->gt(now()->subSeconds(LabTourConfig::staleness('chunk_reserved_seconds')))) return;
                $kind = 'reject';
            }
            $this->failChunk($run, $chunk, $code, $kind === 'ceiling' ? 'not_admitted' : 'cancelled');
            if ($kind === 'expire') {
                $this->errorStop($run, 'project_expiry');
            } elseif ($kind === 'ceiling') {
                $this->setTargets(IguideLabTourRunTarget::where('run_id', $runId)->where('state', 'waiting')->get(), 'not_admitted', ['workflow_ceiling']);
            } else {
                $failures = (int) $run->consecutive_chunk_failures + 1;
                IguideLabTourRun::whereKey($runId)->toBase()->update(['consecutive_chunk_failures' => min(255, $failures)]);
                if ($failures >= LabTourConfig::maxConsecutiveChunkFailures()) $this->errorStop($run, 'chunk_start_failed');
            }
            $this->touchAndBump($run);
        });
    }

    /** T2 (9.2 step 6): every target terminal and no chunk reserved or started ends the run. */
    private function complete(string $projectId, string $runId): void
    {
        $this->write($projectId, 'run-complete', function () use ($projectId, $runId) {
            $run = IguideLabTourRun::where('project_id', $projectId)->find($runId);
            if (! $run || ! in_array($run->status, self::ACTIVE, true)) return;
            if (IguideLabTourRunTarget::where('run_id', $runId)->whereNotIn('state', self::TERMINAL_TARGET_STATES)->exists()) return;
            if (IguideLabTourRunChunk::where('run_id', $runId)->whereIn('status', ['reserved', 'started'])->exists()) return;
            $updated = IguideLabTourRun::whereKey($runId)->whereIn('status', self::ACTIVE)->toBase()
                ->update(['status' => $run->error_code ? 'failed' : 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            if ($updated > 0) LabTourRevisions::bump($run->analysis_id);
        });
    }

    // ------------------------------------------------------------------ transaction-internal helpers

    /** Target state sync (9.3) of every bound target; true when a run-DTO field changed. */
    private function syncTargets(IguideLabTourRun $run): bool
    {
        $targets = IguideLabTourRunTarget::where('run_id', $run->id)->whereNotNull('workflow_item_id')->get();
        if ($targets->isEmpty()) return false;
        $items = IguideLabWorkflowItem::where('project_id', $run->project_id)->whereIn('id', $targets->pluck('workflow_item_id')->all())->get()->keyBy('id');
        $changed = false;
        foreach ($targets as $target) {
            $item = $items[$target->workflow_item_id] ?? null;
            if (! $item) continue;
            $mapped = $this->mapItem($item);
            if ($mapped[3] === $target->synced_fingerprint) continue;
            $changed = $this->applyItem($target, $mapped) || $changed;
        }

        return $changed;
    }

    /** A started chunk whose workflow completed, was cancelled, or has only terminal items is finished (9.2 T1). */
    private function finishChunks(IguideLabTourRun $run): void
    {
        foreach (IguideLabTourRunChunk::where('run_id', $run->id)->where('status', 'started')->get() as $chunk) {
            $workflow = $chunk->workflow_id ? IguideLabWorkflow::where('project_id', $run->project_id)->find($chunk->workflow_id) : null;
            $done = ! $workflow || $workflow->completed_at !== null || $workflow->cancelled_at !== null
                || ! IguideLabWorkflowItem::where('workflow_id', $workflow->id)->whereNotIn('status', LabWorkflows::TERMINAL)->exists();
            if ($done) IguideLabTourRunChunk::whereKey($chunk->id)->where('status', 'started')->toBase()->update(['status' => 'finished', 'updated_at' => now()]);
        }
    }

    /** item -> [state, reasons, hasAttempt, fingerprint] (9.3). */
    private function mapItem(IguideLabWorkflowItem $item): array
    {
        $status = (string) $item->status;
        $state = self::ITEM_STATES[$status] ?? null;
        if ($state === null) {
            $this->deferredLogs[] = ['Unknown workflow item status synced as processing.', ['itemId' => $item->id, 'status' => Str::limit($status, 40, '')]];
            $state = 'processing';
        }
        $data = is_array($item->data) ? $item->data : [];
        $compositeId = is_string($data['compositeId'] ?? null) ? $data['compositeId'] : null;
        $reasons = [];
        if (in_array($state, ['held', 'failed', 'cancelled'], true)) {
            $codes = array_column(array_filter(is_array($data['reviewReasons'] ?? null) ? $data['reviewReasons'] : [], 'is_array'), 'code');
            $reasons = array_slice(array_values(array_unique(array_filter($codes, fn ($c) => is_string($c) && preg_match('/\A[a-z0-9_]{1,64}\z/D', $c) === 1))), 0, 8);
        }
        $hasAttempt = in_array($state, ['ready', 'held'], true) && $compositeId !== null
            && IguideLabComposite::where('project_id', $item->project_id)->whereKey($compositeId)->exists();
        if ($state === 'ready' && ! $hasAttempt) {
            // ready => hasAttempt (tourContract.ts:290): a ready item without its composite is shown as held.
            $state = 'held';
            $reasons = ['result_unconfirmed'];
        }
        if (! array_is_list($reasons)) throw new \LogicException('Target reasons must be a list.');

        return [$state, $reasons, $hasAttempt, hash('sha256', json_encode([$status, (string) $item->getRawOriginal('updated_at'), $compositeId, $reasons]))];
    }

    /** Write a mapped item onto its target; true when a run-DTO field changed. */
    private function applyItem(IguideLabTourRunTarget $target, array $mapped, array $binding = []): bool
    {
        [$state, $reasons, $hasAttempt, $fingerprint] = $mapped;
        $visible = $binding !== [] || $state !== $target->state || $reasons !== array_values((array) ($target->reasons ?? [])) || $hasAttempt !== (bool) $target->has_attempt;
        IguideLabTourRunTarget::whereKey($target->id)->toBase()->update($binding + ['state' => $state, 'reasons' => json_encode($reasons),
            'has_attempt' => $hasAttempt, 'synced_fingerprint' => $fingerprint, 'updated_at' => now()]);

        return $visible;
    }

    /**
     * Bind a chunk to its workflow inside the caller's transaction: chunk started, every item's target bound with the
     * item's state (whatever the target's current state), run running unless it was cancelled.
     */
    private function bindChunk(IguideLabTourRun $run, IguideLabTourRunChunk $chunk, IguideLabWorkflow $workflow): bool
    {
        $items = IguideLabWorkflowItem::where('project_id', $run->project_id)->where('workflow_id', $workflow->id)->orderBy('position')->get();
        $targets = IguideLabTourRunTarget::where('run_id', $run->id)->get()->keyBy(fn ($t) => strtolower($t->case_id));
        $caseIds = [];
        foreach ($items as $item) if (isset($targets[strtolower($item->case_id)])) $caseIds[] = $targets[strtolower($item->case_id)]->case_id;
        $affected = IguideLabTourRunChunk::whereKey($chunk->id)->whereIn('status', ['reserved', 'cancelled'])->whereNull('workflow_id')->toBase()
            ->update(['status' => 'started', 'workflow_id' => $workflow->id, 'case_ids' => json_encode($caseIds !== [] ? $caseIds : array_values((array) $chunk->case_ids)),
                'error_code' => null, 'updated_at' => now()]);
        if ($affected !== 1) return false;
        foreach ($items as $item) {
            $target = $targets[strtolower($item->case_id)] ?? null;
            if (! $target) {
                $this->deferredLogs[] = ['A tour workflow item has no run target.', ['runId' => $run->id, 'itemId' => $item->id]];
                continue;
            }
            $this->applyItem($target, $this->mapItem($item), ['chunk_id' => $chunk->id, 'workflow_id' => $workflow->id, 'workflow_item_id' => $item->id]);
        }
        // A room of the chunk that the workflow does not contain can never progress.
        $this->setTargets(IguideLabTourRunTarget::where('run_id', $run->id)->where('chunk_id', $chunk->id)->whereNull('workflow_item_id')->where('state', 'waiting')->get(),
            'cancelled', ['request_conflict']);
        IguideLabTourRun::whereKey($run->id)->whereIn('status', self::ACTIVE)->toBase()->update(['status' => 'running', 'completed_at' => null, 'consecutive_chunk_failures' => 0]);
        IguideLabTourRun::whereKey($run->id)->toBase()->update(['updated_at' => now()]);

        return true;
    }

    /**
     * Reserved-chunk resolution (9.4 step 9): an existing workflow is bound; otherwise the chunk becomes failed with the
     * code (cancelled in the cancel path) and its still-waiting targets cancelled with the code.
     *
     * @return list<string> workflow ids to cancel after the commit (cancel path only)
     */
    private function resolveReservedChunks(IguideLabTourRun $run, string $code, bool $cancelPath): array
    {
        $cancel = [];
        foreach (IguideLabTourRunChunk::where('run_id', $run->id)->where('status', 'reserved')->orderBy('position')->get() as $chunk) {
            if ($workflow = $this->workflowFor($run->project_id, $chunk->request_id)) {
                if ($this->bindChunk($run, $chunk, $workflow) && $cancelPath) $cancel[] = $workflow->id;
                continue;
            }
            $this->failChunk($run, $chunk, $code, 'cancelled', $cancelPath ? 'cancelled' : 'failed');
        }

        return $cancel;
    }

    /** Error stop (9.4 steps 1, 8, 9.2 T1): resolve reserved chunks, cancel every waiting target, set the run error. */
    private function errorStop(IguideLabTourRun $run, string $code): bool
    {
        $before = IguideLabTourRunTarget::where('run_id', $run->id)->where('state', 'waiting')->count()
            + IguideLabTourRunChunk::where('run_id', $run->id)->where('status', 'reserved')->count();
        $this->resolveReservedChunks($run, $code, false);
        $this->cancelWaitingTargets($run->id, $code);
        IguideLabTourRun::whereKey($run->id)->whereIn('status', self::ACTIVE)->whereNull('error_code')->toBase()->update(['error_code' => $code]);

        return $before > 0;
    }

    private function failChunk(IguideLabTourRun $run, IguideLabTourRunChunk $chunk, string $code, string $targetState, string $chunkStatus = 'failed'): void
    {
        IguideLabTourRunChunk::whereKey($chunk->id)->where('status', 'reserved')->toBase()->update(['status' => $chunkStatus, 'error_code' => $code, 'updated_at' => now()]);
        $this->setTargets(IguideLabTourRunTarget::where('run_id', $run->id)->where('chunk_id', $chunk->id)->where('state', 'waiting')->get(), $targetState, [$code]);
    }

    private function cancelWaitingTargets(string $runId, string $code): void
    {
        $this->setTargets(IguideLabTourRunTarget::where('run_id', $runId)->where('state', 'waiting')->get(), 'cancelled', [$code]);
    }

    /** Move still-waiting targets to an unbound terminal state; true when any changed. */
    private function setTargets(Collection $targets, string $state, array $reasons): bool
    {
        if ($targets->isEmpty()) return false;

        return IguideLabTourRunTarget::whereIn('id', $targets->pluck('id')->all())->where('state', 'waiting')->toBase()
            ->update(['state' => $state, 'reasons' => json_encode(array_values($reasons)), 'updated_at' => now()]) > 0;
    }

    /** Run-DTO change: runs.updated_at and the record revision move together (10.6). */
    private function touchAndBump(IguideLabTourRun $run): void
    {
        IguideLabTourRun::whereKey($run->id)->toBase()->update(['updated_at' => now()]);
        LabTourRevisions::bump($run->analysis_id);
    }

    private function workflowFor(string $projectId, string $requestId): ?IguideLabWorkflow
    {
        return IguideLabWorkflow::where('project_id', $projectId)->where('request_id', $requestId)->first();
    }

    /** Bookkeeping clear of the monitor chain (9.8): the next new chain proceeds at once. */
    private function stopMonitor(string $runId): void
    {
        LockedWrite::run(fn () => IguideLabTourRun::whereKey($runId)->toBase()->update(['monitor_heartbeat_at' => null, 'monitor_token' => null]), 'iguide-lab.tour-run-monitor-stop');
    }

    /** A lab write transaction (spec conventions); warnings decided inside it are logged after it. */
    private function write(string $projectId, string $context, \Closure $decide): mixed
    {
        try {
            return LockedWrite::run(function () use ($projectId, $decide) {
                $this->deferredLogs = [];

                return DB::transaction(function () use ($projectId, $decide) {
                    IguideLabProject::whereKey($projectId)->update(['updated_at' => now()]);

                    return $decide();
                });
            }, 'iguide-lab.tour-'.$context);
        } finally {
            $logs = $this->deferredLogs;
            $this->deferredLogs = [];
            $this->log($logs);
        }
    }

    private function log(array $logs): void
    {
        foreach ($logs as [$message, $context]) Log::warning($message, $context);
    }
}
