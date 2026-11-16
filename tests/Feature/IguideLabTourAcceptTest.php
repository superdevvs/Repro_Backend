<?php

namespace Tests\Feature;

use App\Exceptions\PublicApiException;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use App\Services\IguideLab\LabTourAcceptances;
use App\Services\IguideLab\LabTourErrors;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TourRunFixtures;
use Tests\TestCase;

/** "Use this edit anyway" (spec 10.8, D13): stored only on the run target, with an append-only audit log. */
class IguideLabTourAcceptTest extends TestCase
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

    public function test_accept_held_attempt_records_only_in_the_tour_layer(): void
    {
        [$analysis, $run, $cases] = $this->runWithResults();
        $revision = $analysis->fresh()->record_revision;
        $this->travel(30)->seconds();

        $dto = $this->accept($analysis, $run, strtoupper($cases[1]), true);
        $this->assertRunDtoValid($dto);
        $this->assertSame(['acceptedAt' => now()->toIso8601String()], $dto['targets'][1]['acceptance']);
        $this->assertSame([null, null], [$dto['targets'][0]['acceptance'], $dto['targets'][2]['acceptance']]);
        $this->assertSame(1, $dto['counts']['accepted']);
        $this->assertSame(now()->toIso8601String(), $dto['updatedAt'], 'the run DTO changed, so runs.updated_at moved');
        $this->assertSame($revision + 1, $analysis->fresh()->record_revision);
        $target = IguideLabTourRunTarget::where('run_id', $run->id)->where('case_id', $cases[1])->sole();
        $this->assertSame([['action' => 'accept', 'at' => now()->toIso8601String()]], $target->acceptance_log);

        // Accepting again changes nothing and bumps nothing.
        $this->travel(30)->seconds();
        $again = $this->accept($analysis, $run, $cases[1], true);
        $this->assertSame($dto['targets'][1]['acceptance'], $again['targets'][1]['acceptance']);
        $this->assertSame([$revision + 1, 1], [$analysis->fresh()->record_revision, count($target->fresh()->acceptance_log)]);
        $this->assertSame($dto['updatedAt'], $again['updatedAt']);
    }

    public function test_ready_or_attemptless_held_is_409_not_held(): void
    {
        [$analysis, $run, $cases] = $this->runWithResults();
        $revision = $analysis->fresh()->record_revision;
        foreach ([$cases[0] => 'ready', $cases[2] => 'held without a composite'] as $caseId => $label) {
            try {
                $this->accept($analysis, $run, $caseId, true);
                $this->fail('Expected notHeld for '.$label);
            } catch (PublicApiException $error) {
                $this->assertSame([409, 'tour_not_held', LabTourErrors::MESSAGES['notHeld'][2]], [$error->getStatusCode(), $error->errorCode, $error->getMessage()], $label);
            }
        }
        // Undo of a target that was never accepted is a no-op.
        $dto = $this->accept($analysis, $run, $cases[0], false);
        $this->assertSame([null, $revision], [$dto['targets'][0]['acceptance'], $analysis->fresh()->record_revision]);
        $this->assertSame(0, IguideLabTourRunTarget::whereNotNull('accepted_at')->count());
        $this->assertSame(['[]'], array_values(array_unique(DB::table('iguide_lab_tour_run_targets')->pluck('acceptance_log')->all())));
    }

    public function test_undo_clears_and_appends_to_the_audit_log(): void
    {
        [$analysis, $run, $cases] = $this->runWithResults();
        $times = [];
        foreach ([true, false, false, true] as $accepted) {
            $this->travel(10)->seconds();
            $times[] = now()->toIso8601String();
            $dto = $this->accept($analysis, $run, $cases[1], $accepted);
            $this->assertRunDtoValid($dto);
            $this->assertSame($accepted ? ['acceptedAt' => now()->toIso8601String()] : null, $dto['targets'][1]['acceptance']);
        }
        $log = IguideLabTourRunTarget::where('run_id', $run->id)->where('case_id', $cases[1])->sole()->acceptance_log;
        $this->assertSame([['action' => 'accept', 'at' => $times[0]], ['action' => 'undo', 'at' => $times[1]], ['action' => 'accept', 'at' => $times[3]]], $log,
            'append-only; the repeated undo added nothing');
        $this->assertTrue(array_is_list(json_decode(DB::table('iguide_lab_tour_run_targets')->where('case_id', $cases[1])->value('acceptance_log'), true)));
    }

    public function test_unknown_run_or_case_is_404(): void
    {
        [$analysis, $run, $cases] = $this->runWithResults();
        [$other] = $this->seedAnalysis(rooms: 1, options: ['files' => false]);
        $foreign = $this->seedRun($other, 'twilight', ['ready'], 'completed');
        $calls = [
            'unknown run' => fn () => app(LabTourAcceptances::class)->accept($analysis, ['runId' => (string) Str::uuid(), 'caseId' => $cases[1], 'accepted' => true]),
            'run of another analysis' => fn () => app(LabTourAcceptances::class)->accept($analysis, ['runId' => $foreign->id, 'caseId' => $cases[1], 'accepted' => true]),
            'case not in the run' => fn () => app(LabTourAcceptances::class)->accept($analysis, ['runId' => $run->id, 'caseId' => (string) Str::uuid(), 'accepted' => true]),
            'case of another run' => fn () => app(LabTourAcceptances::class)->accept($analysis, ['runId' => $run->id, 'caseId' => IguideLabTourRunTarget::where('run_id', $foreign->id)->value('case_id'), 'accepted' => true]),
        ];
        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail('Expected 404 for '.$label);
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, IguideLabTourRunTarget::whereNotNull('accepted_at')->count());
    }

    public function test_workflow_review_and_composite_tables_are_untouched(): void
    {
        [$analysis, $run, $cases] = $this->runWithResults();
        $workflow = IguideLabWorkflow::sole();
        $tables = ['iguide_lab_workflows', 'iguide_lab_workflow_items', 'iguide_lab_workflow_steps', 'iguide_lab_composites', 'iguide_lab_composite_reviews', 'iguide_lab_reviews'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $dtoBefore = app(LabWorkflows::class)->dto($workflow->fresh());
        $this->travel(45)->seconds();

        $this->accept($analysis, $run, $cases[1], true);
        $this->travel(45)->seconds();
        $this->accept($analysis, $run, $cases[1], false);
        $this->accept($analysis, $run, $cases[1], true);

        $this->assertSame($before, $snapshot(), 'row counts and updated_at identical');
        $dto = app(LabWorkflows::class)->dto($workflow->fresh());
        $this->assertSame($dtoBefore, $dto);
        foreach ($dto['items'] as $item) $this->assertFalse($item['automation']['manualAcceptance']);
        $this->assertSame('needs_review', collect($dto['items'])->firstWhere('caseId', $cases[1])['automation']['qualityStatus']);
    }

    /**
     * A bound run of 3 rooms: ready with a composite, held with a composite (an attempt), held without a composite.
     *
     * @return array{0:IguideLabTourAnalysis,1:IguideLabTourRun,2:list<string>}
     */
    private function runWithResults(): array
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 3);
        [$run] = $this->createRun($analysis, 'twilight');
        $this->tick($run);
        [$a, $b, $c] = IguideLabWorkflowItem::orderBy('position')->get()->all();
        app(LabWorkflows::class)->finish($a, 'ready', null, null, ['compositeId' => $this->composite($cases[0]), 'reviewReasons' => []]);
        app(LabWorkflows::class)->finish($b, 'needs_review', 'quality_checks_failed', 'Fixture.', ['compositeId' => $this->composite($cases[1]), 'reviewReasons' => []]);
        app(LabWorkflows::class)->finish($c, 'needs_review', 'analysis_incomplete', 'Fixture.', ['reviewReasons' => []]);
        $this->tick($run);
        $this->assertSame([['ready', true], ['held', true], ['held', false]], IguideLabTourRunTarget::where('run_id', $run->id)->orderBy('position')->get()
            ->map(fn ($t) => [$t->state, $t->has_attempt])->all());

        return [$analysis->fresh(), $run->fresh(), $cases];
    }

    private function accept(IguideLabTourAnalysis $analysis, IguideLabTourRun $run, string $caseId, bool $accepted): array
    {
        return app(LabTourAcceptances::class)->accept($analysis->fresh(), ['runId' => strtoupper($run->id), 'caseId' => $caseId, 'accepted' => $accepted]);
    }

    private function composite(string $caseId): string
    {
        $id = (string) Str::uuid();
        DB::table('iguide_lab_composites')->insert(['id' => $id, 'project_id' => $this->project->id, 'case_id' => $caseId,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'label' => 'Fixture', 'preview_path' => 'fixture',
            'manifest' => json_encode(['preset' => 'twilight', 'resolutionStrategy' => 'fixture', 'preview' => ['width' => 2048, 'height' => 1024]]),
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
