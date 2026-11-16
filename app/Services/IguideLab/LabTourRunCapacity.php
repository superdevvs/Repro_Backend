<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use App\Models\IguideLabComposite;
use App\Models\IguideLabDeclutterRemovalPlan;
use App\Models\IguideLabGrassAssessment;
use App\Models\IguideLabGrassSourceAdmission;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Models\IguideLabSection;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunChunk;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowEvaluation;

/**
 * Preflight estimate and admission of tour runs (spec 8, D2). Rooms are admitted in tour order until the first limit.
 *
 * fit() is the shared core of the live estimate, POST runs (frozen estimate and target admission) and the chunk
 * re-check (9.4). It performs database reads only, so it may run inside a lab write transaction. The policy is never
 * read here: callers pass $facts, computed before any transaction (8.1):
 *   ['policy' => LabWorkflowPolicy snapshot | LabTourCapabilities::policyFacts(), 'runnable' => bool | array<preset,bool>,
 *    'schedulerEnabled' => bool, 'tourBudgetEnabled' => bool]
 * A missing runnable fact fails closed (preset_not_runnable); missing scheduler/budget flags fall back to the live config.
 */
class LabTourRunCapacity
{
    public const LIGHTING = ['golden-hour', 'twilight'];
    public const KINDS = ['image', 'analysis', 'evaluation', 'composite', 'section', 'grass_assessment', 'grass_admission', 'removal_plan', 'case'];
    /** Per-project lifetime and lab-wide rolling-day record ceilings while the scheduler is off (LabWorkflowRecordCapacity.php:37,46-48). */
    public const RECORD_CEILING = 120;
    /** Lifetime workflows per project (LabWorkflows.php:46). */
    public const WORKFLOW_CEILING = 120;
    /** Lab-wide images per rolling 24 h (LabService.php:182). */
    public const DAILY_IMAGES = 120;
    /** Target states that still reserve a full worst case (8.3 "outstanding"). */
    public const OUTSTANDING_STATES = ['waiting', 'queued', 'processing'];
    public const ACTIVE_RUN_STATES = ['creating', 'running'];

    /**
     * Exactly the 8 estimateSchema keys (tourContract.ts:246-264). Reads the selection and the captures from the loaded
     * view and never reloads them; $fit reuses a computed fit() (POST runs).
     */
    public function estimate(LabTourView $view, string $preset, array $facts, ?string $excludeRunId = null, ?array $fit = null): array
    {
        // Only counts leave this method; every limit costs the same per room, so the order of $rooms cannot change them.
        $effective = app(LabTourSelections::class)->effective($view, $preset);
        $rooms = [];
        $items = 0;
        foreach ($effective as $caseId => $ids) {
            $ids = array_values((array) $ids);
            if ($ids === []) continue;
            $rooms[] = strtolower((string) $caseId);
            $items += count($ids);
        }
        $selected = count($rooms);
        if ($fit === null) {
            $fit = match (true) {
                $selected === 0 => [[], null],
                LabTourConfig::runsPaused() => [[], 'runs_paused'],
                $this->otherActiveRun($view, $preset, $excludeRunId) => [[], 'active_run'],
                default => $this->fit(IguideLabProject::findOrFail($view->analysis->project_id), $preset, $rooms, $facts, $excludeRunId),
            };
        }
        $fits = min($selected, count($fit[0]));
        $reason = $fits === $selected ? null : ($fit[1] ?? throw new \LogicException('A partial fit always names its limit.'));
        $policy = $this->policyOf($facts);
        $edit = LabTourConfig::editMinutesPerRoom();

        return [
            'selectionRevision' => (int) ($view->analysis->selections[$preset]['revision'] ?? 0),
            'roomsSelected' => $selected,
            'itemsSelected' => $items,
            'roomsThatFit' => $fits,
            'worstCaseImages' => min(1000, $fits * $this->worstCase($preset, $policy)['image']),
            'limitingReason' => $reason,
            'estimatedMinutes' => ['min' => min(10000, $fits * $edit['min']), 'max' => min(10000, $fits * $edit['max'])],
            'computedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * Global gates 1 and 3-6 (8.4), then limits L1-L15 per room in this order (8.3). Gate 2 (another active run of this
     * preset in the analysis) needs the analysis and is applied by estimate(); POST runs refuses it with 409 first.
     *
     * @return array{0:list<string>,1:?string} [admittedCaseIds, limitingReason]
     */
    public function fit(IguideLabProject $project, string $preset, array $orderedCaseIds, array $facts, ?string $excludeRunId): array
    {
        $rooms = array_values(array_unique(array_map(fn ($id) => strtolower((string) $id), $orderedCaseIds)));
        $n = count($rooms);
        if ($n === 0) return [[], null];
        if ($gate = $this->gate($project, $preset, $facts)) return [[], $gate];

        $policy = $this->policyOf($facts);
        $w = $this->worstCase($preset, $policy);
        $own = $excludeRunId ? IguideLabTourRun::where('project_id', $project->id)->find($excludeRunId) : null;
        $chunk = max(1, (int) ($own?->chunk_size ?: LabTourConfig::chunkSize()));
        $existingWorkflows = $own ? IguideLabTourRunChunk::where('run_id', $own->id)->whereNotNull('workflow_id')->count() : 0;
        $out = $this->outstanding($project, $excludeRunId, $policy);
        $workflows = fn (int $k) => intdiv($k + $chunk - 1, $chunk);
        $day = now()->subDay();

        /** @var list<array{0:string,1:int,2:callable(int):int}> $limits in the order of 8.3 */
        $limits = [
            ['workflow_ceiling', self::WORKFLOW_CEILING - IguideLabWorkflow::where('project_id', $project->id)->count() - $out['workflows'], $workflows],
            // Workflows already bound to this run count as well, so a run never lists more than maxWorkflowsPerRun (contract max 10).
            ['run_workflow_limit', LabTourConfig::maxWorkflowsPerRun() - $existingWorkflows, $workflows],
        ];
        $perRoom = fn (string $kind) => fn (int $k) => $k * $w[$kind];
        $record = function (string $kind, string $model, ?string $projectCode, ?string $dailyCode) use (&$limits, $w, $project, $out, $perRoom, $day): void {
            if ($w[$kind] === 0) return;
            if ($projectCode) $limits[] = [$projectCode, self::RECORD_CEILING - $model::where('project_id', $project->id)->count() - $out[$kind], $perRoom($kind)];
            if ($dailyCode) $limits[] = [$dailyCode, self::RECORD_CEILING - $model::where('created_at', '>=', $day)->count() - $out[$kind], $perRoom($kind)];
        };
        if ($w['image'] > 0) {
            $limits[] = ['image_allowance', (int) $project->max_runs - (int) $project->used_runs - $out['image'], $perRoom('image')];
            $limits[] = ['daily_image_limit', self::DAILY_IMAGES - IguideLabRun::where('created_at', '>=', $day)->count() - $out['image'], $perRoom('image')];
        }
        $record('analysis', IguideLabAnalysis::class, 'analysis_ceiling', 'daily_analysis_limit');
        $record('evaluation', IguideLabWorkflowEvaluation::class, 'evaluation_ceiling', 'daily_evaluation_limit');
        $record('composite', IguideLabComposite::class, 'composite_ceiling', null);
        $record('section', IguideLabSection::class, 'section_ceiling', null);
        if ($w['case'] > 0) {
            $maxCases = is_int($project->max_cases) ? $project->max_cases : LabCaseCapacity::DEFAULT_LIMIT;
            $limits[] = ['case_ceiling', $maxCases - IguideLabCase::where('project_id', $project->id)->count() - $out['case'], $perRoom('case')];
        }
        $record('grass_assessment', IguideLabGrassAssessment::class, 'grass_assessment_ceiling', 'daily_grass_assessment_limit');
        $record('grass_admission', IguideLabGrassSourceAdmission::class, 'grass_admission_ceiling', 'daily_grass_admission_limit');
        $record('removal_plan', IguideLabDeclutterRemovalPlan::class, 'removal_plan_ceiling', 'daily_removal_plan_limit');
        // L15: now + (backlog + k) x edit.max minutes + ceil(k / chunk) x monitor interval <= expires_at (in seconds).
        $editSeconds = LabTourConfig::editMinutesPerRoom()['max'] * 60;
        $limits[] = ['project_expiry', $project->expires_at->getTimestamp() - now()->getTimestamp() - $out['rooms'] * $editSeconds,
            fn (int $k) => $k * $editSeconds + $workflows($k) * LabTourConfig::monitorInterval()];

        for ($k = 1; $k <= $n; $k++) {
            foreach ($limits as [$code, $remaining, $need]) {
                if ($need($k) > $remaining) return [array_slice($rooms, 0, $k - 1), $code];
            }
        }

        return [$rooms, null];
    }

    /**
     * Route-actual worst case per room and record kind (8.2). $policy is a LabWorkflowPolicy snapshot, or the cached
     * policy facts of 8.1 ({policyKeys, limits}). Images follow LabWorkflowSteps::limits() exactly.
     *
     * @return array<string,int>
     */
    public function worstCase(string $preset, array $policy): array
    {
        $factsForm = is_array($policy['policyKeys'] ?? null);
        $has = fn (string $key): bool => $factsForm ? (($policy['policyKeys'][$key] ?? false) === true) : isset($policy[$key]);
        $image = $factsForm ? ($policy['limits'][$preset]['image'] ?? null) : null;
        if (! $factsForm && in_array($preset, ['golden-hour', 'twilight', 'grass', 'declutter'], true)) {
            $image = app(LabWorkflowSteps::class)->limits($policy, $preset)['image'];
        }
        $w = array_fill_keys(self::KINDS, 0);
        switch ($preset) {
            case 'golden-hour':
            case 'twilight':
                // LabWorkflows.php:140-222 creates no section; lighting repair caps composites and evaluations at 5 (LabWorkflowSteps.php:18).
                $repair = $has('lightingRepair');
                return array_replace($w, ['image' => (int) ($image ?? 3), 'analysis' => 2, 'evaluation' => $repair ? 5 : 8, 'composite' => $repair ? 5 : 8]);
            case 'grass':
                return array_replace($w, ['image' => (int) ($image ?? 1), 'analysis' => 1, 'evaluation' => 1, 'composite' => 1, 'grass_assessment' => 1,
                    'grass_admission' => $has('grassSourceAdmission') ? 2 : 0]);
            case 'declutter':
                return array_replace($w, ['image' => (int) ($image ?? 1), 'analysis' => 1, 'evaluation' => 1, 'composite' => 1, 'section' => 1,
                    'removal_plan' => 1, 'case' => 1]);
            default:
                throw new \InvalidArgumentException('Unknown tour preset.');
        }
    }

    /**
     * D1 runs lighting presets only (D1). Grass and declutter need their run flags, which LabTourConfig hard-wires to
     * false until D3/D5 replace the accessors together with their drivers.
     */
    public static function presetRunsEnabled(string $preset): bool
    {
        return match ($preset) {
            'golden-hour', 'twilight' => true,
            'grass' => LabTourConfig::grassRunsEnabled(),
            'declutter' => LabTourConfig::declutterRunsEnabled(),
            default => false,
        };
    }

    /** Global gates 1 and 3-6 of 8.4, in order. */
    private function gate(IguideLabProject $project, string $preset, array $facts): ?string
    {
        if (LabTourConfig::runsPaused()) return 'runs_paused';
        if (! static::presetRunsEnabled($preset) || ! $this->runnableFact($facts, $preset)) return 'preset_not_runnable';
        if ($this->flagFact($facts, 'schedulerEnabled', fn () => LabWorkflowScheduler::enabled())) return 'scheduler_mode_unsupported';
        if ($this->flagFact($facts, 'tourBudgetEnabled', fn () => LabTourProcessingBudget::enabled())) return 'tour_budget_required';
        if (! $project->active()) return 'project_expiry';

        return null;
    }

    private function runnableFact(array $facts, string $preset): bool
    {
        $runnable = $facts['runnable'] ?? false;

        return is_array($runnable) ? (($runnable[$preset] ?? false) === true) : $runnable === true;
    }

    private function flagFact(array $facts, string $key, \Closure $live): bool
    {
        $value = $facts[$key] ?? ($this->policyOf($facts)[$key] ?? null);

        return $value === null ? $live() === true : $value === true;
    }

    private function policyOf(array $facts): array
    {
        return is_array($facts['policy'] ?? null) ? $facts['policy'] : $facts;
    }

    private function otherActiveRun(LabTourView $view, string $preset, ?string $excludeRunId): bool
    {
        return $view->runs->contains(fn (IguideLabTourRun $run) => $run->preset === $preset && $run->id !== $excludeRunId
            && in_array($run->status, self::ACTIVE_RUN_STATES, true));
    }

    /**
     * Reservations of the project's other tour runs (8.3): every waiting, queued or processing target of an active run,
     * and every bound non-terminal target of a cancelled or failed run, reserves its run's full worst case.
     *
     * @return array<string,int> per record kind, plus 'rooms' (backlog) and 'workflows' (still to create)
     */
    private function outstanding(IguideLabProject $project, ?string $excludeRunId, array $policy): array
    {
        $out = array_fill_keys([...self::KINDS, 'rooms', 'workflows'], 0);
        $runs = IguideLabTourRun::where('project_id', $project->id)->whereIn('status', [...self::ACTIVE_RUN_STATES, 'cancelled', 'failed'])
            ->when($excludeRunId !== null, fn ($q) => $q->whereKeyNot($excludeRunId))->get(['id', 'preset', 'status', 'chunk_size'])->keyBy('id');
        if ($runs->isEmpty()) return $out;
        $rows = IguideLabTourRunTarget::where('project_id', $project->id)->whereIn('run_id', $runs->keys()->all())->whereIn('state', self::OUTSTANDING_STATES)
            ->selectRaw('run_id, state, count(*) as aggregate')->groupBy('run_id', 'state')->get();
        foreach ($rows as $row) {
            $run = $runs[$row->run_id];
            $active = in_array($run->status, self::ACTIVE_RUN_STATES, true);
            if (! $active && $row->state === 'waiting') continue; // only bound rooms of a cancelled or failed run still run
            $count = (int) $row->aggregate;
            $out['rooms'] += $count;
            foreach ($this->worstCase($run->preset, $policy) as $kind => $perRoom) $out[$kind] += $count * $perRoom;
            if ($active && $row->state === 'waiting') {
                $chunk = max(1, (int) $run->chunk_size);
                $out['workflows'] += intdiv($count + $chunk - 1, $chunk);
            }
        }

        return $out;
    }
}
