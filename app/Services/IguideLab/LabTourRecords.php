<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRun;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Record, summary, progress and run DTOs of the tour layer (spec 10.7), each built from one read snapshot.
 *
 * Everything that may write runs first: the GET kick of stale monitors (9.8, the only GET writes, D4) and the policy
 * facts and runnability of the live estimate (10.5, cache writes). Then one LabTourView is loaded inside one
 * transaction and every part of the response is derived from it, so a monitor commit between two queries can never
 * mix an old status with new counts (tourContract.ts:358-366, 464, 491, 494, 551).
 *
 * The WP3/WP4 parts are resolved by class name (spec 14.1): LabTourAnalyses (counts, captures, analysis kick),
 * LabTourSelections (selection state), LabTourRunCapacity (estimate) and LabTourRuns (run DTO, activeRunId, run kick).
 */
class LabTourRecords
{
    /** tourContract.ts TOUR_LIMITS.maxRunsPerAnalysis: the record lists at most this many runs. */
    public const MAX_RUNS = 40;
    public const MAX_SUMMARIES = 10;
    /** MINUTES maximum (tourContract.ts:83). */
    public const MAX_MINUTES = 10000;
    private const ACTIVE_RUN_STATES = ['creating', 'running'];

    /**
     * tourAnalysisSchema: kick and facts first, then one read snapshot (10.7). $kick is false for the POST create and
     * cancel answers, which have no side effects beyond their own write (E2 replay, 9.6).
     */
    public function dto(IguideLabTourAnalysis $analysis, bool $kick = true): array
    {
        if ($kick) $this->kickStaleMonitors($analysis);
        $facts = $this->facts();

        return DB::transaction(fn () => $this->fromView(LabTourView::load($analysis->project_id, $analysis->id), $facts));
    }

    /** Up to 10 tourAnalysisSummarySchema of the project, newest first, from one read snapshot. */
    public function summaryDtos(IguideLabProject $project): array
    {
        return DB::transaction(function () use ($project): array {
            $analyses = IguideLabTourAnalysis::where('project_id', $project->id)->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_SUMMARIES)->get();
            if ($analyses->isEmpty()) return [];
            $statuses = [];
            foreach (IguideLabTourCapture::where('project_id', $project->id)->whereIn('analysis_id', $analyses->modelKeys())
                ->selectRaw('analysis_id, status, count(*) as aggregate')->groupBy('analysis_id', 'status')->toBase()->get() as $row) {
                $statuses[$row->analysis_id][$row->status] = (int) $row->aggregate;
            }

            return array_values($analyses->map(fn (IguideLabTourAnalysis $a) => [
                'id' => $a->id,
                'importId' => $a->import_id,
                'status' => $a->status,
                'recordRevision' => (int) $a->record_revision,
                'counts' => self::counts($statuses[$a->id] ?? []),
                'createdAt' => $a->created_at->toIso8601String(),
                'updatedAt' => $a->updated_at->toIso8601String(),
            ])->all());
        });
    }

    /** tourProgressSchema (< 2 KiB): kick first, then one read snapshot. */
    public function progressDto(IguideLabTourAnalysis $analysis): array
    {
        $this->kickStaleMonitors($analysis);

        return DB::transaction(fn () => $this->progressFromView(LabTourView::load($analysis->project_id, $analysis->id)));
    }

    /** tourRunSchema of one run of this analysis, from one read snapshot (the POST runs answer, 9.1). */
    public function runDto(IguideLabTourAnalysis $analysis, string $runId): array
    {
        return DB::transaction(function () use ($analysis, $runId): array {
            $view = LabTourView::load($analysis->project_id, $analysis->id);
            $run = $view->runs->firstWhere('id', strtolower($runId)) ?? throw (new ModelNotFoundException)->setModel(IguideLabTourRun::class, [$runId]);

            return app(LabTourRuns::class)->dto($view, $run);
        });
    }

    /**
     * The GET exception (9.8): asks LabTourAnalyses and LabTourRuns to push a new monitor chain where one is stale.
     * Their kickIfStale() holds the four 9.8 conditions; this only picks the candidates. Runs before any snapshot,
     * at the caller's transaction level, and never fails the GET.
     */
    public function kickStaleMonitors(IguideLabTourAnalysis $analysis): void
    {
        if (! LabTourConfig::analysisEnabled()) return;
        try {
            $analyses = app(LabTourAnalyses::class);
            if ($analyses->needsMonitor($analysis)) $analyses->kickIfStale($analysis);
        } catch (\Throwable $e) {
            $this->kickFailed($analysis, null, $e);
        }
        try {
            // A completed run never needs a monitor; cancelled and failed runs may still sync bound rooms (9.2 step 7).
            $runs = IguideLabTourRun::where('project_id', $analysis->project_id)->where('analysis_id', $analysis->id)
                ->whereIn('status', ['creating', 'running', 'cancelled', 'failed'])->orderBy('created_at')->get();
        } catch (\Throwable $e) {
            $this->kickFailed($analysis, null, $e);

            return;
        }
        $service = app(LabTourRuns::class);
        foreach ($runs as $run) {
            try {
                if ($service->needsMonitor($run, true)) $service->kickIfStale($run);
            } catch (\Throwable $e) {
                $this->kickFailed($analysis, $run->id, $e);
            }
        }
    }

    /**
     * The live-estimate facts of every preset (8, 8.1), computed before a snapshot opens. Public so a WP3 write path can
     * build its record DTO inside its own transaction with fromView().
     *
     * @return array<string, array{policy:array,runnable:bool,schedulerEnabled:bool,tourBudgetEnabled:bool}>
     */
    public function facts(): array
    {
        $capabilities = app(LabTourCapabilities::class);
        $policyFacts = $capabilities->policyFacts();
        $facts = [];
        foreach (LabTourCapabilities::PRESETS as $preset) $facts[$preset] = $capabilities->liveFacts($preset, $policyFacts);

        return $facts;
    }

    /**
     * tourAnalysisSchema from one loaded view. Database reads only (the WP3/WP4 parts read the view); $facts comes from
     * facts(), computed before the transaction that loaded $view.
     */
    public function fromView(LabTourView $view, array $facts): array
    {
        $a = $view->analysis;
        $analyses = app(LabTourAnalyses::class);
        $selections = app(LabTourSelections::class);
        $capacity = app(LabTourRunCapacity::class);
        $runs = app(LabTourRuns::class);
        // Estimates exist only once the analysis has finished (tourContract.ts:490).
        $settled = in_array($a->status, ['ready', 'cancelled'], true);
        $presets = [];
        foreach (LabTourCapabilities::PRESETS as $preset) {
            $presets[$preset] = [
                'selection' => $selections->stateDto($view, $preset),
                'estimate' => $settled ? $capacity->estimate($view, $preset, $facts[$preset]) : null,
                'activeRunId' => $runs->activeRunId($view, $preset),
            ];
        }

        return [
            'version' => LabTourCapabilities::VERSION,
            'id' => $a->id,
            'requestId' => $a->request_id,
            'importId' => $a->import_id,
            'status' => $a->status,
            'recordRevision' => (int) $a->record_revision,
            'surveyVersion' => LabTourCapabilities::SURVEY_VERSION,
            'defaultsVersion' => LabTourCapabilities::DEFAULTS_VERSION,
            'counts' => $analyses->countsDto($view),
            'captures' => array_values($analyses->capturesDto($view)),
            'presets' => $presets,
            // The view holds runs newest first (created_at desc, id desc).
            'runs' => array_values($view->runs->take(self::MAX_RUNS)->map(fn (IguideLabTourRun $run) => $runs->dto($view, $run))->all()),
            'createdAt' => $a->created_at->toIso8601String(),
            'updatedAt' => $a->updated_at->toIso8601String(),
            'completedAt' => $a->completed_at?->toIso8601String(),
        ];
    }

    /** tourProgressSchema from one loaded view (10.7 progress rows). */
    public function progressFromView(LabTourView $view): array
    {
        $a = $view->analysis;
        $counts = app(LabTourAnalyses::class)->countsDto($view);
        $eta = null;
        if (in_array($a->status, ['queued', 'analysing'], true)) {
            $minutes = LabTourConfig::surveyMinutesPerCapture();
            $workers = LabTourConfig::workers();
            $pending = (int) $counts['pending'];
            $eta = ['min' => min(self::MAX_MINUTES, (int) ceil($pending * $minutes['min'] / $workers)),
                'max' => min(self::MAX_MINUTES, (int) ceil($pending * $minutes['max'] / $workers))];
        }
        $edit = LabTourConfig::editMinutesPerRoom();
        $runs = [];
        foreach ($view->runs as $run) {
            // Active runs only, the newest per preset (at most one per preset, so at most four).
            if (! in_array($run->status, self::ACTIVE_RUN_STATES, true) || isset($runs[$run->preset])) continue;
            $admitted = $run->targets->where('state', '!=', 'not_admitted');
            $rooms = $admitted->count();
            $done = $admitted->whereIn('state', ['ready', 'held', 'failed', 'cancelled'])->count();
            $runs[$run->preset] = [
                'id' => $run->id,
                'preset' => $run->preset,
                'status' => $run->status,
                'rooms' => $rooms,
                'done' => $done,
                'ready' => $admitted->where('state', 'ready')->count(),
                'held' => $admitted->where('state', 'held')->count(),
                'failed' => $admitted->where('state', 'failed')->count(),
                'etaMinutes' => ['min' => min(self::MAX_MINUTES, ($rooms - $done) * $edit['min']), 'max' => min(self::MAX_MINUTES, ($rooms - $done) * $edit['max'])],
            ];
        }

        return [
            'id' => $a->id,
            'status' => $a->status,
            'recordRevision' => (int) $a->record_revision,
            'counts' => $counts,
            'etaMinutes' => $eta,
            'runs' => array_values($runs),
            'updatedAt' => $a->updated_at->toIso8601String(),
        ];
    }

    /**
     * analysisCounts from capture status counts (pending includes surveying, tourContract.ts:464).
     *
     * @param array<string,int> $byStatus
     * @return array{total:int,surveyed:int,failed:int,skipped:int,pending:int}
     */
    private static function counts(array $byStatus): array
    {
        return ['total' => array_sum($byStatus), 'surveyed' => $byStatus['surveyed'] ?? 0, 'failed' => $byStatus['failed'] ?? 0,
            'skipped' => $byStatus['skipped'] ?? 0, 'pending' => ($byStatus['pending'] ?? 0) + ($byStatus['surveying'] ?? 0)];
    }

    private function kickFailed(IguideLabTourAnalysis $analysis, ?string $runId, \Throwable $e): void
    {
        // Only the exception class: a message can carry SQL or provider detail (ApiErrorResponder::diagnosticContext).
        Log::warning('Tour monitor kick failed; the GET answers without it.', array_filter(['analysisId' => $analysis->id, 'runId' => $runId,
            'exception' => $e::class]));
    }
}
