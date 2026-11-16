<?php

namespace Tests\Support;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunTarget;
use App\Services\IguideLab\LabTourAcceptances;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourRunCapacity;
use App\Services\IguideLab\LabTourRuns;
use App\Services\IguideLab\LabTourSelections;
use App\Services\IguideLab\LabTourSurveys;
use App\Services\IguideLab\LabTourView;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Stand-ins for the WP2-WP4 services that the WP5 code calls through their spec 14.1 signatures (spec 14: "until the
 * owning package merges, a caller binds a test double with $this->app->instance(...)"). Each is an anonymous object
 * bound under the real class name, so it works whether or not the real class exists yet.
 *
 * The DTO parts follow the spec 10.7 field table and read only the LabTourView they are given (the selection sha
 * follows 7.4), so the WP5 assembly, its single read snapshot and the contract mirror can be tested before WP3/WP4
 * land. They are not the WP3/WP4 implementations: estimate() admits every selected room when the preset is
 * runnable, and the write paths (create, write, cancel, runs create, accept) only record their input unless a test
 * sets a closure.
 *
 * Every call is logged in $calls as [method, args, DB::transactionLevel()].
 */
final class TourLayerDoubles
{
    /** @var list<array{0:string,1:array,2:int}> */
    public array $calls = [];
    public int $attemptsUsedByProject = 0;
    public int $attemptsUsedToday = 0;
    public bool $sweepResult = false;
    public ?\Throwable $sweepFailure = null;
    public ?\Throwable $kickFailure = null;
    /** fn (IguideLabProject $project, array $input, ?int $limit): array{0:IguideLabTourAnalysis,1:bool} */
    public ?Closure $create = null;
    /** fn (IguideLabTourAnalysis $analysis): IguideLabTourAnalysis */
    public ?Closure $cancel = null;
    /** fn (IguideLabTourAnalysis $analysis, array $input, array $facts): array */
    public ?Closure $write = null;
    /** fn (IguideLabTourAnalysis $analysis, array $input): array{0:IguideLabTourRun,1:bool} */
    public ?Closure $runCreate = null;
    /** fn (IguideLabTourAnalysis $analysis, array $input): array */
    public ?Closure $accept = null;

    public static function install(): self
    {
        $d = new self;
        app()->instance(LabTourSurveys::class, new class($d)
        {
            public function __construct(private TourLayerDoubles $d) {}
            public function attemptsUsedByProject(string $projectId): int { $this->d->log(__FUNCTION__, [$projectId]); return $this->d->attemptsUsedByProject; }
            public function attemptsUsedToday(): int { $this->d->log(__FUNCTION__, []); return $this->d->attemptsUsedToday; }
            public function sweep(): bool
            {
                $this->d->log(__FUNCTION__, []);
                if ($this->d->sweepFailure) throw $this->d->sweepFailure;

                return $this->d->sweepResult;
            }
        });
        app()->instance(LabTourAnalyses::class, new class($d)
        {
            public function __construct(private TourLayerDoubles $d) {}
            public function create(IguideLabProject $project, array $input, ?int $operatorLimit = null): array
            {
                $this->d->log(__FUNCTION__, [$project->id, $input, $operatorLimit]);

                return ($this->d->create ?? fn () => throw new \LogicException('No create double.'))($project, $input, $operatorLimit);
            }
            public function cancel(IguideLabTourAnalysis $analysis): IguideLabTourAnalysis
            {
                $this->d->log(__FUNCTION__, [$analysis->id]);

                return $this->d->cancel ? ($this->d->cancel)($analysis) : $analysis->fresh();
            }
            public function kickIfStale(IguideLabTourAnalysis $analysis): void
            {
                $this->d->log(__FUNCTION__, [$analysis->id]);
                if ($this->d->kickFailure) throw $this->d->kickFailure;
            }
            public function needsMonitor(IguideLabTourAnalysis $analysis): bool
            {
                $this->d->log(__FUNCTION__, [$analysis->id]);

                return in_array($analysis->status, ['queued', 'analysing'], true) && ! $analysis->monitor_idle;
            }
            public function countsDto(LabTourView $view): array { $this->d->log(__FUNCTION__, [$view->analysis->id]); return $this->d->countsDto($view); }
            public function capturesDto(LabTourView $view): array { $this->d->log(__FUNCTION__, [$view->analysis->id]); return $this->d->capturesDto($view); }
        });
        app()->instance(LabTourSelections::class, new class($d)
        {
            public function __construct(private TourLayerDoubles $d) {}
            public function write(IguideLabTourAnalysis $analysis, array $input, array $facts): array
            {
                $this->d->log(__FUNCTION__, [$analysis->id, $input, $facts]);
                if ($this->d->write) return ($this->d->write)($analysis, $input, $facts);
                $view = LabTourView::load($analysis->project_id, $analysis->id);
                $state = $this->d->stateDto($view, $input['preset']);
                [$rooms, $items] = $this->d->selectionCounts($this->d->effective($view, $input['preset']));

                return ['analysisId' => $analysis->id, 'preset' => $input['preset'], 'selection' => $state, 'counts' => ['rooms' => $rooms, 'items' => $items],
                    'estimate' => in_array($view->analysis->status, ['ready', 'cancelled'], true) ? $this->d->estimate($view, $input['preset'], $facts) : null];
            }
            public function effective(LabTourView $view, string $preset): array { $this->d->log(__FUNCTION__, [$preset]); return $this->d->effective($view, $preset); }
            public static function canonical(string $preset, array $effective): string { return TourLayerDoubles::canonical($preset, $effective); }
            public static function sha256(string $preset, array $effective): string { return hash('sha256', TourLayerDoubles::canonical($preset, $effective)); }
            public function stateDto(LabTourView $view, string $preset): array { $this->d->log(__FUNCTION__, [$preset]); return $this->d->stateDto($view, $preset); }
        });
        app()->instance(LabTourRunCapacity::class, new class($d)
        {
            public function __construct(private TourLayerDoubles $d) {}
            public function estimate(LabTourView $view, string $preset, array $facts, ?string $excludeRunId = null, ?array $fit = null): array
            {
                $this->d->log(__FUNCTION__, [$preset, $facts, $excludeRunId]);

                return $this->d->estimate($view, $preset, $facts);
            }
        });
        app()->instance(LabTourRuns::class, new class($d)
        {
            public function __construct(private TourLayerDoubles $d) {}
            public function create(IguideLabTourAnalysis $analysis, array $input): array
            {
                $this->d->log(__FUNCTION__, [$analysis->id, $input]);

                return ($this->d->runCreate ?? fn () => throw new \LogicException('No run create double.'))($analysis, $input);
            }
            public function kickIfStale(IguideLabTourRun $run): void
            {
                $this->d->log(__FUNCTION__, [$run->id]);
                if ($this->d->kickFailure) throw $this->d->kickFailure;
            }
            public function needsMonitor(IguideLabTourRun $run, bool $forGet): bool { $this->d->log(__FUNCTION__, [$run->id, $forGet]); return $this->d->needsRunMonitor($run); }
            public function dto(LabTourView $view, IguideLabTourRun $run): array { $this->d->log(__FUNCTION__, [$run->id]); return $this->d->runDto($run); }
            public function activeRunId(LabTourView $view, string $preset): ?string { $this->d->log(__FUNCTION__, [$preset]); return $this->d->activeRunId($view, $preset); }
        });
        app()->instance(LabTourAcceptances::class, new class($d)
        {
            public function __construct(private TourLayerDoubles $d) {}
            public function accept(IguideLabTourAnalysis $analysis, array $input): array
            {
                $this->d->log(__FUNCTION__, [$analysis->id, $input]);
                if ($this->d->accept) return ($this->d->accept)($analysis, $input);
                $run = IguideLabTourRun::where('project_id', $analysis->project_id)->where('analysis_id', $analysis->id)->with(['targets', 'chunks'])->findOrFail($input['runId']);

                return $this->d->runDto($run);
            }
        });

        return $d;
    }

    /** Until WP3/WP4 land, the monitor job names resolve to stand-ins with the 9.8 constructors; the real jobs win once they exist. */
    public static function aliasMissingMonitorJobs(): void
    {
        foreach (['App\Jobs\MonitorIguideLabTourAnalysis' => TourAnalysisMonitorStub::class, 'App\Jobs\MonitorIguideLabTourRun' => TourRunMonitorStub::class] as $job => $stub) {
            if (! class_exists($job)) class_alias($stub, $job);
        }
    }

    public function log(string $method, array $args): void
    {
        $this->calls[] = [$method, $args, DB::transactionLevel()];
    }

    /** @return list<array{0:string,1:array,2:int}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call[0] === $method));
    }

    // ------------------------------------------------------------------ 10.7 parts from the loaded view

    public function countsDto(LabTourView $view): array
    {
        $by = fn (string ...$states) => $view->captures->whereIn('status', $states)->count();

        return ['total' => $view->captures->count(), 'surveyed' => $by('surveyed'), 'failed' => $by('failed'), 'skipped' => $by('skipped'),
            'pending' => $by('pending', 'surveying')];
    }

    public function capturesDto(LabTourView $view): array
    {
        return $view->captures->map(function (IguideLabTourCapture $c) {
            $surveyed = $c->status === 'surveyed';

            return ['caseId' => $c->case_id, 'order' => (int) $c->position, 'roomName' => $c->room_name, 'floorName' => $c->floor_name, 'status' => $c->status,
                'error' => in_array($c->status, ['failed', 'skipped'], true) ? $c->error_code : null, 'survey' => $surveyed ? $c->survey : null,
                'applicability' => $surveyed ? $c->applicability : null, 'candidates' => $surveyed ? array_values($c->candidates ?? []) : []];
        })->values()->all();
    }

    /** @return array<string, list<string>> */
    public function effective(LabTourView $view, string $preset): array
    {
        $changes = $view->analysis->selections[$preset]['changes'] ?? [];
        $out = [];
        foreach ($view->captures as $c) {
            if ($c->status !== 'surveyed') continue;
            $set = array_values(array_map(fn ($x) => $x['id'], array_filter($c->candidates ?? [],
                fn ($x) => $x['preset'] === $preset && $x['executable'] && $x['defaultSelected'])));
            if ($entry = $changes[strtolower($c->case_id)] ?? null) {
                $set = array_values(array_diff($set, $entry['remove']));
                foreach ($entry['add'] as $id) if (! in_array($id, $set, true)) $set[] = $id;
            }
            if ($set) $out[strtolower($c->case_id)] = $set;
        }

        return $out;
    }

    public static function canonical(string $preset, array $effective): string
    {
        $rows = [];
        foreach ($effective as $caseId => $ids) {
            if (! $ids) continue;
            $ids = array_values($ids);
            sort($ids, SORT_STRING);
            $rows[strtolower($caseId)] = implode(',', $ids);
        }
        ksort($rows, SORT_STRING);
        $lines = ['tour-selection/1', 'preset='.$preset];
        foreach ($rows as $caseId => $ids) $lines[] = $caseId.'='.$ids;

        return implode("\n", $lines);
    }

    public function stateDto(LabTourView $view, string $preset): array
    {
        $state = $view->analysis->selections[$preset] ?? ['revision' => 0, 'changes' => []];
        $changes = [];
        foreach ($view->captures as $c) {
            if ($entry = $state['changes'][strtolower($c->case_id)] ?? null) {
                $changes[] = ['caseId' => strtolower($c->case_id), 'add' => array_values($entry['add']), 'remove' => array_values($entry['remove'])];
            }
        }

        return ['revision' => (int) $state['revision'], 'sha256' => hash('sha256', self::canonical($preset, $this->effective($view, $preset))), 'changes' => $changes];
    }

    /** @return array{0:int,1:int} [rooms, items] */
    public function selectionCounts(array $effective): array
    {
        return [count(array_filter($effective)), array_sum(array_map('count', $effective))];
    }

    /** Admits every selected room when the preset is runnable, none otherwise (enough for the WP5 assembly). */
    public function estimate(LabTourView $view, string $preset, array $facts): array
    {
        [$rooms, $items] = $this->selectionCounts($this->effective($view, $preset));
        $fit = ($facts['runnable'] ?? false) === true ? $rooms : 0;
        $edit = LabTourConfig::editMinutesPerRoom();

        return ['selectionRevision' => (int) ($view->analysis->selections[$preset]['revision'] ?? 0), 'roomsSelected' => $rooms, 'itemsSelected' => $items,
            'roomsThatFit' => $fit, 'worstCaseImages' => $fit * 3, 'limitingReason' => $fit === $rooms ? null : 'preset_not_runnable',
            'estimatedMinutes' => ['min' => min(10000, $fit * $edit['min']), 'max' => min(10000, $fit * $edit['max'])], 'computedAt' => now()->toIso8601String()];
    }

    public function activeRunId(LabTourView $view, string $preset): ?string
    {
        return $view->runs->first(fn (IguideLabTourRun $run) => $run->preset === $preset && in_array($run->status, ['creating', 'running'], true))?->id;
    }

    public function needsRunMonitor(IguideLabTourRun $run): bool
    {
        if (in_array($run->status, ['creating', 'running'], true)) return true;

        return in_array($run->status, ['cancelled', 'failed'], true) && IguideLabTourRunTarget::where('run_id', $run->id)
            ->whereNotNull('workflow_item_id')->whereIn('state', ['queued', 'processing'])->exists();
    }

    public function runDto(IguideLabTourRun $run): array
    {
        $targets = $run->targets;
        $n = fn (string ...$states) => $targets->whereIn('state', $states)->count();

        return ['id' => $run->id, 'requestId' => $run->request_id, 'analysisId' => $run->analysis_id, 'preset' => $run->preset, 'status' => $run->status,
            'selectionRevision' => (int) $run->selection_revision, 'selectionSha256' => $run->selection_sha256, 'estimate' => $run->estimate,
            'counts' => ['selected' => $targets->count(), 'admitted' => $targets->count() - $n('not_admitted'), 'pending' => $n('waiting', 'queued', 'processing'),
                'ready' => $n('ready'), 'held' => $n('held'), 'failed' => $n('failed'), 'cancelled' => $n('cancelled'),
                'accepted' => $targets->whereNotNull('accepted_at')->count()],
            'workflows' => $run->chunks->whereNotNull('workflow_id')->map(fn ($chunk) => ['id' => $chunk->workflow_id, 'requestId' => $chunk->request_id,
                'caseIds' => array_values($chunk->case_ids)])->values()->all(),
            'targets' => $targets->map(fn (IguideLabTourRunTarget $t) => ['caseId' => $t->case_id, 'candidateIds' => array_values($t->candidate_ids),
                'state' => $t->state, 'workflowId' => $t->workflow_id, 'workflowItemId' => $t->workflow_item_id, 'hasAttempt' => (bool) $t->has_attempt,
                'reasons' => array_values($t->reasons), 'acceptance' => $t->accepted_at ? ['acceptedAt' => $t->accepted_at->toIso8601String()] : null])->values()->all(),
            'error' => $run->status === 'failed' ? $run->error_code : null, 'createdAt' => $run->created_at->toIso8601String(),
            'updatedAt' => $run->updated_at->toIso8601String(), 'completedAt' => $run->completed_at?->toIso8601String()];
    }
}
