<?php

namespace Tests\Feature;

use App\Jobs\MonitorIguideLabTourAnalysis;
use App\Jobs\MonitorIguideLabTourRun;
use App\Jobs\ProcessIguideLabTourSurvey;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabTourSurveyAttempt;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourDefaults;
use App\Services\IguideLab\LabTourErrors;
use App\Services\IguideLab\LabTourView;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsTourContract;
use Tests\Support\TourEndToEndFixtures;
use Tests\TestCase;

/**
 * The integrated tour layer through its 8 HTTP routes with every tour service real (spec 13.6, 14.2 item 6): create,
 * survey, selection, runs, accept and cancel; replay and shared-record rules; every public error text and status; one
 * read snapshot per response; the GET kick. Every response passes the PHP mirror of the strict readers
 * (AssertsTourContract). The WP4 spies fail a test at teardown when LabWorkflowPolicy is read, or LabWorkflows started,
 * cancelled or stopped, inside a transaction; RecordingQueueFake fails it when a job is pushed inside one.
 */
class IguideLabTourEndToEndTest extends TestCase
{
    use AssertsTourContract;
    use RefreshDatabase;
    use TourEndToEndFixtures;

    /** Room name => survey response fixture. Golden hour ticks the exterior and the kitchen by default (6.2 G1, G8). */
    private const ROOMS = ['Front Exterior' => 'exterior-front', 'Main Kitchen' => 'kitchen', 'Walk-in Closet' => 'walk-in-closet', 'Back Den' => 'refusal'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTourEndToEnd();
    }

    protected function tearDown(): void
    {
        $this->tearDownTourEndToEnd();
        parent::tearDown();
    }

    public function test_create_survey_select_run_accept_and_cancel_through_the_real_services(): void
    {
        $import = $this->seedImport(self::ROOMS);
        $requestId = (string) Str::uuid();
        $create = ['requestId' => strtoupper($requestId), 'importId' => strtoupper($import->id), 'preset' => 'golden-hour'];

        // E2: 202 with a queued record; one new monitor chain pushed after the commit.
        $created = $this->tour('POST', 'tour-analyses', $create, 202);
        $id = $created->json('data.id');
        $this->assertSame(['queued', 1, $requestId, $import->id], [$created->json('data.status'), $created->json('data.recordRevision'),
            $created->json('data.requestId'), $created->json('data.importId')]);
        $this->assertSame(['total' => 4, 'surveyed' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 4], $created->json('data.counts'));
        $this->assertSame([null, null, null, null], array_column($created->json('data.presets'), 'estimate'));
        $this->assertCount(1, $this->queue->pushed(MonitorIguideLabTourAnalysis::class));

        // Replay (same requestId and import): 200 with the same record and no side effect. A new requestId for the same
        // import answers the one shared record (D9). A reused requestId for another import is a 409.
        $this->tour('POST', 'tour-analyses', $create, 200)->assertJsonPath('data.id', $id)->assertJsonPath('data.recordRevision', 1);
        $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'twilight'], 200)
            ->assertJsonPath('data.id', $id);
        $other = $this->seedImport(['Side Porch' => 'exterior-front']);
        $this->tourError('POST', 'tour-analyses', ['requestId' => $requestId, 'importId' => $other->id, 'preset' => 'golden-hour'], 'requestReused');
        $this->assertCount(1, $this->queue->pushed(MonitorIguideLabTourAnalysis::class));
        $this->assertSame(1, IguideLabTourAnalysis::count());

        $progress = $this->tour('GET', "tour-analyses/$id/progress");
        $this->assertSame(['min' => 1, 'max' => 1], $progress->json('data.etaMinutes'), 'ceil(4 x {1,2} / 8 workers)');

        // The workers: the monitor dispatches every capture (8 workers), each survey is paid once and parsed for free.
        $this->workTourQueue();
        $this->assertEqualsCanonicalizing(array_keys(self::ROOMS), $this->providerCalls);
        $this->assertSame('Front Exterior', $this->providerCalls[0], 'exterior captures go first for golden hour (9.7)');
        $this->assertSame(4, IguideLabTourSurveyAttempt::count());
        $this->assertCount(4, $this->queue->pushed(ProcessIguideLabTourSurvey::class));

        $ready = $this->tour('GET', "tour-analyses/$id");
        $data = $ready->json('data');
        $this->assertSame('ready', $data['status']);
        $this->assertSame(['total' => 4, 'surveyed' => 3, 'failed' => 1, 'skipped' => 0, 'pending' => 0], $data['counts']);
        $this->assertSame([null, null, null, 'survey_refused'], array_column($data['captures'], 'error'));
        $this->assertGreaterThan(1, $data['recordRevision']);
        $cases = array_column($data['captures'], 'caseId');
        $ticked = fn (array $capture, string $preset) => array_values(array_map(fn ($c) => $c['id'], array_filter($capture['candidates'],
            fn ($c) => $c['preset'] === $preset && $c['defaultSelected'])));
        $this->assertSame([['g0'], ['g1'], [], []], array_map(fn ($c) => $ticked($c, 'golden-hour'), $data['captures']));
        $this->assertSame([[], ['t1'], [], []], array_map(fn ($c) => $ticked($c, 'twilight'), $data['captures']));
        // D1: lawn areas and declutter items are listed but never executable.
        foreach ($data['captures'] as $capture) foreach ($capture['candidates'] as $candidate) {
            if (in_array($candidate['preset'], ['grass', 'declutter'], true)) $this->assertSame([false, false, 'preset_runs_not_enabled'],
                [$candidate['executable'], $candidate['defaultSelected'], $candidate['reasons'][0]]);
        }
        $this->assertSame(['selectionRevision' => 0, 'roomsSelected' => 2, 'itemsSelected' => 2, 'roomsThatFit' => 2],
            array_slice($data['presets']['golden-hour']['estimate'], 0, 4));
        $this->assertNull($data['presets']['golden-hour']['estimate']['limitingReason']);

        // E5: a write replaces the capture's delta; a stale base revision is a 409; an empty entry resets the capture.
        Cache::flush();
        $this->policy->calls = [];
        $write = $this->tour('POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 0,
            'changes' => [['caseId' => strtoupper($cases[1]), 'add' => [], 'remove' => ['g1']]]]);
        $this->assertSame([1, [['caseId' => $cases[1], 'add' => [], 'remove' => ['g1']]], ['rooms' => 1, 'items' => 1], 1],
            [$write->json('data.selection.revision'), $write->json('data.selection.changes'), $write->json('data.counts'), $write->json('data.estimate.roomsSelected')]);
        $this->assertNotEmpty($this->policy->calls, 'the selection facts read the policy (before the write transaction)');
        $this->tourError('POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 0,
            'changes' => [['caseId' => $cases[0], 'add' => [], 'remove' => ['g0']]]], 'staleSelection');
        $reset = $this->tour('POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 1,
            'changes' => [['caseId' => $cases[1], 'add' => [], 'remove' => []]]]);
        $this->assertSame([2, [], 2], [$reset->json('data.selection.revision'), $reset->json('data.selection.changes'), $reset->json('data.counts.rooms')]);

        // E6: 202 creating with no workflow yet; replay 200; a reused requestId with another body 409; a second run of the
        // same preset while the first is active 409.
        $record = $this->tour('GET', "tour-analyses/$id");
        $selection = $record->json('data.presets.golden-hour.selection');
        $runRequest = (string) Str::uuid();
        $runBody = ['requestId' => strtoupper($runRequest), 'preset' => 'golden-hour', 'selectionRevision' => 2, 'selectionSha256' => $selection['sha256']];
        $run = $this->tour('POST', "tour-analyses/$id/runs", $runBody, 202);
        $runId = $run->json('data.id');
        $this->assertSame(['creating', [], ['waiting', 'waiting'], $runRequest], [$run->json('data.status'), $run->json('data.workflows'),
            array_column($run->json('data.targets'), 'state'), $run->json('data.requestId')]);
        $this->tour('POST', "tour-analyses/$id/runs", $runBody, 200)->assertJsonPath('data.id', $runId);
        $twilight = $record->json('data.presets.twilight.selection');
        $this->tourError('POST', "tour-analyses/$id/runs", ['requestId' => $runRequest, 'preset' => 'twilight', 'selectionRevision' => 0,
            'selectionSha256' => $twilight['sha256']], 'requestReused');
        $this->tourError('POST', "tour-analyses/$id/runs", ['requestId' => (string) Str::uuid()] + $runBody, 'duplicateActiveRun');
        $this->assertSame($runId, $this->tour('GET', "tour-analyses/$id")->json('data.presets.golden-hour.activeRunId'));
        $this->assertCount(1, $this->queue->pushed(MonitorIguideLabTourRun::class));

        // The run monitor starts chunk 1 through the real LabWorkflows::start() at the baseline level, then syncs targets.
        $this->assertSame(1, $this->workRunMonitors());
        $this->assertCount(1, $this->workflows->startCalls);
        $running = $this->tour('GET', "tour-analyses/$id")->json('data.runs.0');
        $this->assertSame(['running', ['queued', 'queued']], [$running['status'], array_column($running['targets'], 'state')]);
        $this->assertCount(1, $running['workflows']);
        $workflowId = $running['workflows'][0]['id'];
        $this->assertSame([$cases[0], $cases[1]], $running['workflows'][0]['caseIds']);
        $workflow = $this->api('GET', "workflows/$workflowId")->assertOk();
        $this->assertSame([$workflowId, [$cases[0], $cases[1]], [false, false]], [$workflow->json('data.id'), array_column($workflow->json('data.items'), 'caseId'),
            array_column(array_column($workflow->json('data.items'), 'automation'), 'manualAcceptance')]);
        $this->tour('GET', "tour-analyses/$id/progress")->assertJsonPath('data.runs.0.rooms', 2)->assertJsonPath('data.runs.0.done', 0);

        $this->finishItems($workflowId, [$cases[0] => 'ready', $cases[1] => 'held']);
        $this->workRunMonitors();
        $finished = $this->tour('GET', "tour-analyses/$id");
        $this->assertSame(['completed', 1, 1, null], [$finished->json('data.runs.0.status'), $finished->json('data.runs.0.counts.ready'),
            $finished->json('data.runs.0.counts.held'), $finished->json('data.presets.golden-hour.activeRunId')]);

        // E7: accept only a held room with an attempt; repeat is a no-op; Undo clears it; unknown run or room is a 404.
        $workflowDto = $this->api('GET', "workflows/$workflowId")->assertOk()->getContent();
        $this->tourError('POST', "tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[0], 'accepted' => true], 'notHeld');
        $accepted = $this->tour('POST', "tour-analyses/$id/accept", ['runId' => strtoupper($runId), 'caseId' => strtoupper($cases[1]), 'accepted' => true]);
        $this->assertSame([1, ['acceptedAt' => now()->toIso8601String()]], [$accepted->json('data.counts.accepted'), $accepted->json('data.targets.1.acceptance')]);
        $revision = $this->tour('GET', "tour-analyses/$id")->json('data.recordRevision');
        $this->tour('POST', "tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[1], 'accepted' => true]);
        $this->assertSame($revision, $this->tour('GET', "tour-analyses/$id")->json('data.recordRevision'), 'a repeated accept changes nothing');
        $undo = $this->tour('POST', "tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[1], 'accepted' => false]);
        $this->assertSame([0, null], [$undo->json('data.counts.accepted'), $undo->json('data.targets.1.acceptance')]);
        $this->tour('POST', "tour-analyses/$id/accept", ['runId' => (string) Str::uuid(), 'caseId' => $cases[1], 'accepted' => true], 404);
        $this->tour('POST', "tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[2], 'accepted' => true], 404);
        $this->assertSame($workflowDto, $this->api('GET', "workflows/$workflowId")->assertOk()->getContent(), 'accept never touches the workflow (D13)');

        // E8: cancel of a finished record with a finished run changes nothing and is idempotent.
        $before = $this->tour('GET', "tour-analyses/$id")->json('data');
        $first = $this->tour('POST', "tour-analyses/$id/cancel", []);
        $second = $this->tour('POST', "tour-analyses/$id/cancel", []);
        $this->assertSame([$before['status'], $before['recordRevision']], [$first->json('data.status'), $first->json('data.recordRevision')]);
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame([], $this->workflows->cancelCalls);

        $this->assertNotEmpty($this->policy->calls);
        $this->assertSame([], array_values(array_filter($this->policy->calls, fn ($call) => $call['level'] !== $this->policy->baselineLevel)));
        $this->assertSame([$this->queue->baselineLevel], array_values(array_unique($this->queue->levels)));
    }

    public function test_cancel_while_analysing_frees_unsent_surveys_and_create_resumes_the_record(): void
    {
        $import = $this->seedImport(['Main Kitchen' => 'kitchen', 'Walk-in Closet' => 'walk-in-closet']);
        $id = $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'twilight'], 202)->json('data.id');
        // Only the monitor runs: both captures are surveying and their surveys queued, nothing is sent yet.
        foreach ($this->queue->pushed(MonitorIguideLabTourAnalysis::class) as $job) $this->runQueued($job, fn ($job) => $job->handle(app(LabTourAnalyses::class)));
        $analysing = $this->tour('GET', "tour-analyses/$id");
        $this->assertSame(['analysing', 2], [$analysing->json('data.status'), $analysing->json('data.counts.pending')]);

        $cancelled = $this->tour('POST', "tour-analyses/$id/cancel", []);
        $this->assertSame(['cancelled', ['cancelled', 'cancelled']], [$cancelled->json('data.status'), array_column($cancelled->json('data.captures'), 'error')]);
        $this->assertSame($analysing->json('data.recordRevision') + 1, $cancelled->json('data.recordRevision'));
        $this->assertSame($cancelled->json('data'), $this->tour('POST', "tour-analyses/$id/cancel", [])->json('data'), 'cancel is idempotent');
        $this->assertSame(['cancelled', 'cancelled'], IguideLabTourSurvey::orderBy('created_at')->pluck('status')->all(), 'queued surveys without an attempt are cancelled for free');

        // The already queued survey jobs find nothing to do: no provider call.
        $this->workTourQueue();
        $this->assertSame([], $this->providerCalls);

        // A UI create resumes the cancelled record (D9): 202, captures pending again, a new monitor chain.
        $resumed = $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'twilight'], 202);
        $this->assertSame([$id, 'queued', null], [$resumed->json('data.id'), $resumed->json('data.status'), $resumed->json('data.completedAt')]);
        // New chains (no seq): the create and the resume; the first tick's successor is the only other monitor push.
        $this->assertCount(2, $this->queue->pushed(MonitorIguideLabTourAnalysis::class)->filter(fn ($job) => $job->seq === null));
        $this->workTourQueue();
        $this->assertEqualsCanonicalizing(['Main Kitchen', 'Walk-in Closet'], $this->providerCalls, 'the relinked surveys are sent once each');
        $this->assertSame(['ready', 2], [$this->tour('GET', "tour-analyses/$id")->json('data.status'), $this->tour('GET', "tour-analyses/$id")->json('data.counts.surveyed')]);
    }

    public function test_every_public_error_text_and_status_through_http(): void
    {
        // E2 errors.
        $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => (string) Str::uuid(), 'preset' => 'golden-hour'], 404)
            ->assertJsonPath('message', 'The requested item was not found.');
        $processing = $this->seedImport(['North Yard' => 'exterior-front'], status: 'processing');
        $this->tourError('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $processing->id, 'preset' => 'golden-hour'], 'importNotReady');
        $three = $this->seedImport(['Room One A' => 'kitchen', 'Room Two A' => 'kitchen', 'Room Three A' => 'kitchen']);
        config(['iguide-lab-tour.caps.max_captures' => 2]);
        $this->tourError('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $three->id, 'preset' => 'golden-hour'], 'tour_too_many_captures',
            'This tour has more than 2 photos.');
        config(['iguide-lab-tour.caps.max_captures' => 80, 'iguide-lab-tour.caps.survey_calls_per_project' => 2]);
        $this->tourError('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $three->id, 'preset' => 'golden-hour'], 'surveyCapReached');
        config(['iguide-lab-tour.caps.survey_calls_per_project' => 240]);
        $this->assertSame(0, IguideLabTourAnalysis::count(), 'refused creates change nothing');

        // E6 before the analysis is ready.
        $import = $this->seedImport(self::ROOMS);
        $id = $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'golden-hour'], 202)->json('data.id');
        $this->tourError('POST', "tour-analyses/$id/runs", ['requestId' => (string) Str::uuid(), 'preset' => 'golden-hour', 'selectionRevision' => 0,
            'selectionSha256' => str_repeat('a', 64)], 'analysisNotReady');
        $this->workTourQueue();
        $record = $this->tour('GET', "tour-analyses/$id")->json('data');
        $cases = array_column($record['captures'], 'caseId');

        // E5 errors: request guards first, then the service rules on the real candidates.
        $selection = fn (array $changes, int $base = 0) => ['preset' => 'golden-hour', 'baseRevision' => $base, 'changes' => $changes];
        $this->tourError('POST', "tour-analyses/$id/selection", $selection([['caseId' => $cases[3], 'add' => [], 'remove' => ['g3']]]), 'tour_capture_not_surveyed',
            'A selected room has not been analysed yet.');
        $this->tourError('POST', "tour-analyses/$id/selection", ['preset' => 'twilight', 'baseRevision' => 0, 'changes' => [['caseId' => $cases[0], 'add' => ['t0'], 'remove' => []]]],
            'tour_candidate_not_selectable', 'A selected item is not available for this preset.');
        $this->tourError('POST', "tour-analyses/$id/selection", $selection([['caseId' => $cases[2], 'add' => [], 'remove' => ['g2']]]), 'tour_candidate_not_default',
            'Only items that are selected by default can be removed.');
        $this->tourError('POST', "tour-analyses/$id/selection", $selection([['caseId' => $cases[0], 'add' => [], 'remove' => ['g0']], ['caseId' => strtoupper($cases[0]), 'add' => [], 'remove' => []]]),
            'tour_duplicate_change', 'Each room can appear only once, and an item cannot be both added and removed.');
        $this->tourError('POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => '0', 'changes' => [['caseId' => $cases[0], 'add' => [], 'remove' => ['g0']]]],
            'tour_invalid_type', 'The request has an invalid value.');
        $this->tourError('POST', "tour-analyses/$id/selection", $selection([['caseId' => $cases[0], 'add' => [], 'remove' => ['g0']]]) + ['note' => 'x'],
            'tour_unsupported_field', 'This request has an unsupported field.');
        $this->tourError('POST', "tour-analyses/$id/selection", $selection([['caseId' => $cases[0], 'add' => [], 'remove' => ['g0'], 'pad' => str_repeat('x', 16384)]]),
            'tour_body_too_large', 'The request is too large.');
        $this->assertSame(0, IguideLabTourAnalysis::findOrFail($id)->selections['golden-hour']['revision'], 'refused selections change nothing');

        // E6 errors, in the order of 9.1.
        $this->travel(61)->seconds(); // a fresh write-throttle minute (30 writes per minute per token)
        $record = $this->tour('GET', "tour-analyses/$id")->json('data');
        $golden = $record['presets']['golden-hour']['selection'];
        $body = fn (array $over = []) => $over + ['requestId' => (string) Str::uuid(), 'preset' => 'golden-hour', 'selectionRevision' => 0, 'selectionSha256' => $golden['sha256']];
        config(['iguide-lab-tour.tour_runs_paused' => true]);
        $this->tourError('POST', "tour-analyses/$id/runs", $body(), 'runsPaused');
        config(['iguide-lab-tour.tour_runs_paused' => false, 'iguide-lab-tour.grass_runs_enabled' => true]);
        $this->tourError('POST', "tour-analyses/$id/runs", $body(['preset' => 'grass', 'selectionSha256' => $record['presets']['grass']['selection']['sha256']]), 'presetNotRunnable');
        $this->tourError('POST', "tour-analyses/$id/runs", $body(['selectionRevision' => 5]), 'staleSelection');
        $this->tourError('POST', "tour-analyses/$id/runs", $body(['selectionSha256' => str_repeat('0', 64)]), 'selectionMismatch');
        $this->tour('POST', "tour-analyses/$id/runs", ['requestId' => strtoupper((string) Str::uuid())] + $body(), 202);
        $this->tourError('POST', "tour-analyses/$id/runs", $body(), 'duplicateActiveRun');
        $twilight = $body(['preset' => 'twilight', 'selectionSha256' => $record['presets']['twilight']['selection']['sha256']]);
        config(['iguide-lab-tour.caps.max_runs_per_analysis' => 1]);
        $this->tourError('POST', "tour-analyses/$id/runs", $twilight, 'runLimitReached');
        config(['iguide-lab-tour.caps.max_runs_per_analysis' => 40]);
        IguideLabProject::whereKey($this->project->id)->update(['used_runs' => DB::raw('max_runs')]);
        $this->tourError('POST', "tour-analyses/$id/runs", $twilight, 'nothingFits');
        $this->tourError('POST', "tour-analyses/$id/runs", $twilight + ['pad' => str_repeat('x', 4096)], 'tour_body_too_large', 'The request is too large.');
        $this->tourError('POST', "tour-analyses/$id/runs", ['selectionRevision' => 1.5] + $twilight, 'validation_failed', null);

        // E7 and E8 errors.
        $runId = $this->tour('GET', "tour-analyses/$id")->json('data.runs.0.id');
        $this->tourError('POST', "tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[0], 'accepted' => true], 'notHeld');
        $this->tourError('POST', "tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[0], 'accepted' => 1], 'tour_invalid_type', 'The request has an invalid value.');
        $this->tourError('POST', "tour-analyses/$id/cancel", ['force' => true], 'tour_unsupported_field', 'This request has an unsupported field.');
        $this->tour('GET', 'tour-analyses/'.Str::uuid(), null, 404)->assertJsonPath('message', 'The requested item was not found.');
    }

    public function test_get_kicks_a_stale_monitor_once_and_progress_is_read_only_while_fresh(): void
    {
        $import = $this->seedImport(['Main Kitchen' => 'kitchen', 'Front Exterior' => 'exterior-front']);
        $id = $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'golden-hour'], 202)->json('data.id');
        $this->assertCount(1, $this->queue->pushed(MonitorIguideLabTourAnalysis::class));

        DB::enableQueryLog();
        $this->tour('GET', "tour-analyses/$id/progress");
        $this->tour('GET', "tour-analyses/$id");
        $statements = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter($statements, fn ($sql) => ! preg_match('/\A\s*(select|savepoint|release savepoint)\b/i', $sql))),
            'GETs write nothing while the monitor is fresh');
        $this->assertCount(1, $this->queue->pushed(MonitorIguideLabTourAnalysis::class));

        // Stale (no heartbeat 5 minutes after create, no pending job): one new chain, then the per-minute gate holds.
        $this->travel(301)->seconds();
        $this->tour('GET', "tour-analyses/$id");
        $this->tour('GET', "tour-analyses/$id/progress");
        $kicks = $this->queue->pushed(MonitorIguideLabTourAnalysis::class)->values();
        $this->assertCount(2, $kicks);
        $this->assertNull($kicks[1]->seq, 'a kick starts a new chain');
        // A pending job of this monitor in the jobs table means "waiting", not dead: no kick even after the gate.
        $this->travel(61)->seconds();
        DB::table('jobs')->insert(['queue' => 'studio', 'payload' => json_encode(['displayName' => MonitorIguideLabTourAnalysis::class, 'data' => ['analysisId' => $id]]),
            'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        $this->tour('GET', "tour-analyses/$id");
        $this->assertCount(2, $this->queue->pushed(MonitorIguideLabTourAnalysis::class));
        DB::table('jobs')->delete();

        // The run monitor is kicked the same way.
        $this->workTourQueue();
        $record = $this->tour('GET', "tour-analyses/$id")->json('data');
        $this->tour('POST', "tour-analyses/$id/runs", ['requestId' => (string) Str::uuid(), 'preset' => 'golden-hour', 'selectionRevision' => 0,
            'selectionSha256' => $record['presets']['golden-hour']['selection']['sha256']], 202);
        $this->assertCount(1, $this->queue->pushed(MonitorIguideLabTourRun::class));
        $this->tour('GET', "tour-analyses/$id/progress");
        $this->assertCount(1, $this->queue->pushed(MonitorIguideLabTourRun::class));
        $this->travel(301)->seconds();
        $this->tour('GET', "tour-analyses/$id/progress");
        $this->tour('GET', "tour-analyses/$id");
        $this->assertCount(2, $this->queue->pushed(MonitorIguideLabTourRun::class));
    }

    public function test_record_and_progress_are_built_from_one_snapshot_by_the_real_services(): void
    {
        $import = $this->seedImport(['Main Kitchen' => 'kitchen', 'Front Exterior' => 'exterior-front', 'Walk-in Closet' => 'walk-in-closet']);
        $id = $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'golden-hour'], 202)->json('data.id');
        $this->workTourQueue();
        $ready = IguideLabTourAnalysis::findOrFail($id);
        $last = IguideLabTourCapture::where('analysis_id', $id)->where('position', 2)->firstOrFail();
        $this->assertSame(['ready', 'surveyed'], [$ready->status, $last->status]);
        // The real end state of the last capture and the record, and the same record one monitor commit earlier.
        $columns = ['status', 'survey', 'applicability', 'candidates', 'defaults_version', 'defaults_flags', 'surveyed_at'];
        $finished = (array) DB::table('iguide_lab_tour_captures')->where('id', $last->id)->first($columns);
        $record = (array) DB::table('iguide_lab_tour_analyses')->where('id', $id)->first(['record_revision', 'completed_at', 'updated_at']);
        $set = fn (array $values) => implode(', ', array_map(fn ($column, $value) => $column.' = '.($value === null ? 'NULL' : DB::getPdo()->quote((string) $value)),
            array_keys($values), $values));
        $finish = 'UPDATE iguide_lab_tour_captures SET '.$set($finished)." WHERE id = '{$last->id}';"
            .' UPDATE iguide_lab_tour_analyses SET '.$set(['status' => 'ready'] + $record)." WHERE id = '$id';";
        $restart = 'UPDATE iguide_lab_tour_captures SET '.$set(['status' => 'surveying'] + array_fill_keys(array_slice($columns, 1), null))." WHERE id = '{$last->id}';"
            .' UPDATE iguide_lab_tour_analyses SET '.$set(['status' => 'analysing', 'completed_at' => null, 'record_revision' => $record['record_revision'] - 1,
                'monitor_heartbeat_at' => now()->format('Y-m-d H:i:s')])." WHERE id = '$id';";

        $this->withWalDatabase(function (\PDO $writer) use ($id, $finish, $restart, $record) {
            foreach (["tour-analyses/$id", "tour-analyses/$id/progress"] as $path) {
                $writer->exec($restart);
                $hook = $this->commitInsideTheSnapshot($writer, $finish);
                $before = $this->tour('GET', $path);
                $this->assertTrue($hook->fired, "$path: the monitor commit ran between two loader queries");
                $this->assertTrue(collect($hook->after)->contains(fn ($sql) => str_contains($sql, 'iguide_lab_tour_captures')), "$path: captures were read after the commit");
                $this->assertSame(['analysing', 1, $record['record_revision'] - 1], [$before->json('data.status'), $before->json('data.counts.pending'),
                    $before->json('data.recordRevision')], "$path is entirely from before the commit");
                $hook->armed = false;
                $after = $this->tour('GET', $path);
                $this->assertSame(['ready', 0, $record['record_revision']], [$after->json('data.status'), $after->json('data.counts.pending'),
                    $after->json('data.recordRevision')], "$path is entirely from after the commit");
            }
        });
    }

    /** 9.9 with the real LabTourSurveys::sweep() and the real monitor jobs: background recovery without client polling (D4). */
    public function test_scheduled_sweeper_restarts_a_dead_chain_and_redispatches_a_lost_survey(): void
    {
        $import = $this->seedImport(['Main Kitchen' => 'kitchen']);
        $id = $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'golden-hour'], 202)->json('data.id');
        $sweep = function (): array {
            $this->assertSame(0, Artisan::call('iguide-lab:tour-monitors'));

            return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        };
        // The first tick claims the chain and dispatches the survey; its job is "lost" (never run).
        foreach ($this->queue->pushed(MonitorIguideLabTourAnalysis::class) as $job) $this->runQueued($job, fn ($job) => $job->handle(app(LabTourAnalyses::class)));
        $this->assertCount(1, $this->queue->pushed(ProcessIguideLabTourSurvey::class));
        $this->assertSame(['version' => 'tour-monitor-sweep/1', 'surveysChanged' => false, 'analysesKicked' => 0, 'runsKicked' => 0], $sweep(), 'a fresh chain is left alone');

        // The successor tick was lost too: after 5 minutes the sweeper starts a new chain.
        $this->travel(301)->seconds();
        $this->assertSame(['version' => 'tour-monitor-sweep/1', 'surveysChanged' => false, 'analysesKicked' => 1, 'runsKicked' => 0], $sweep());
        $this->assertNull($this->queue->pushed(MonitorIguideLabTourAnalysis::class)->last()->seq);

        // 15 minutes after its dispatch, the queued survey without a pending job is pushed again (bounded, free).
        $this->travel(600)->seconds();
        $this->assertTrue($sweep()['surveysChanged']);
        $this->assertCount(2, $this->queue->pushed(ProcessIguideLabTourSurvey::class));
        $this->assertSame(2, IguideLabTourSurvey::sole()->dispatch_count);
        $this->workTourQueue();
        $this->assertSame(['Main Kitchen'], $this->providerCalls, 'the re-dispatched survey is sent once');
        $this->tour('GET', "tour-analyses/$id")->assertJsonPath('data.status', 'ready');

        config(['iguide-lab-tour.tour_analysis_enabled' => false]);
        $pushes = count($this->queue->pushedJobs(), COUNT_RECURSIVE);
        $this->assertSame(['version' => 'tour-monitor-sweep/1', 'surveysChanged' => false, 'analysesKicked' => 0, 'runsKicked' => 0], $sweep());
        $this->assertSame($pushes, count($this->queue->pushedJobs(), COUNT_RECURSIVE), 'flag off: no work');
    }

    /** 13.3 test_exterior_none_lawn_drops_the_condition, with the real LabTourDefaults and the record reader. */
    public function test_exterior_answer_without_lawn_builds_a_contract_capture(): void
    {
        $import = $this->seedImport(['Back Garden Exterior' => 'exterior-lawn-none-condition']);
        $id = $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'grass'], 202)->json('data.id');
        $this->workTourQueue();
        $capture = $this->tour('GET', "tour-analyses/$id")->assertJsonPath('data.status', 'ready')->json('data.captures.0');
        $this->assertSame(['status' => 'none', 'condition' => null, 'areas' => []], $capture['survey']['lawn']);
        $this->assertSame(['not_applicable', ['no_lawn_found']], [$capture['applicability']['grass']['status'], $capture['applicability']['grass']['reasons']]);
        $this->assertSame([], array_values(array_filter($capture['candidates'], fn ($c) => $c['preset'] === 'grass')));
        app(LabTourDefaults::class)->assertCapture($capture);
        $this->assertTourContract('capture', $capture);
        $this->assertSame(['lawn_condition_dropped'], IguideLabTourSurvey::sole()->result['warnings']);
    }

    /** 13.3 test_reparse_turns_failed_captures_surveyed_and_a_failed_analysis_ready, with the real LabTourAnalyses (5.13). */
    public function test_free_reparse_turns_a_failed_record_ready_through_the_real_services(): void
    {
        $envelope = json_decode(file_get_contents(base_path('tests/Fixtures/iguide-lab/tour/responses/kitchen.json')), true);
        $envelope['metadata'] = ['padding' => str_repeat('y', 560 * 1024)];
        $import = $this->seedImport(['Main Kitchen' => json_encode($envelope)]);
        $id = $this->tour('POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'golden-hour'], 202)->json('data.id');
        $this->workTourQueue();
        $failed = $this->tour('GET', "tour-analyses/$id")->json('data');
        $this->assertSame(['failed', 'failed', 'survey_too_large'], [$failed['status'], $failed['captures'][0]['status'], $failed['captures'][0]['error']]);
        $this->assertNotNull($failed['completedAt']);
        $survey = IguideLabTourSurvey::sole();

        // The stored answer is re-read for free once the limit allows it (D6): no provider call, no attempt row.
        config(['iguide-lab-tour.survey.max_response_bytes' => 1024 * 1024]);
        $this->travel(10)->seconds();
        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => $survey->id])->expectsOutputToContain('"capturesUpdated":1')->assertSuccessful();

        $ready = $this->tour('GET', "tour-analyses/$id")->json('data');
        $this->assertSame(['ready', 'surveyed', null], [$ready['status'], $ready['captures'][0]['status'], $ready['captures'][0]['error']]);
        $this->assertSame($failed['completedAt'], $ready['completedAt'], 'completedAt is kept (5.13 step 4)');
        $this->assertSame($failed['recordRevision'] + 1, $ready['recordRevision'], 'one revision bump');
        $this->assertSame(['g0'], array_column(array_filter($ready['captures'][0]['candidates'], fn ($c) => $c['preset'] === 'golden-hour' && $c['defaultSelected']), 'id'));
        $this->assertSame(['Main Kitchen'], $this->providerCalls);
        $this->assertSame(1, IguideLabTourSurveyAttempt::count());
    }

    // ------------------------------------------------------------------ helpers

    /** A tour route answer with the expected status that passes the reader mirror. */
    private function tour(string $method, string $path, ?array $body = null, int $status = 200): TestResponse
    {
        $response = $this->api($method, $path, $method === 'GET' ? null : ($body ?? []));
        $response->assertStatus($status);
        $this->assertTourResponse($response, $method, $path);

        return $response;
    }

    /** A public error: a contract key of LabTourErrors::MESSAGES, or a backend code with its exact text. */
    private function tourError(string $method, string $path, array $body, string $keyOrCode, ?string $text = null): TestResponse
    {
        if (isset(LabTourErrors::MESSAGES[$keyOrCode])) {
            [$status, $code, $text] = LabTourErrors::MESSAGES[$keyOrCode];
        } else {
            $code = $keyOrCode;
            $status = $code === 'tour_body_too_large' ? 413 : 422;
        }
        $response = $this->tour($method, $path, $body, $status)->assertJsonPath('code', $code);
        if ($text !== null) $response->assertJsonPath('message', $text);

        return $response;
    }

    /** Runs $test with the default connection on a WAL copy of every table, and a second writer on that file. */
    private function withWalDatabase(callable $test): void
    {
        $directory = sys_get_temp_dir().'/tour-e2e-snapshot-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $file = $directory.'/tour.sqlite';
        $writer = new \PDO('sqlite:'.$file);
        $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA journal_mode=WAL');
        // Every table first, then indexes, triggers and views (some refer to other tables); foreign keys stay off while copying.
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
            ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 ELSE 2 END, rowid") as $ddl) $writer->exec($ddl->sql);
        $tables = array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name');
        foreach ($tables as $table) {
            foreach (DB::table($table)->get() as $row) {
                $row = (array) $row;
                $writer->prepare('INSERT INTO "'.$table.'" ("'.implode('","', array_keys($row)).'") VALUES ('.implode(',', array_fill(0, count($row), '?')).')')
                    ->execute(array_values($row));
            }
        }
        config(['database.connections.tour_e2e_snapshot' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 5000, 'journal_mode' => 'wal']]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('tour_e2e_snapshot');
        try {
            $test($writer);
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('tour_e2e_snapshot');
            $writer = null;
            foreach (glob($directory.'/*') ?: [] as $path) @unlink($path);
            @rmdir($directory);
        }
    }

    /**
     * Commits $sql through a second connection right after the analyses read of LabTourView::load() (found on the call
     * stack, so the hook does not depend on how the code frames its reads; the controller lookup and the GET kick also
     * read the analysis). A loader that did not hold one snapshot would read the captures after the commit and mix the
     * two states.
     */
    private function commitInsideTheSnapshot(\PDO $writer, string $sql): object
    {
        $hook = new class
        {
            public bool $armed = true;
            public bool $fired = false;
            /** @var list<string> */
            public array $after = [];
        };
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($hook, $writer, $sql) {
            if (! $hook->armed || $query->connectionName !== 'tour_e2e_snapshot') return;
            if ($hook->fired) { $hook->after[] = $query->sql; return; }
            if (! preg_match('/\A\s*select .* from "iguide_lab_tour_analyses"/is', $query->sql)) return;
            $inLoader = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->contains(fn ($frame) => ($frame['class'] ?? null) === LabTourView::class && $frame['function'] === 'load');
            if (! $inLoader) return;
            $hook->fired = true;
            $writer->exec('BEGIN IMMEDIATE');
            $writer->exec($sql);
            $writer->exec('COMMIT');
        });

        return $hook;
    }
}
