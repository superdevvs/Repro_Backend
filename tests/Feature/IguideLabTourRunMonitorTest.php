<?php

namespace Tests\Feature;

use App\Jobs\MonitorIguideLabTourRun;
use App\Jobs\ProcessIguideLabWorkflow;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunChunk;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabTourRunCapacity;
use App\Services\IguideLab\LabTourRuns;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TourRunFixtures;
use Tests\TestCase;

/**
 * Run monitor (spec 9.2-9.6, 9.8): sequential chunks through the real LabWorkflows::start() behind
 * TransactionAssertingLabWorkflows, target sync, crash recovery, cancel during start, failures, stall, kicks.
 */
class IguideLabTourRunMonitorTest extends TestCase
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

    public function test_24_rooms_become_3_sequential_workflows_with_uuid_request_ids(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 30);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $this->assertSame(24, $this->states($run, 'waiting'), 'real capacity: a fresh project admits 24 lighting rooms (8.3)');

        $workflows = [];
        for ($chunk = 1; $chunk <= 3; $chunk++) {
            $this->assertTrue($this->tick($run));
            $this->assertSame($chunk, IguideLabWorkflow::count(), 'one new workflow per terminal predecessor');
            $this->assertTrue($this->tick($run));
            $this->assertSame($chunk, IguideLabWorkflow::count(), 'no next chunk while the previous workflow is running');
            $dto = $this->runDto($run);
            $this->assertRunDtoValid($dto);
            $this->assertSame('running', $dto['status']);
            $workflows[] = end($dto['workflows']);
            $this->assertSame(array_slice($cases, ($chunk - 1) * 8, 8), end($dto['workflows'])['caseIds']);
            $this->finishWorkflow(end($dto['workflows'])['id']);
        }
        $this->assertFalse($this->tick($run), 'completed: the monitor stops');
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame(['completed', 24, 6, null], [$dto['status'], $dto['counts']['held'], $dto['counts']['selected'] - $dto['counts']['admitted'], $dto['error']]);
        $requestIds = array_column($workflows, 'requestId');
        foreach ($requestIds as $requestId) $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestId);
        $this->assertSame(3, count(array_unique([...$requestIds, $run->request_id])) - 1);
        $this->assertNotContains($run->request_id, $requestIds);
        $this->assertCount(3, $this->workflows->startCalls);
        foreach ($this->workflows->startCalls as $call) {
            $this->assertSame(0, $call['level'] - $this->workflows->baselineLevel);
            $this->assertCount(8, $call['input']['items']);
            foreach ($call['input']['items'] as $item) $this->assertSame(['preset' => 'golden-hour', 'style' => null, 'instructions' => ''], array_diff_key($item, ['caseId' => 1]));
        }
        $this->assertNull(app(LabTourRuns::class)->activeRunId($this->tourView($analysis), 'golden-hour'));
    }

    public function test_80_rooms_need_10_chunks_with_a_capacity_double(): void
    {
        $this->app->instance(LabTourRunCapacity::class, new class extends LabTourRunCapacity {
            public function fit(IguideLabProject $project, string $preset, array $orderedCaseIds, array $facts, ?string $excludeRunId): array
            {
                return [array_values(array_map('strtolower', $orderedCaseIds)), null];
            }
        });
        [$analysis] = $this->seedAnalysis(rooms: 80);
        [$run, , $dto] = $this->createRun($analysis, 'twilight');
        $this->assertSame([80, 80, 240, ['min' => 240, 'max' => 400]], [$dto['estimate']['roomsSelected'], $dto['estimate']['roomsThatFit'],
            $dto['estimate']['worstCaseImages'], $dto['estimate']['estimatedMinutes']]);
        for ($i = 1; $i <= 10; $i++) {
            $this->assertTrue($this->tick($run));
            $this->assertSame($i, IguideLabWorkflow::count());
            $this->finishWorkflow(IguideLabTourRunChunk::where('run_id', $run->id)->where('position', $i - 1)->value('workflow_id'));
        }
        $this->assertFalse($this->tick($run));
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertCount(10, $dto['workflows'], 'maxWorkflowsPerRun (D3)');
        $this->assertSame(['completed', 80], [$dto['status'], $dto['counts']['held']]);
    }

    public static function itemStatuses(): array
    {
        return ['queued' => ['queued', 'queued'], 'analyzing' => ['analyzing', 'processing'], 'preparing' => ['preparing', 'processing'],
            'editing' => ['editing', 'processing'], 'repairing' => ['repairing', 'processing'], 'checking' => ['checking', 'processing'],
            'ready' => ['ready', 'ready'], 'needs_review' => ['needs_review', 'held'], 'failed' => ['failed', 'failed'],
            'cancelled' => ['cancelled', 'cancelled'], 'unknown' => ['mystery_state', 'processing']];
    }

    #[DataProvider('itemStatuses')]
    public function test_item_statuses_map_to_target_states(string $status, string $state): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 1);
        [$run] = $this->createRun($analysis, 'twilight');
        $this->tick($run);
        $item = IguideLabWorkflowItem::sole();
        $composite = $this->composite($cases[0]);
        $revision = $analysis->fresh()->record_revision;
        $item->update(['status' => $status, 'data' => ['compositeId' => $composite, 'reviewReasons' => [['code' => 'quality_checks_failed', 'message' => 'x'],
            ['code' => 'Not A Code'], ['code' => 'quality_checks_failed'], ['message' => 'no code'], ['code' => 'evaluation_unconfirmed']]]]);
        $this->tick($run);
        $target = IguideLabTourRunTarget::sole();
        $this->assertSame($state, $target->state);
        $this->assertSame(in_array($state, ['held', 'failed', 'cancelled'], true) ? ['quality_checks_failed', 'evaluation_unconfirmed'] : [], $target->reasons);
        $this->assertSame(in_array($state, ['ready', 'held'], true), $target->has_attempt);
        $this->assertSame([$item->workflow_id, $item->id], [$target->workflow_id, $target->workflow_item_id]);
        $this->assertSame($state === 'queued' ? $revision : $revision + 1 + (in_array($state, ['queued', 'processing'], true) ? 0 : 1),
            $analysis->fresh()->record_revision, 'a visible change bumps once; completion bumps once more');
        $this->assertRunDtoValid($this->runDto($run));
    }

    public function test_has_attempt_needs_an_existing_composite(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 1);
        [$run] = $this->createRun($analysis, 'twilight');
        $this->tick($run);
        $item = IguideLabWorkflowItem::sole();
        // A ready item whose composite does not exist (or belongs to another project) keeps ready => hasAttempt by showing held.
        $other = $this->makeProject();
        $foreign = $this->composite($this->seedCase($other, 'Other', false), $other);
        $item->update(['status' => 'ready', 'data' => ['compositeId' => $foreign, 'reviewReasons' => []]]);
        $this->tick($run);
        $this->assertSame(['held', ['result_unconfirmed'], false], $this->target($run));
        $this->assertRunDtoValid($this->runDto($run));
        $item->update(['data' => ['compositeId' => (string) Str::uuid(), 'reviewReasons' => []]]);
        $this->tick($run);
        $this->assertSame(['held', ['result_unconfirmed'], false], $this->target($run));
        $item->update(['data' => ['compositeId' => $this->composite($cases[0]), 'reviewReasons' => []]]);
        $this->tick($run);
        $this->assertSame(['ready', [], true], $this->target($run));
        $item->update(['status' => 'needs_review', 'data' => ['reviewReasons' => [['code' => 'quality_checks_failed']]]]);
        $this->tick($run);
        $this->assertSame(['held', ['quality_checks_failed'], false], $this->target($run), 'held without a composite has no attempt');
        $this->assertRunDtoValid($this->runDto($run));
    }

    public function test_reserved_chunk_is_replayed_after_a_crash_without_a_duplicate_workflow(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 2);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $chunk = $this->crashBetweenStartAndBind($run);
        // The source changed since: a start() replay would now answer 409 (identity) or 422 (source).
        Storage::disk(LabService::DISK)->put('iguide-lab/'.$this->project->id.'/cases/'.$cases[0].'.png', 'changed bytes');
        $starts = count($this->workflows->startCalls);

        $this->assertTrue($this->tick($run));
        $this->assertSame($starts, count($this->workflows->startCalls), 'bound without calling start()');
        $this->assertSame(1, IguideLabWorkflow::count());
        $this->assertSame(['started', IguideLabWorkflow::sole()->id], [$chunk->fresh()->status, $chunk->fresh()->workflow_id]);
        $this->assertSame(['queued', 'queued'], IguideLabTourRunTarget::where('run_id', $run->id)->orderBy('position')->pluck('state')->all());
        $this->assertRunDtoValid($this->runDto($run));

        // The project became inactive (start() would answer 401): the T1 expiry stop still binds the crashed chunk.
        [$second] = $this->seedAnalysis(rooms: 1);
        [$run2] = $this->createRun($second, 'twilight');
        $chunk2 = $this->crashBetweenStartAndBind($run2);
        $this->project->update(['revoked_at' => now()]);
        $starts = count($this->workflows->startCalls);
        $this->assertFalse($this->tick($run2), 'inactive project: the monitor stops after the final sync');
        $this->assertSame($starts, count($this->workflows->startCalls));
        $this->assertSame('started', $chunk2->fresh()->status);
        $this->assertSame(2, IguideLabWorkflow::count());
        $this->assertSame([], $this->workflows->cancelCalls, 'an error path never cancels a created workflow (README decision 10)');
        $this->assertSame(['running', 'project_expiry'], [$run2->fresh()->status, $run2->fresh()->error_code]);
    }

    public function test_cancel_during_chunk_start_cancels_the_created_workflow(): void
    {
        // The cancel commits while start() is in flight, before the workflow exists.
        [$analysis] = $this->seedAnalysis(rooms: 2);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $this->workflows->beforeStart = function () use ($analysis) {
            $this->workflows->beforeStart = null;
            $this->assertSame([], $this->cancelAnalysis($analysis), 'nothing to cancel yet: the chunk is only reserved');
            $this->assertSame('cancelled', IguideLabTourRunChunk::sole()->status);
        };
        $this->tick($run);
        $workflow = IguideLabWorkflow::sole();
        $this->assertCancelledRunWithBoundWorkflow($analysis, $run, $workflow);

        // The cancel commits after start() created the workflow, before Bind: cancel binds it and cancels it once.
        [$second] = $this->seedAnalysis(rooms: 2);
        [$run2] = $this->createRun($second, 'twilight');
        $this->workflows->cancelCalls = [];
        $this->workflows->beforeReturn = function (IguideLabWorkflow $created) use ($second) {
            $this->workflows->beforeReturn = null;
            $this->assertSame([$created->id], $this->cancelAnalysis($second));
        };
        $this->tick($run2);
        $this->assertCancelledRunWithBoundWorkflow($second, $run2, IguideLabWorkflow::whereKeyNot($workflow->id)->sole());
    }

    public function test_cancel_after_crash_between_start_and_bind_binds_and_cancels(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 2);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $chunk = $this->crashBetweenStartAndBind($run);
        $workflow = IguideLabWorkflow::sole();
        $revision = $analysis->fresh()->record_revision;

        $this->assertSame([$workflow->id], $this->cancelAnalysis($analysis));
        $this->assertSame(['started', $workflow->id], [$chunk->fresh()->status, $chunk->fresh()->workflow_id]);
        $this->assertNotNull($workflow->fresh()->cancelled_at);
        $this->assertSame([$workflow->id], $this->workflows->cancelCalls);
        $this->assertSame($revision + 1, $analysis->fresh()->record_revision);
        $this->assertSame([], $this->cancelAnalysis($analysis), 'idempotent');
        $this->assertSame($revision + 1, $analysis->fresh()->record_revision, 'a no-op cancel does not bump');
        $this->assertCancelledRunWithBoundWorkflow($analysis, $run, $workflow);
    }

    public function test_throwable_from_start_then_ten_not_ready_ticks_ends_failed(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 3);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $this->workflows->override = fn () => throw new \RuntimeException('transport detail that must not be stored');
        $this->assertTrue($this->tick($run));
        $chunk = IguideLabTourRunChunk::sole();
        $this->assertSame(['reserved', 1, ['exceptionClass' => \RuntimeException::class, 'httpStatus' => null, 'recordedAt' => now()->toIso8601String()]],
            [$chunk->status, $chunk->start_attempts, $chunk->diagnostic]);
        $this->assertStringNotContainsString('transport detail', json_encode(DB::table('iguide_lab_tour_run_chunks')->get()));

        $this->workflows->override = null;
        $this->capabilities->freshReady = ['golden-hour' => false];
        for ($i = 1; $i <= 9; $i++) {
            $this->assertTrue($this->tick($run));
            $this->assertSame([$i, 'reserved'], [$run->fresh()->not_ready_ticks, $chunk->fresh()->status]);
        }
        $this->assertFalse($this->tick($run));
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame(['failed', 'recipe_unavailable', 3], [$dto['status'], $dto['error'], $dto['counts']['cancelled']]);
        $this->assertSame([['recipe_unavailable']], array_values(array_unique(array_column($dto['targets'], 'reasons'), SORT_REGULAR)));
        $this->assertSame(['failed', 'recipe_unavailable'], [$chunk->fresh()->status, $chunk->fresh()->error_code]);
        $this->assertSame(0, IguideLabTourRunChunk::where('status', 'reserved')->count());
        $this->assertNull(app(LabTourRuns::class)->activeRunId($this->tourView($analysis), 'golden-hour'));
        $this->assertNull($run->fresh()->monitor_token);
        $this->assertSame(1, count($this->workflows->startCalls));
    }

    public function test_editor_workflow_started_between_chunks_excludes_the_case(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 10);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $this->tick($run);
        $first = IguideLabWorkflow::sole();
        app(LabWorkflows::class)->start($this->project, ['requestId' => (string) Str::uuid(),
            'items' => [['caseId' => $cases[9], 'preset' => 'golden-hour', 'style' => null, 'instructions' => 'Editor request.']]]);
        $this->finishWorkflow($first->id);
        $this->tick($run);
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame([$cases[8]], $dto['workflows'][1]['caseIds']);
        $this->assertSame(['cancelled', ['duplicate_active'], null], [$dto['targets'][9]['state'], $dto['targets'][9]['reasons'], $dto['targets'][9]['workflowId']]);
    }

    public function test_stalled_workflow_without_job_is_stopped_and_the_run_completes(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 2);
        [$run] = $this->createRun($analysis, 'twilight');
        $this->tick($run);
        $workflow = IguideLabWorkflow::sole();
        $this->travel(901)->seconds();
        // A waiting or reserved job keeps its jobs row: that workflow is waiting, not stalled.
        DB::table('jobs')->insert(['queue' => 'studio', 'payload' => json_encode(['displayName' => ProcessIguideLabWorkflow::class, 'data' => ['workflowId' => $workflow->id]]),
            'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        $this->assertTrue($this->tick($run));
        $this->assertSame([], $this->workflows->stopCalls);
        DB::table('jobs')->delete();

        $this->assertTrue($this->tick($run));
        $this->assertSame([['workflow' => $workflow->id, 'code' => 'workflow_interrupted']], $this->workflows->stopCalls);
        $this->assertFalse($this->tick($run), 'the next T1 syncs held targets and the run completes');
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame(['completed', 2, ['workflow_interrupted']], [$dto['status'], $dto['counts']['held'], $dto['targets'][0]['reasons']]);
    }

    public function test_monitor_stops_for_an_inactive_project(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 3, options: ['files' => false]);
        [$run] = $this->createRun($analysis, 'golden-hour');
        DB::table('iguide_lab_tour_runs')->where('id', $run->id)->update(['monitor_token' => (string) Str::uuid(), 'monitor_heartbeat_at' => now()]);
        $this->project->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse($this->tick($run));
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame(['failed', 'project_expiry', ['project_expiry']], [$dto['status'], $dto['error'], $dto['targets'][0]['reasons']]);
        $this->assertSame([null, null], [$run->fresh()->monitor_token, $run->fresh()->monitor_heartbeat_at]);
        $this->assertSame([], $this->workflows->startCalls);
        $this->assertFalse(app(LabTourRuns::class)->needsMonitor($run->fresh(), true));
    }

    public function test_capacity_recheck_marks_remaining_rooms_not_admitted(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 20);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $this->assertSame(20, $this->states($run, 'waiting'));
        $this->tick($run);
        $this->seedRecords('iguide_lab_workflow_evaluations', $this->project, 70);
        $this->finishWorkflow(IguideLabWorkflow::where('id', IguideLabTourRunChunk::where('run_id', $run->id)->value('workflow_id'))->value('id'));
        $revision = $analysis->fresh()->record_revision;
        $this->tick($run);
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame([20, 18], [$dto['estimate']['roomsThatFit'], $dto['counts']['admitted']], '(120 - 70) / 5 = 10 of the 12 remaining rooms fit');
        $this->assertSame([['evaluation_ceiling'], ['evaluation_ceiling']], array_column(array_slice($dto['targets'], 18), 'reasons'));
        $this->assertSame(['not_admitted', 'not_admitted'], array_column(array_slice($dto['targets'], 18), 'state'));
        $this->assertSame(array_slice($cases, 8, 8), $dto['workflows'][1]['caseIds']);
        $this->assertGreaterThan($revision, $analysis->fresh()->record_revision);
    }

    public function test_start_exceptions_map_to_documented_reasons(): void
    {
        config(['iguide-lab-tour.runs.chunk_size' => 2]);
        $cases = [
            'http401' => [new HttpException(401), ['failed', 'project_expiry'], array_fill(0, 5, 'cancelled'), ['project_expiry'], 'failed', 'project_expiry', 0],
            'http429' => [new HttpException(429), ['failed', 'workflow_ceiling'], array_fill(0, 5, 'not_admitted'), ['workflow_ceiling'], 'completed', null, 0],
            'http409' => [new HttpException(409), ['failed', 'request_conflict'], ['cancelled', 'cancelled', 'waiting', 'waiting', 'waiting'], ['request_conflict'], 'creating', null, 1],
            'http422' => [new HttpException(422), ['failed', 'chunk_rejected'], ['cancelled', 'cancelled', 'waiting', 'waiting', 'waiting'], ['chunk_rejected'], 'creating', null, 1],
            'missing' => [new ModelNotFoundException, ['failed', 'source_missing'], ['cancelled', 'cancelled', 'waiting', 'waiting', 'waiting'], ['source_missing'], 'creating', null, 1],
        ];
        foreach ($cases as $label => [$error, $chunkState, $targetStates, $reasons, $status, $runError, $failures]) {
            [$analysis] = $this->seedAnalysis(rooms: 5);
            [$run] = $this->createRun($analysis, 'golden-hour');
            $this->workflows->override = fn () => throw $error;
            $this->tick($run);
            $chunk = IguideLabTourRunChunk::where('run_id', $run->id)->sole();
            $this->assertSame($chunkState, [$chunk->status, $chunk->error_code], $label);
            $this->assertSame(['exceptionClass' => get_class($error), 'httpStatus' => $error instanceof HttpException ? $error->getStatusCode() : null],
                array_slice($chunk->diagnostic, 0, 2), $label);
            $this->assertSame($targetStates, IguideLabTourRunTarget::where('run_id', $run->id)->orderBy('position')->pluck('state')->all(), $label);
            $this->assertSame($reasons, IguideLabTourRunTarget::where('run_id', $run->id)->orderBy('position')->first()->reasons, $label);
            $fresh = $run->fresh();
            $this->assertSame([$status, $runError, $failures], [$fresh->status, $fresh->status === 'failed' ? $fresh->error_code : null, $fresh->consecutive_chunk_failures], $label);
            $this->assertRunDtoValid($this->runDto($run));
            if ($label === 'http409') {
                // The second consecutive failure stops the run: every waiting room is cancelled with chunk_start_failed.
                $this->tick($run);
                $this->assertSame(['failed', 'chunk_start_failed', 2], [$run->fresh()->status, $run->fresh()->error_code, $run->fresh()->consecutive_chunk_failures]);
                $reasons = IguideLabTourRunTarget::where('run_id', $run->id)->orderBy('position')->get()->pluck('reasons')->all();
                $this->assertSame([['request_conflict'], ['request_conflict'], ['request_conflict'], ['request_conflict'], ['chunk_start_failed']], $reasons);
                $this->assertRunDtoValid($this->runDto($run));
            }
        }

        // Any other Throwable keeps the chunk reserved until 3 attempts and 600 s, then fails it as chunk_start_failed.
        [$analysis] = $this->seedAnalysis(rooms: 3);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $this->workflows->override = fn () => throw new \LogicException('other');
        for ($i = 1; $i <= 3; $i++) {
            $this->tick($run);
            $this->assertSame(['reserved', $i], [IguideLabTourRunChunk::where('run_id', $run->id)->sole()->status, IguideLabTourRunChunk::where('run_id', $run->id)->sole()->start_attempts]);
        }
        $this->travel(601)->seconds();
        $this->tick($run);
        $chunk = IguideLabTourRunChunk::where('run_id', $run->id)->orderBy('position')->first();
        $this->assertSame(['failed', 'chunk_start_failed', 4], [$chunk->status, $chunk->error_code, $chunk->start_attempts]);
        $this->assertSame(['cancelled', 'cancelled'], IguideLabTourRunTarget::where('run_id', $run->id)->where('chunk_id', $chunk->id)->pluck('state')->all());
        $this->assertSame(1, $run->fresh()->consecutive_chunk_failures);
        $this->assertRunDtoValid($this->runDto($run));
    }

    public function test_invalid_source_is_excluded_before_start(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 3);
        Storage::disk(LabService::DISK)->put('iguide-lab/'.$this->project->id.'/cases/'.$cases[1].'.png', 'not the source');
        [$run] = $this->createRun($analysis, 'twilight');
        $this->tick($run);
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame([$cases[0], $cases[2]], $dto['workflows'][0]['caseIds']);
        $this->assertSame(['queued', 'cancelled', 'queued'], array_column($dto['targets'], 'state'));
        $this->assertSame(['invalid_source'], $dto['targets'][1]['reasons']);
        $this->assertSame(2, IguideLabWorkflowItem::count());
    }

    public function test_run_completes_and_active_run_clears(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 2);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $this->assertSame($run->id, app(LabTourRuns::class)->activeRunId($this->tourView($analysis), 'golden-hour'));
        $this->assertNull(app(LabTourRuns::class)->activeRunId($this->tourView($analysis), 'twilight'));
        $this->tick($run);
        [$first, $second] = IguideLabWorkflowItem::orderBy('position')->get()->all();
        app(LabWorkflows::class)->finish($first, 'ready', null, null, ['compositeId' => $this->composite($cases[0]), 'reviewReasons' => []]);
        app(LabWorkflows::class)->finish($second, 'needs_review', 'quality_checks_failed', 'Fixture.', ['compositeId' => $this->composite($cases[1]), 'reviewReasons' => []]);
        $this->assertFalse($this->tick($run));
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame(['completed', 1, 1, 0, true, true], [$dto['status'], $dto['counts']['ready'], $dto['counts']['held'], $dto['counts']['pending'],
            $dto['targets'][0]['hasAttempt'], $dto['targets'][1]['hasAttempt']]);
        $this->assertSame(now()->toIso8601String(), $dto['completedAt']);
        $this->assertNull(app(LabTourRuns::class)->activeRunId($this->tourView($analysis), 'golden-hour'));
        $this->assertSame('finished', IguideLabTourRunChunk::sole()->status);
    }

    public function test_paused_or_disabled_creates_no_chunk(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 2);
        [$run] = $this->createRun($analysis, 'golden-hour');
        config(['iguide-lab-tour.tour_runs_paused' => true]);
        $this->assertTrue($this->tick($run), 'paused: the monitor keeps running but creates nothing');
        $this->assertSame([0, 'creating', 0], [IguideLabTourRunChunk::count(), $run->fresh()->status, $run->fresh()->not_ready_ticks]);

        config(['iguide-lab-tour.tour_runs_paused' => false, 'iguide-lab-tour.tour_analysis_enabled' => false]);
        DB::table('iguide_lab_tour_runs')->where('id', $run->id)->update(['monitor_token' => (string) Str::uuid(), 'monitor_heartbeat_at' => now()]);
        $updated = $run->fresh()->updated_at->toIso8601String();
        $this->assertFalse($this->tick($run), 'flag off: the chain stops (D10)');
        $this->assertSame([0, null, null, $updated], [IguideLabTourRunChunk::count(), $run->fresh()->monitor_token, $run->fresh()->monitor_heartbeat_at,
            $run->fresh()->updated_at->toIso8601String()]);

        config(['iguide-lab-tour.tour_analysis_enabled' => true]);
        $this->assertTrue($this->tick($run));
        $this->assertSame(['started', 'running'], [IguideLabTourRunChunk::sole()->status, $run->fresh()->status]);
    }

    public function test_not_ready_in_process_cancels_after_ten_ticks(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 2);
        [$run] = $this->createRun($analysis, 'twilight');
        $this->capabilities->freshReady = ['twilight' => false];
        $updated = $run->fresh()->updated_at->toIso8601String();
        for ($i = 1; $i <= 9; $i++) {
            $this->travel(60)->seconds();
            $this->assertTrue($this->tick($run));
        }
        $this->assertSame([9, 0, 'creating', $updated], [$run->fresh()->not_ready_ticks, IguideLabTourRunChunk::count(), $run->fresh()->status,
            $run->fresh()->updated_at->toIso8601String()], 'not_ready_ticks is bookkeeping');
        $this->assertSame([], $this->workflows->startCalls);
        $this->assertSame([false, ...array_fill(0, 9, true)], array_column($this->capabilities->runnableCalls, 'fresh'), 'POST runs asks cached, the monitor asks fresh (8.1)');
        $this->assertFalse($this->tick($run));
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame(['failed', 'recipe_unavailable', ['cancelled', 'cancelled']], [$dto['status'], $dto['error'], array_column($dto['targets'], 'state')]);
    }

    public function test_get_kicks_a_stale_monitor_with_one_job_and_no_record_write(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 2);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $this->tick($run);
        $before = [$analysis->fresh()->record_revision, $analysis->fresh()->updated_at->toIso8601String(), $run->fresh()->updated_at->toIso8601String()];
        $pushed = count($this->queue->pushed(MonitorIguideLabTourRun::class));
        $this->travel(301)->seconds();

        app(LabTourRuns::class)->kickIfStale($run->fresh());
        $this->assertCount($pushed + 1, $this->queue->pushed(MonitorIguideLabTourRun::class));
        $job = collect($this->queue->pushed(MonitorIguideLabTourRun::class))->last();
        $this->assertSame([$run->id, null], [$job->runId, $job->seq], 'a new chain');
        $this->assertSame($before, [$analysis->fresh()->record_revision, $analysis->fresh()->updated_at->toIso8601String(), $run->fresh()->updated_at->toIso8601String()]);

        // A tick with no visible change writes neither the revision nor either updatedAt.
        $this->assertTrue($this->tick($run));
        $this->assertSame($before, [$analysis->fresh()->record_revision, $analysis->fresh()->updated_at->toIso8601String(), $run->fresh()->updated_at->toIso8601String()]);

        // A fresh heartbeat is not stale; a finished run needs no monitor.
        DB::table('iguide_lab_tour_runs')->where('id', $run->id)->update(['monitor_heartbeat_at' => now()]);
        Cache::flush();
        app(LabTourRuns::class)->kickIfStale($run->fresh());
        $this->assertCount($pushed + 1, $this->queue->pushed(MonitorIguideLabTourRun::class));
    }

    public function test_get_kick_is_deduplicated(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 1, options: ['files' => false]);
        [$run] = $this->createRun($analysis, 'golden-hour');
        $pushed = count($this->queue->pushed(MonitorIguideLabTourRun::class));
        DB::table('iguide_lab_tour_runs')->where('id', $run->id)->update(['monitor_heartbeat_at' => now()->subMinutes(10), 'monitor_token' => (string) Str::uuid()]);
        // A successor waiting behind a long job on the shared worker keeps its jobs row.
        DB::table('jobs')->insert(['queue' => 'studio', 'payload' => json_encode(['displayName' => MonitorIguideLabTourRun::class, 'data' => ['command' => 'runId '.$run->id]]),
            'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        for ($i = 0; $i < 50; $i++) app(LabTourRuns::class)->kickIfStale($run->fresh());
        $this->assertCount($pushed, $this->queue->pushed(MonitorIguideLabTourRun::class));

        DB::table('jobs')->delete();
        for ($i = 0; $i < 50; $i++) app(LabTourRuns::class)->kickIfStale($run->fresh());
        $this->assertCount($pushed + 1, $this->queue->pushed(MonitorIguideLabTourRun::class), 'the kick gate allows one push per minute');

        Cache::flush();
        $this->queue->failNextPush(new \RuntimeException('database is down'));
        app(LabTourRuns::class)->kickIfStale($run->fresh());
        $this->assertCount($pushed + 1, $this->queue->pushed(MonitorIguideLabTourRun::class), 'a failed kick never throws');
    }

    public function test_monitor_job_claims_a_tick_and_pushes_its_successor(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 1);
        [$run] = $this->createRun($analysis, 'twilight');
        $first = $this->queue->pushed(MonitorIguideLabTourRun::class)[0];
        $this->assertSame([300, 0, 3, [10, 30, 60]], [$first->timeout, $first->tries, $first->maxExceptions, $first->backoff]);
        $first->handle(app(LabTourRuns::class));
        $this->assertSame([$first->token, 1], [$run->fresh()->monitor_token, $run->fresh()->monitor_seq]);
        $this->assertSame(1, IguideLabWorkflow::count());
        $successor = collect($this->queue->pushed(MonitorIguideLabTourRun::class))->last();
        $this->assertSame([$run->id, $first->token, 2], [$successor->runId, $successor->token, $successor->seq]);

        // A second chain cannot take over a live monitor.
        (new MonitorIguideLabTourRun($run->id))->handle(app(LabTourRuns::class));
        $this->assertCount(2, $this->queue->pushed(MonitorIguideLabTourRun::class));
        $this->assertSame($first->token, $run->fresh()->monitor_token);
        // The successor claims seq 2; a duplicate first attempt of the same (token, seq) fails the seq test and exits.
        $duplicate = clone $successor;
        $successor->handle(app(LabTourRuns::class));
        $this->assertSame(2, $run->fresh()->monitor_seq);
        $this->assertCount(3, $this->queue->pushed(MonitorIguideLabTourRun::class));
        $duplicate->handle(app(LabTourRuns::class));
        $this->assertSame(2, $run->fresh()->monitor_seq);
        $this->assertCount(3, $this->queue->pushed(MonitorIguideLabTourRun::class));
    }

    public function test_idle_backstop_stops_the_chain_but_a_get_still_kicks(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 1);
        [$run] = $this->createRun($analysis, 'twilight');
        $this->tick($run);
        $runs = app(LabTourRuns::class);
        $this->travel(5)->hours();
        $this->assertTrue($runs->needsMonitor($run->fresh(), false));
        IguideLabWorkflowItem::sole()->update(['status' => 'editing']);
        $this->travel(5)->hours();
        $this->assertTrue($runs->needsMonitor($run->fresh(), false), 'a bound item changed 5 h ago');
        $this->travel(61)->minutes();
        $this->assertFalse($runs->needsMonitor($run->fresh(), false), 'no bound item change for monitor_idle_hours (9.2 step 7b)');
        $this->assertTrue($runs->needsMonitor($run->fresh(), true), 'the GET lifetime rule has no idle backstop (9.8)');
        $pushed = count($this->queue->pushed(MonitorIguideLabTourRun::class));
        $runs->kickIfStale($run->fresh());
        $this->assertCount($pushed + 1, $this->queue->pushed(MonitorIguideLabTourRun::class));
    }

    // ------------------------------------------------------------------ helpers

    private function states(IguideLabTourRun $run, string $state): int
    {
        return IguideLabTourRunTarget::where('run_id', $run->id)->where('state', $state)->count();
    }

    private function target(IguideLabTourRun $run): array
    {
        $target = IguideLabTourRunTarget::where('run_id', $run->id)->sole();

        return [$target->state, $target->reasons, $target->has_attempt];
    }

    private function composite(string $caseId, ?IguideLabProject $project = null): string
    {
        $id = (string) Str::uuid();
        DB::table('iguide_lab_composites')->insert(['id' => $id, 'project_id' => ($project ?? $this->project)->id, 'case_id' => $caseId,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'label' => 'Fixture', 'preview_path' => 'fixture',
            'manifest' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** A chunk reserved and its workflow created by start(), and then the process died before Bind (9.4 step 7). */
    private function crashBetweenStartAndBind(IguideLabTourRun $run): IguideLabTourRunChunk
    {
        $this->workflows->override = fn () => throw new \RuntimeException('worker lost');
        $this->tick($run);
        $this->workflows->override = null;
        $chunk = IguideLabTourRunChunk::where('run_id', $run->id)->sole();
        $this->assertSame('reserved', $chunk->status);
        $project = IguideLabProject::findOrFail($run->project_id);
        (new LabWorkflows)->start($project, ['requestId' => $chunk->request_id,
            'items' => array_map(fn ($caseId) => ['caseId' => $caseId, 'preset' => $run->preset, 'style' => null, 'instructions' => ''], $chunk->case_ids)]);
        $this->assertSame('reserved', $chunk->fresh()->status);

        return $chunk;
    }

    private function assertCancelledRunWithBoundWorkflow(IguideLabTourAnalysis $analysis, IguideLabTourRun $run, IguideLabWorkflow $workflow): void
    {
        $chunk = IguideLabTourRunChunk::where('run_id', $run->id)->sole();
        $this->assertSame(['started', $workflow->id], [$chunk->status, $chunk->workflow_id]);
        $this->assertSame([$workflow->id], $this->workflows->cancelCalls, 'LabWorkflows::cancel called once');
        $this->assertNotNull($workflow->fresh()->cancelled_at);
        $fresh = $run->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull(app(LabTourRuns::class)->activeRunId($this->tourView($analysis), $run->preset));
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame([$workflow->id], array_column($dto['workflows'], 'id'));
        // The monitor keeps syncing the bound targets until the cancelled items are reflected, then stops.
        $this->assertFalse($this->tick($run));
        $dto = $this->runDto($run);
        $this->assertRunDtoValid($dto);
        $this->assertSame(['cancelled'], array_values(array_unique(array_column($dto['targets'], 'state'))));
        $this->assertSame($workflow->id, $dto['targets'][0]['workflowId']);
    }
}
