<?php

namespace Tests\Feature;

use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Services\IguideLab\LabTourRunCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TourRunFixtures;
use Tests\TestCase;

/** Preflight estimate and admission (spec 8, D2): worst cases, limits L1-L15 in order, global gates, tour order. */
class IguideLabTourRunCapacityTest extends TestCase
{
    use RefreshDatabase, TourRunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTourRuns();
    }

    protected function tearDown(): void
    {
        $this->tearDownTourRuns();
        parent::tearDown();
    }

    private function capacity(): LabTourRunCapacity
    {
        return app(LabTourRunCapacity::class);
    }

    private function estimate(IguideLabTourAnalysis $analysis, string $preset = 'golden-hour', array $facts = [], ?string $exclude = null): array
    {
        return $this->capacity()->estimate($this->tourView($analysis), $preset, $facts ?: $this->facts(), $exclude);
    }

    public function test_fresh_lab_admits_24_lighting_rooms_by_evaluation_ceiling(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 30, options: ['files' => false]);

        DB::enableQueryLog();
        $estimate = DB::transaction(fn () => $this->estimate($analysis));
        $statements = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(['selectionRevision', 'roomsSelected', 'itemsSelected', 'roomsThatFit', 'worstCaseImages', 'limitingReason',
            'estimatedMinutes', 'computedAt'], array_keys($estimate), 'exactly the 8 estimateSchema keys');
        $this->assertSame([0, 30, 30, 24, 72, 'evaluation_ceiling', ['min' => 72, 'max' => 120], now()->toIso8601String()], array_values($estimate));
        foreach ($statements as $sql) $this->assertMatchesRegularExpression('/\A\s*select\b/i', $sql, 'estimate() performs database reads only');
        $this->assertSame([], $this->policy->calls, 'estimate() never reads the policy itself');

        [$admitted, $reason] = $this->capacity()->fit($this->project->fresh(), 'golden-hour', $cases, $this->facts(), null);
        $this->assertSame(array_slice($cases, 0, 24), $admitted, 'rooms are admitted in tour order');
        $this->assertSame('evaluation_ceiling', $reason);
        $this->assertSame(['image' => 3, 'analysis' => 2, 'evaluation' => 5, 'composite' => 5, 'section' => 0, 'grass_assessment' => 0,
            'grass_admission' => 0, 'removal_plan' => 0, 'case' => 0], $this->capacity()->worstCase('golden-hour', ['lightingRepair' => ['version' => 'x']]));
    }

    public function test_image_allowance_and_lab_wide_daily_images(): void
    {
        $this->project->update(['max_runs' => 30, 'used_runs' => 3]);
        [$analysis] = $this->seedAnalysis(rooms: 12, options: ['files' => false]);
        $this->assertSame([9, 'image_allowance', 27], $this->summary($this->estimate($analysis)), '(30 - 3) / 3 rooms');

        // Another project's images count lab-wide, but only inside the rolling 24 hours.
        $other = $this->makeProject();
        $this->seedRecords('iguide_lab_runs', $other, 100, now()->subHours(25));
        $this->assertSame([9, 'image_allowance', 27], $this->summary($this->estimate($analysis)));
        $this->seedRecords('iguide_lab_runs', $other, 100, now()->subHours(23));
        $this->assertSame([6, 'daily_image_limit', 18], $this->summary($this->estimate($analysis)), '(120 - 100) / 3 rooms');
    }

    public function test_lighting_ignores_section_ceiling(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 30, options: ['files' => false]);
        $this->seedRecords('iguide_lab_sections', $this->project, 120);
        $this->assertSame([24, 'evaluation_ceiling', 72], $this->summary($this->estimate($analysis)), 'D2: no lighting step reserves a section');
        $this->assertSame(0, $this->capacity()->worstCase('twilight', ['lightingRepair' => []])['section']);
        // The declutter column is implemented for D5 and does count sections (run flags forced on only in this test).
        $facts = $this->facts(['policy' => ['policyKeys' => [], 'limits' => ['declutter' => ['image' => 1]]]]);
        $this->assertSame([[], 'preset_not_runnable'], $this->capacity()->fit($this->project->fresh(), 'declutter', $cases, $facts, null), 'D1');
        $this->assertSame([[], 'section_ceiling'], $this->flagsOn()->fit($this->project->fresh(), 'declutter', $cases, $facts, null));
    }

    public function test_workflow_ceiling_counts_lifetime_workflows_and_other_runs(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 30, options: ['files' => false]);
        $this->seedRecords('iguide_lab_workflows', $this->project, 118);
        $this->assertSame([16, 'workflow_ceiling', 48], $this->summary($this->estimate($analysis)), '2 workflows of 8 rooms left, cancelled ones included');

        // Another active run's waiting rooms reserve ceil(9 / 8) = 2 more workflows.
        [$other] = $this->seedAnalysis(rooms: 9, options: ['files' => false]);
        $run = $this->seedRun($other, 'twilight', array_fill(0, 9, 'waiting'));
        $this->assertSame([0, 'workflow_ceiling', 0], $this->summary($this->estimate($analysis)));
        $this->assertSame([array_slice($cases, 0, 16), 'workflow_ceiling'], $this->capacity()->fit($this->project->fresh(), 'golden-hour', $cases, $this->facts(), $run->id),
            'the excluded run (its own chunk re-check) reserves nothing');
    }

    public function test_run_workflow_limit_for_a_small_chunk_size(): void
    {
        config(['iguide-lab-tour.runs.chunk_size' => 1]);
        [$analysis] = $this->seedAnalysis(rooms: 12, options: ['files' => false]);
        $this->assertSame([10, 'run_workflow_limit', 30], $this->summary($this->estimate($analysis)), 'at most 10 workflows per run (D3)');

        // At a chunk re-check, the workflows the run already has count too, so a run never lists more than 10.
        $run = $this->seedRun($analysis, 'golden-hour', array_fill(0, 12, 'waiting'), 'running', ['chunk_size' => 1]);
        foreach ([0, 1, 2] as $position) {
            DB::table('iguide_lab_tour_run_chunks')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'run_id' => $run->id, 'project_id' => $this->project->id,
                'position' => $position, 'request_id' => (string) \Illuminate\Support\Str::uuid(), 'case_ids' => '[]', 'status' => 'finished',
                'workflow_id' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $cases = IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->pluck('case_id')->all();
        $this->assertSame([array_slice($cases, 0, 7), 'run_workflow_limit'], $this->capacity()->fit($this->project->fresh(), 'golden-hour', $cases, $this->facts(), $run->id));
    }

    public function test_daily_analysis_and_evaluation_limits(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 12, options: ['files' => false]);
        $other = $this->makeProject();
        // Per-project lifetime ceiling: old analyses of this project count, other projects' old ones do not.
        $this->seedRecords('iguide_lab_analyses', $this->project, 112, now()->subDays(3));
        $this->seedRecords('iguide_lab_analyses', $other, 50, now()->subDays(3));
        $this->assertSame([4, 'analysis_ceiling', 12], $this->summary($this->estimate($analysis)), '(120 - 112) / 2');

        DB::table('iguide_lab_analyses')->where('project_id', $this->project->id)->delete();
        $this->seedRecords('iguide_lab_analyses', $other, 114, now()->subHours(2));
        $this->assertSame([3, 'daily_analysis_limit', 9], $this->summary($this->estimate($analysis)), '(120 - 114) / 2, all projects');

        $this->seedRecords('iguide_lab_workflow_evaluations', $other, 110, now()->subHours(2));
        $this->assertSame([2, 'daily_evaluation_limit', 6], $this->summary($this->estimate($analysis)), '(120 - 110) / 5');

        DB::table('iguide_lab_workflow_evaluations')->delete();
        $this->seedRecords('iguide_lab_workflow_evaluations', $this->project, 112, now()->subDays(3));
        $this->assertSame([1, 'evaluation_ceiling', 3], $this->summary($this->estimate($analysis)), '(120 - 112) / 5, lifetime');
    }

    public function test_project_expiry_against_the_estimated_finish(): void
    {
        $this->project->update(['expires_at' => now()->addMinutes(30)]);
        [$analysis] = $this->seedAnalysis(rooms: 12, options: ['files' => false]);
        // k rooms need k * 5 min + ceil(k / 8) * 60 s: 5 rooms need 26 min, 6 rooms need 31 min.
        $this->assertSame([5, 'project_expiry', 15], $this->summary($this->estimate($analysis)));

        // Rooms of other runs still to process are a backlog in front of this run: 2 * 5 min.
        [$other] = $this->seedAnalysis(rooms: 3, options: ['files' => false]);
        $this->seedRun($other, 'twilight', ['waiting', 'processing', 'ready']);
        $this->assertSame([3, 'project_expiry', 9], $this->summary($this->estimate($analysis)));

        config(['iguide-lab-tour.timing.edit_minutes_per_room' => ['min' => 1, 'max' => 2]]);
        $estimate = $this->estimate($analysis);
        $this->assertSame([12, null], [$estimate['roomsThatFit'], $estimate['limitingReason']]);
        $this->assertSame(['min' => 12, 'max' => 24], $estimate['estimatedMinutes']);
    }

    public function test_global_gates(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 4, options: ['files' => false]);
        $gate = fn (array $facts = []) => $this->summary($this->estimate($analysis, 'golden-hour', $this->facts($facts)));
        $this->assertSame([4, null, 12], $gate());

        config(['iguide-lab-tour.tour_runs_paused' => true]);
        $this->assertSame([0, 'runs_paused', 0], $gate(['runnable' => false]), 'gate 1 comes before gate 3');
        config(['iguide-lab-tour.tour_runs_paused' => false]);

        $active = $this->seedRun($analysis, 'golden-hour', ['waiting'], 'creating');
        $this->assertSame([0, 'active_run', 0], $gate(['runnable' => false]), 'gate 2 comes before gate 3');
        $this->assertSame(4, $this->estimate($analysis, 'golden-hour', $this->facts(), $active->id)['roomsThatFit'], 'excludeRunId skips the gate');
        $this->assertSame(4, $this->estimate($analysis, 'twilight')['roomsThatFit'], 'another preset is not gated');
        DB::table('iguide_lab_tour_runs')->where('id', $active->id)->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertSame([0, 'preset_not_runnable', 0], $gate(['runnable' => false]));
        $this->assertSame([0, 'preset_not_runnable', 0], $gate(['runnable' => ['golden-hour' => false, 'twilight' => true]]), 'per-preset runnable map');
        $this->assertSame([4, null, 12], $gate(['runnable' => ['golden-hour' => true]]));
        $this->assertSame([0, 'scheduler_mode_unsupported', 0], $gate(['schedulerEnabled' => true, 'tourBudgetEnabled' => true]));
        $this->assertSame([0, 'tour_budget_required', 0], $gate(['tourBudgetEnabled' => true]));
        $this->project->update(['revoked_at' => now()]);
        $this->assertSame([0, 'project_expiry', 0], $gate());
        $this->project->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
        $this->assertSame([0, 'project_expiry', 0], $gate());
        $this->project->update(['expires_at' => now()->addDays(10)]);

        // Without explicit flags in the facts, the live config is read (strict === true).
        config(['iguide-lab.tour_scheduler_enabled' => true]);
        $this->assertSame([[], 'scheduler_mode_unsupported'], $this->capacity()->fit($this->project->fresh(), 'golden-hour', $cases, ['policy' => ['lightingRepair' => []], 'runnable' => true], null));
        config(['iguide-lab.tour_scheduler_enabled' => 'true', 'iguide-lab.tour_processing_budget_enabled' => true]);
        $this->assertSame([[], 'tour_budget_required'], $this->capacity()->fit($this->project->fresh(), 'golden-hour', $cases, ['policy' => ['lightingRepair' => []], 'runnable' => true], null));
        // D1: grass and declutter are never runnable, whatever the facts say.
        config(['iguide-lab.tour_processing_budget_enabled' => false]);
        $this->assertSame([[], 'preset_not_runnable'], $this->capacity()->fit($this->project->fresh(), 'grass', $cases, $this->facts(), null));
    }

    public function test_nothing_selected_has_a_null_reason(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 2, options: ['files' => false]);
        $selections = $analysis->selections;
        $selections['golden-hour'] = ['revision' => 3, 'changes' => [$cases[0] => ['add' => [], 'remove' => ['g0']], $cases[1] => ['add' => [], 'remove' => ['g1']]]];
        IguideLabTourAnalysis::whereKey($analysis->id)->update(['selections' => json_encode($selections)]);
        config(['iguide-lab-tour.tour_runs_paused' => true]);

        $estimate = $this->estimate($analysis->fresh(), 'golden-hour', $this->facts(['runnable' => false]));
        $this->assertSame([3, 0, 0, 0, 0, null, ['min' => 0, 'max' => 0]], array_slice(array_values($estimate), 0, 7), 'gate 0 wins: null exactly when every selected room fits');
        $this->assertSame([[], null], $this->capacity()->fit($this->project->fresh(), 'golden-hour', [], $this->facts(), null));
    }

    public function test_outstanding_reservations_of_other_runs_are_subtracted(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 30, options: ['files' => false]);
        [$other] = $this->seedAnalysis(rooms: 12, options: ['files' => false]);
        // waiting, queued and processing reserve a full w(preset) each; terminal and not-admitted rooms reserve nothing.
        $running = $this->seedRun($other, 'twilight', ['waiting', 'waiting', 'queued', 'processing', 'ready', 'held', 'failed', 'cancelled', 'not_admitted']);
        $this->assertSame([20, 'evaluation_ceiling', 60], $this->summary($this->estimate($analysis)), '(120 - 4 * 5) / 5; L9 composites give the same, L7 is checked first');

        // A cancelled run still reserves its bound non-terminal rooms; a completed run reserves nothing.
        [$third] = $this->seedAnalysis(rooms: 3, options: ['files' => false]);
        $this->seedRun($third, 'golden-hour', ['processing', 'queued', 'ready'], 'cancelled');
        $this->assertSame([18, 'evaluation_ceiling', 54], $this->summary($this->estimate($analysis)), '(120 - 6 * 5) / 5');
        [$fourth] = $this->seedAnalysis(rooms: 2, options: ['files' => false]);
        $this->seedRun($fourth, 'golden-hour', ['ready', 'held'], 'completed');
        $this->assertSame([18, 'evaluation_ceiling', 54], $this->summary($this->estimate($analysis)));

        // Waiting rooms of a failed run (never left behind by the error paths) reserve nothing; its bound rooms do.
        [$fifth] = $this->seedAnalysis(rooms: 2, options: ['files' => false]);
        $this->seedRun($fifth, 'twilight', ['waiting', 'processing'], 'failed', ['error_code' => 'chunk_start_failed']);
        $this->assertSame([17, 'evaluation_ceiling', 51], $this->summary($this->estimate($analysis)), '(120 - 7 * 5) / 5');

        // The chunk re-check of the running run excludes the run itself.
        $this->assertCount(21, $this->capacity()->fit($this->project->fresh(), 'golden-hour', $cases, $this->facts(), $running->id)[0], 'only the cancelled and failed runs reserve: (120 - 3 * 5) / 5');
    }

    public function test_without_lighting_repair_uses_eight_records(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 30, options: ['files' => false]);
        $estimate = $this->estimate($analysis, 'twilight', $this->facts([], lightingRepair: false));
        $this->assertSame([15, 'evaluation_ceiling', 45], $this->summary($estimate), '120 / 8');
        $this->assertSame(['image' => 3, 'analysis' => 2, 'evaluation' => 8, 'composite' => 8, 'section' => 0, 'grass_assessment' => 0,
            'grass_admission' => 0, 'removal_plan' => 0, 'case' => 0], $this->capacity()->worstCase('twilight', ['version' => 'fixture']));
        // The cached policy facts form of 8.1 gives the same answer.
        $facts = ['policyKeys' => ['lightingRepair' => false], 'limits' => ['twilight' => ['image' => 3]]];
        $this->assertSame(8, $this->capacity()->worstCase('twilight', $facts)['evaluation']);
        $this->assertSame([15, 'evaluation_ceiling', 45], $this->summary($this->estimate($analysis, 'twilight', ['policy' => $facts, 'runnable' => true, 'schedulerEnabled' => false, 'tourBudgetEnabled' => false])));
    }

    public function test_grass_and_declutter_columns_for_later_deploys(): void
    {
        $capacity = $this->capacity();
        $grass = ['grassAssessment' => ['x'], 'grassSourceAdmission' => ['y']];
        $this->assertSame(['image' => 3, 'analysis' => 1, 'evaluation' => 1, 'composite' => 1, 'section' => 0, 'grass_assessment' => 1,
            'grass_admission' => 2, 'removal_plan' => 0, 'case' => 0], $capacity->worstCase('grass', $grass), 'image from limits() (no live grass policy here)');
        $this->assertSame(1, $capacity->worstCase('grass', ['policyKeys' => ['grassAssessment' => true], 'limits' => ['grass' => ['image' => 1]]])['image']);
        $this->assertSame(0, $capacity->worstCase('grass', ['policyKeys' => ['grassAssessment' => true, 'grassSourceAdmission' => false]])['grass_admission']);
        $this->assertSame(['image' => 1, 'analysis' => 1, 'evaluation' => 1, 'composite' => 1, 'section' => 1, 'grass_assessment' => 0,
            'grass_admission' => 0, 'removal_plan' => 1, 'case' => 1], $capacity->worstCase('declutter', ['policyKeys' => [], 'limits' => ['declutter' => ['image' => 1]]]));

        // Flip the run flags only inside this test (D3/D5 replace the accessors); the limits themselves are live code.
        [, $cases] = $this->seedAnalysis(rooms: 6, options: ['files' => false]);
        $facts = $this->facts(['policy' => ['policyKeys' => ['grassSourceAdmission' => true], 'limits' => ['grass' => ['image' => 1], 'declutter' => ['image' => 1]]]]);
        $flags = $this->flagsOn();
        $this->seedRecords('iguide_lab_grass_source_admissions', $this->project, 117);
        $this->assertSame([array_slice($cases, 0, 1), 'grass_admission_ceiling'], $flags->fit($this->project->fresh(), 'grass', $cases, $facts, null));
        $this->seedRecords('iguide_lab_grass_assessments', $this->makeProject(), 118, now()->subHour());
        $this->assertSame([array_slice($cases, 0, 1), 'grass_admission_ceiling'], $flags->fit($this->project->fresh(), 'grass', $cases, $facts, null));
        $this->assertSame([array_slice($cases, 0, 2), 'daily_grass_assessment_limit'], $flags->fit($this->project->fresh(), 'grass',
            $cases, $this->facts(['policy' => ['policyKeys' => [], 'limits' => ['grass' => ['image' => 1]]]]), null));

        // Declutter adds a helper case per room against the project's max_cases.
        $this->project->update(['max_cases' => 80]);
        $existing = DB::table('iguide_lab_cases')->where('project_id', $this->project->id)->count();
        for ($i = $existing; $i < 77; $i++) $this->seedCase($this->project, 'Fill', false);
        $this->assertSame([array_slice($cases, 0, 3), 'case_ceiling'], $flags->fit($this->project->fresh(), 'declutter', $cases, $facts, null));
        $this->seedRecords('iguide_lab_declutter_removal_plans', $this->makeProject(), 119, now()->subHour());
        $this->assertSame([array_slice($cases, 0, 1), 'daily_removal_plan_limit'], $flags->fit($this->project->fresh(), 'declutter', $cases, $facts, null));
    }

    public function test_policy_is_never_read_inside_a_transaction(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 3);
        [$run] = $this->createRun($analysis, 'twilight');
        $this->assertContains('snapshot', $this->policy->methods(), 'POST runs freezes a fresh policy (8.1)');
        $before = count($this->policy->calls);
        $this->tick($run);
        $this->assertGreaterThan($before, count($this->policy->calls), 'the monitor reads the policy for the chunk start');
        $this->assertContains('capabilities', array_slice($this->policy->methods(), $before), 'runnable(fresh) reads capabilities');
        foreach ($this->policy->calls as $call) $this->assertSame($this->policy->baselineLevel, $call['level'], $call['method'].' ran inside a transaction');
        $this->assertNotEmpty($this->workflows->startCalls);
        foreach ($this->workflows->startCalls as $call) $this->assertSame($this->workflows->baselineLevel, $call['level']);

        // The live estimate: policy facts are read before the snapshot opens; inside it nothing reads the policy.
        $facts = ['policy' => $this->capabilities->policyFacts(), 'runnable' => ['golden-hour' => true]];
        $calls = count($this->policy->calls);
        $estimate = DB::transaction(fn () => $this->estimate($analysis, 'golden-hour', $facts));
        $this->assertSame($calls, count($this->policy->calls));
        $this->assertSame(3, $estimate['roomsThatFit']);
    }

    /** Grass and declutter runs switched on, as D3/D5 will do by replacing the run-flag accessors. */
    private function flagsOn(): LabTourRunCapacity
    {
        return new class extends LabTourRunCapacity {
            public static function presetRunsEnabled(string $preset): bool { return true; }
        };
    }

    /** @return array{0:int,1:?string,2:int} [roomsThatFit, limitingReason, worstCaseImages] */
    private function summary(array $estimate): array
    {
        $this->assertSame($estimate['limitingReason'] === null, $estimate['roomsThatFit'] === $estimate['roomsSelected']);

        return [$estimate['roomsThatFit'], $estimate['limitingReason'], $estimate['worstCaseImages']];
    }
}
