<?php

namespace Tests\Feature;

use App\Jobs\MonitorIguideLabTourAnalysis;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourErrors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsTourContract;
use Tests\Support\TourEndToEndFixtures;
use Tests\TestCase;

/**
 * Backend DTO export for tour-contract/check-contract.ts part (g) (spec 12.3, 13.6, 13.8), with every tour service real
 * (TourEndToEndFixtures). Every success and error body passes AssertsTourContract, the PHP mirror of the strict
 * readers, which CI runs. Only when TOUR_DTO_EXPORT_DIR is set is each body also written as
 * {kind, method, path, status, request, body} (path relative to /api/iguide-lab/), so CI, which has no export
 * directory, only validates.
 */
class IguideLabTourContractExportTest extends TestCase
{
    use AssertsTourContract;
    use RefreshDatabase;
    use TourEndToEndFixtures;

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

    public function test_every_endpoint_dto_matches_the_contract_mirror(): void
    {
        $import = $this->seedImport(self::ROOMS);
        $other = $this->seedImport(['Side Porch' => 'exterior-front', 'Guest Bath' => 'kitchen']);

        // Capabilities off and on, and the guards that answer before any service runs.
        config(['iguide-lab-tour.tour_analysis_enabled' => false]);
        $this->export('capabilities-disabled', 'GET', 'tour-analyses', null, 200);
        $this->export('error-404-disabled-route', 'GET', 'tour-analyses/'.Str::uuid(), null, 404);
        config(['iguide-lab-tour.tour_analysis_enabled' => true]);
        $this->export('error-401-no-token', 'GET', 'tour-analyses', null, 401, token: '');
        $this->export('error-404-unknown-record', 'GET', 'tour-analyses/'.Str::uuid(), null, 404);

        // E2: 202 queued, 200 replay, 200 shared record, and its errors.
        $requestId = (string) Str::uuid();
        $create = ['requestId' => $requestId, 'importId' => $import->id, 'preset' => 'golden-hour'];
        $id = $this->export('create-202-queued', 'POST', 'tour-analyses', $create, 202)->json('data.id');
        $this->export('create-200-replay', 'POST', 'tour-analyses', $create, 200);
        $this->export('create-200-shared-record', 'POST', 'tour-analyses', ['requestId' => (string) Str::uuid()] + $create, 200);
        $this->export('error-404-unknown-import', 'POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => (string) Str::uuid()] + $create, 404);
        $this->exportError('error-409-request-reused', 'POST', 'tour-analyses', ['importId' => $other->id] + $create, 'requestReused');
        $processing = $this->seedImport(['North Yard' => 'exterior-front'], status: 'processing');
        $this->exportError('error-409-import-not-ready', 'POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $processing->id, 'preset' => 'golden-hour'], 'importNotReady');
        config(['iguide-lab-tour.caps.max_captures' => 1]);
        $this->exportError('error-422-too-many-captures', 'POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $other->id, 'preset' => 'twilight'],
            'tour_too_many_captures');
        config(['iguide-lab-tour.caps.max_captures' => 80, 'iguide-lab-tour.caps.survey_calls_per_project' => 1]);
        $this->exportError('error-429-survey-cap', 'POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $other->id, 'preset' => 'twilight'], 'surveyCapReached');
        config(['iguide-lab-tour.caps.survey_calls_per_project' => 240]);
        $this->export('error-422-validation', 'POST', 'tour-analyses', ['requestId' => 'not-a-uuid'] + $create, 422);
        $this->export('record-queued', 'GET', "tour-analyses/$id", null, 200);
        $this->export('progress-queued', 'GET', "tour-analyses/$id/progress", null, 200);

        // Analysing: only the monitor has run, so every capture is surveying.
        foreach ($this->queue->pushed(MonitorIguideLabTourAnalysis::class) as $job) $this->runQueued($job, fn ($job) => $job->handle(app(LabTourAnalyses::class)));
        $this->export('record-analysing', 'GET', "tour-analyses/$id", null, 200)->assertJsonPath('data.status', 'analysing');
        $this->export('progress-analysing', 'GET', "tour-analyses/$id/progress", null, 200);
        $this->exportError('error-409-analysis-not-ready', 'POST', "tour-analyses/$id/runs", ['requestId' => (string) Str::uuid(), 'preset' => 'golden-hour',
            'selectionRevision' => 0, 'selectionSha256' => str_repeat('a', 64)], 'analysisNotReady');

        // A second record cancelled while queued: its captures are skipped with error cancelled.
        $second = $this->export('create-202-second-import', 'POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $other->id, 'preset' => 'twilight'], 202)
            ->json('data.id');
        $this->export('cancel-queued', 'POST', "tour-analyses/$second/cancel", [], 200)->assertJsonPath('data.status', 'cancelled');

        // Ready: every survey paid once and parsed; one capture failed (refusal).
        $this->workTourQueue();
        $ready = $this->export('record-ready', 'GET', "tour-analyses/$id", null, 200)->assertJsonPath('data.status', 'ready')->json('data');
        $this->export('capabilities-enabled', 'GET', 'tour-analyses', null, 200);
        $cases = array_column($ready['captures'], 'caseId');

        // E5 and its errors.
        $change = fn (int $case, array $remove, array $add = []) => ['caseId' => $cases[$case], 'add' => $add, 'remove' => $remove];
        $this->export('selection-write', 'POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 0, 'changes' => [$change(1, ['g1'])]], 200);
        $this->exportError('error-409-selection-stale', 'POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 0, 'changes' => [$change(1, [])]],
            'staleSelection');
        $this->exportError('error-422-capture-not-surveyed', 'POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 1, 'changes' => [$change(3, ['g3'])]],
            'tour_capture_not_surveyed');
        $this->exportError('error-422-candidate-not-selectable', 'POST', "tour-analyses/$id/selection", ['preset' => 'twilight', 'baseRevision' => 0, 'changes' => [$change(0, [], ['t0'])]],
            'tour_candidate_not_selectable');
        $this->exportError('error-422-candidate-not-default', 'POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 1, 'changes' => [$change(2, ['g2'])]],
            'tour_candidate_not_default');
        $this->exportError('error-422-duplicate-change', 'POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 1,
            'changes' => [$change(0, ['g0']), ['caseId' => strtoupper($cases[0]), 'add' => [], 'remove' => []]]], 'tour_duplicate_change');
        $this->exportError('error-422-invalid-type', 'POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => '1', 'changes' => [$change(0, ['g0'])]],
            'tour_invalid_type');
        $this->exportError('error-422-unsupported-field', 'POST', "tour-analyses/$id/cancel", ['force' => true], 'tour_unsupported_field');
        $this->exportError('error-413-body-too-large', 'POST', "tour-analyses/$id/accept", ['runId' => (string) Str::uuid(), 'caseId' => $cases[0], 'accepted' => true,
            'pad' => str_repeat('x', 4096)], 'tour_body_too_large');

        // E6: 202 creating, 200 replay, the monitor starts the chunk, then the run completes with a held room.
        $this->travel(61)->seconds(); // a fresh write-throttle minute (30 writes per minute per token)
        $record = $this->export('record-before-run', 'GET', "tour-analyses/$id", null, 200)->json('data');
        $runBody = ['requestId' => (string) Str::uuid(), 'preset' => 'golden-hour', 'selectionRevision' => 1, 'selectionSha256' => $record['presets']['golden-hour']['selection']['sha256']];
        // Undo the one removal first so the run has two rooms.
        $this->export('selection-reset', 'POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 1, 'changes' => [$change(1, [])]], 200);
        $record = $this->export('record-after-reset', 'GET', "tour-analyses/$id", null, 200)->json('data');
        $runBody = ['selectionRevision' => 2, 'selectionSha256' => $record['presets']['golden-hour']['selection']['sha256']] + $runBody;
        $runId = $this->export('run-202-creating', 'POST', "tour-analyses/$id/runs", $runBody, 202)->json('data.id');
        $this->export('run-200-replay', 'POST', "tour-analyses/$id/runs", $runBody, 200);
        $this->exportError('error-409-duplicate-active-run', 'POST', "tour-analyses/$id/runs", ['requestId' => (string) Str::uuid()] + $runBody, 'duplicateActiveRun');
        $this->workRunMonitors();
        $running = $this->export('record-run-running', 'GET', "tour-analyses/$id", null, 200)->json('data.runs.0');
        $this->assertSame('running', $running['status']);
        $this->export('progress-run-running', 'GET', "tour-analyses/$id/progress", null, 200);
        $this->finishItems($running['workflows'][0]['id'], [$cases[0] => 'ready', $cases[1] => 'held']);
        $this->workRunMonitors();
        $this->export('record-run-completed-held', 'GET', "tour-analyses/$id", null, 200)->assertJsonPath('data.runs.0.status', 'completed');

        // E7 and its 409.
        $this->exportError('error-409-not-held', 'POST', "tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[0], 'accepted' => true], 'notHeld');
        $this->export('accept', 'POST', "tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[1], 'accepted' => true], 200)->assertJsonPath('data.counts.accepted', 1);
        $this->export('undo', 'POST', "tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[1], 'accepted' => false], 200)->assertJsonPath('data.counts.accepted', 0);

        // The remaining E6 409s, in the order of 9.1.
        $twilight = ['requestId' => (string) Str::uuid(), 'preset' => 'twilight', 'selectionRevision' => 0, 'selectionSha256' => $record['presets']['twilight']['selection']['sha256']];
        config(['iguide-lab-tour.tour_runs_paused' => true]);
        $this->exportError('error-409-runs-paused', 'POST', "tour-analyses/$id/runs", $twilight, 'runsPaused');
        config(['iguide-lab-tour.tour_runs_paused' => false]);
        $this->exportError('error-409-preset-not-runnable', 'POST', "tour-analyses/$id/runs", ['preset' => 'grass',
            'selectionSha256' => $record['presets']['grass']['selection']['sha256']] + $twilight, 'presetNotRunnable');
        $this->exportError('error-409-selection-mismatch', 'POST', "tour-analyses/$id/runs", ['selectionSha256' => str_repeat('0', 64)] + $twilight, 'selectionMismatch');
        config(['iguide-lab-tour.caps.max_runs_per_analysis' => 1]);
        $this->exportError('error-409-run-limit', 'POST', "tour-analyses/$id/runs", $twilight, 'runLimitReached');
        config(['iguide-lab-tour.caps.max_runs_per_analysis' => 40]);
        IguideLabProject::whereKey($this->project->id)->update(['used_runs' => DB::raw('max_runs')]);
        $this->exportError('error-409-nothing-fits', 'POST', "tour-analyses/$id/runs", $twilight, 'nothingFits');

        // E8 on the finished record: idempotent, nothing changes.
        $this->export('cancel-ready', 'POST', "tour-analyses/$id/cancel", [], 200)->assertJsonPath('data.status', 'ready');
        $this->export('record-final', 'GET', "tour-analyses/$id", null, 200);
    }

    public function test_tour_operations_leave_existing_dtos_byte_identical(): void
    {
        $rooms = ['Front Exterior' => 'exterior-front', 'Main Kitchen' => 'kitchen'];
        $import = $this->seedImport($rooms);
        $id = $this->tourPost('tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'golden-hour'], 202)->json('data.id');
        $this->workTourQueue();
        $record = $this->tourGet("tour-analyses/$id")->json('data');
        $cases = array_column($record['captures'], 'caseId');
        $runId = $this->tourPost("tour-analyses/$id/runs", ['requestId' => (string) Str::uuid(), 'preset' => 'golden-hour', 'selectionRevision' => 0,
            'selectionSha256' => $record['presets']['golden-hour']['selection']['sha256']], 202)->json('data.id');
        $this->workRunMonitors();
        $workflowId = $this->tourGet("tour-analyses/$id")->json('data.runs.0.workflows.0.id');
        $this->finishItems($workflowId, [$cases[0] => 'ready', $cases[1] => 'held']);
        $this->workRunMonitors();
        // A re-import of the same cases, whose completed surveys are reused for free (D8).
        $reimport = $this->seedImport($rooms, reuse: $import);
        $providerCalls = count($this->providerCalls);

        $paths = ['', 'imports', "imports/{$import->id}", "imports/{$reimport->id}", 'workflows', "workflows/$workflowId"];
        $before = array_map(fn ($path) => $this->api('GET', $path)->assertOk()->getContent(), $paths);

        $cached = $this->tourPost('tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $reimport->id, 'preset' => 'golden-hour'], 200);
        $this->assertSame(['ready', 2], [$cached->json('data.status'), $cached->json('data.counts.surveyed')], 'create (cached) queues nothing');
        $this->tourPost("tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 0, 'changes' => [['caseId' => $cases[1], 'add' => [], 'remove' => ['g1']]]], 200);
        $this->tourPost("tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[1], 'accepted' => true], 200)->assertJsonPath('data.counts.accepted', 1);
        $this->tourPost("tour-analyses/$id/accept", ['runId' => $runId, 'caseId' => $cases[1], 'accepted' => false], 200);
        $this->tourPost("tour-analyses/$id/cancel", [], 200);

        $after = array_map(fn ($path) => $this->api('GET', $path)->assertOk()->getContent(), $paths);
        foreach ($paths as $i => $path) $this->assertSame($before[$i], $after[$i], "GET /api/iguide-lab/$path is byte-identical (D17)");
        $this->assertSame($providerCalls, count($this->providerCalls), 'the cached create paid nothing');
    }

    public function test_tour_created_workflow_is_exported_for_check_ts(): void
    {
        $import = $this->seedImport(['Front Exterior' => 'exterior-front', 'Main Kitchen' => 'kitchen']);
        $id = $this->tourPost('tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => $import->id, 'preset' => 'golden-hour'], 202)->json('data.id');
        $this->workTourQueue();
        $record = $this->tourGet("tour-analyses/$id")->json('data');
        $this->tourPost("tour-analyses/$id/runs", ['requestId' => (string) Str::uuid(), 'preset' => 'golden-hour', 'selectionRevision' => 0,
            'selectionSha256' => $record['presets']['golden-hour']['selection']['sha256']], 202);
        $this->workRunMonitors();
        $chunk = $this->tourGet("tour-analyses/$id")->json('data.runs.0.workflows.0');

        $workflow = $this->exportAs('workflow', 'workflow-tour-chunk', 'GET', 'workflows/'.$chunk['id'], 200);
        $data = $workflow->json('data');
        $this->assertSame($chunk['id'], $data['id']);
        $this->assertSame($chunk['requestId'], $data['requestId'], 'the chunk request id is the workflow request id (README decision 10)');
        $this->assertSame($chunk['caseIds'], array_column($data['items'], 'caseId'));
        $this->assertSame(['golden-hour'], array_values(array_unique(array_column($data['items'], 'preset'))));
        $this->assertSame([false], array_values(array_unique(array_column(array_column($data['items'], 'automation'), 'manualAcceptance'))));
    }

    // ------------------------------------------------------------------ helpers

    /** A tour route answer checked by the mirror and, when TOUR_DTO_EXPORT_DIR is set, written for check-contract part (g). */
    private function export(string $name, string $method, string $path, ?array $request, int $status, ?string $token = null): TestResponse
    {
        return $this->exportAs('tour', $name, $method, $path, $status, $request, $token);
    }

    private function exportError(string $name, string $method, string $path, array $request, string $keyOrCode): TestResponse
    {
        [$status, $code, $text] = LabTourErrors::MESSAGES[$keyOrCode]
            ?? [LabTourErrors::UNPROCESSABLE[$keyOrCode][0], $keyOrCode, LabTourErrors::unprocessable($keyOrCode)->getMessage()];

        return $this->export($name, $method, $path, $request, $status)->assertJsonPath('code', $code)->assertJsonPath('message', $text);
    }

    private function exportAs(string $kind, string $name, string $method, string $path, int $status, ?array $request = null, ?string $token = null): TestResponse
    {
        $token ??= $this->token;
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($token === '' ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        // An empty body is the JSON object {} (the cancel request), never the list [].
        $payload = $request === [] ? new \stdClass : $request;
        $response = $this->call($method, '/api/iguide-lab/'.$path, [], [], [], $server, $payload === null ? null : json_encode($payload));
        $response->assertStatus($status);
        if ($kind === 'tour') $this->assertTourResponse($response, $method, $path);
        if (is_string($dir = getenv('TOUR_DTO_EXPORT_DIR')) && $dir !== '') {
            if (! is_dir($dir)) mkdir($dir, 0775, true);
            $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
            file_put_contents($dir.'/'.$name.'.json', json_encode(['kind' => $kind, 'method' => $method, 'path' => $path, 'status' => $status,
                'request' => $payload, 'body' => $body], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }

        return $response;
    }

    private function tourGet(string $path): TestResponse
    {
        $response = $this->api('GET', $path)->assertOk();
        $this->assertTourResponse($response, 'GET', $path);

        return $response;
    }

    private function tourPost(string $path, array $body, int $status): TestResponse
    {
        $response = $this->api('POST', $path, $body)->assertStatus($status);
        $this->assertTourResponse($response, 'POST', $path);

        return $response;
    }
}
