<?php

namespace Tests\Feature;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Services\IguideLab\LabWorkflowPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsTourContract;
use Tests\Support\RecordingQueueFake;
use Tests\Support\TourLayerDoubles;
use Tests\Support\TourPolicySpy;
use Tests\TestCase;

/**
 * Backend DTO export for tour-contract/check-contract.ts part (g) (spec 12.3, 13.6, 13.8).
 *
 * WP5 part: the bodies the HTTP layer produces on its own (capabilities on and off with real summaries, and every
 * request-guard error rendered by ApiErrorResponder). Every body passes AssertsTourContract. With TOUR_DTO_EXPORT_DIR
 * set, each one is also written as {kind, method, path, status, request, body}; the bridge path is relative to
 * /api/iguide-lab/. The integration step adds the record, progress, selection, run, accept, cancel and 409 exports
 * (13.6 test_every_endpoint_dto_matches_the_contract_mirror) once the WP2-WP4 services are real.
 */
class IguideLabTourContractExportTest extends TestCase
{
    use AssertsTourContract;
    use RefreshDatabase;

    private string $token;
    private IguideLabProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        RecordingQueueFake::install($this);
        // WP2 survey counters (attemptsUsedByProject/attemptsUsedToday) are the only stand-in this export needs.
        $doubles = TourLayerDoubles::install();
        $doubles->attemptsUsedByProject = 26;
        $doubles->attemptsUsedToday = 26;
        $this->app->instance(LabWorkflowPolicy::class, new TourPolicySpy(DB::transactionLevel()));
        $this->token = bin2hex(random_bytes(32));
        $this->project = IguideLabProject::create(['name' => 'Tour export fixture', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDays(10),
            'max_runs' => 60]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_capabilities_and_request_errors_match_the_contract_mirror(): void
    {
        $analysis = $this->analysis();
        $id = $analysis->id;
        $caseId = $analysis->captures()->value('case_id');

        config(['iguide-lab-tour.tour_analysis_enabled' => false]);
        $this->export('capabilities-disabled', 'GET', 'tour-analyses', null, 200);
        $this->export('error-404-disabled-record', 'GET', "tour-analyses/$id", null, 404);

        config(['iguide-lab-tour.tour_analysis_enabled' => true]);
        $this->export('capabilities-enabled', 'GET', 'tour-analyses', null, 200);
        $this->export('error-401-no-token', 'GET', 'tour-analyses', null, 401, token: '');
        $this->export('error-404-unknown-record', 'GET', 'tour-analyses/'.Str::uuid(), null, 404);
        $this->export('error-422-unsupported-field', 'POST', "tour-analyses/$id/cancel", ['force' => true], 422);
        $this->export('error-422-validation', 'POST', 'tour-analyses', ['requestId' => 'not-a-uuid', 'importId' => $analysis->import_id, 'preset' => 'golden-hour'], 422);
        $this->export('error-422-invalid-type', 'POST', "tour-analyses/$id/runs", ['requestId' => (string) Str::uuid(), 'preset' => 'golden-hour',
            'selectionRevision' => '0', 'selectionSha256' => str_repeat('a', 64)], 422);
        $this->export('error-422-duplicate-change', 'POST', "tour-analyses/$id/selection", ['preset' => 'golden-hour', 'baseRevision' => 0,
            'changes' => [['caseId' => $caseId, 'add' => [], 'remove' => ['g0']], ['caseId' => strtoupper($caseId), 'add' => [], 'remove' => []]]], 422);
        $this->export('error-413-body-too-large', 'POST', "tour-analyses/$id/accept", ['runId' => (string) Str::uuid(), 'caseId' => $caseId,
            'accepted' => true, 'pad' => str_repeat('x', 4096)], 413);
    }

    private function export(string $name, string $method, string $path, ?array $request, int $status, ?string $token = null): TestResponse
    {
        $token ??= $this->token;
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($token === '' ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $response = $this->call($method, '/api/iguide-lab/'.$path, [], [], [], $server, $request === null ? null : json_encode($request));
        $response->assertStatus($status);
        $this->assertTourResponse($response, $method, $path);
        if (is_string($dir = getenv('TOUR_DTO_EXPORT_DIR')) && $dir !== '') {
            if (! is_dir($dir)) mkdir($dir, 0775, true);
            $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
            file_put_contents($dir.'/'.$name.'.json', json_encode(['kind' => 'tour', 'method' => $method, 'path' => $path, 'status' => $status,
                'request' => $request, 'body' => $body], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }

        return $response;
    }

    /** A ready analysis with two surveyed captures (golden hour recommended), so the summary list is not empty. */
    private function analysis(): IguideLabTourAnalysis
    {
        $importId = (string) Str::uuid();
        DB::table('iguide_lab_imports')->insert(['id' => $importId, 'project_id' => $this->project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'source_kind' => 'zip', 'private_input' => 'fixture', 'status' => 'completed', 'sources' => '[]',
            'created_at' => now(), 'updated_at' => now()]);
        $analysis = IguideLabTourAnalysis::create(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'import_id' => $importId,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'priority_preset' => 'golden-hour', 'status' => 'ready', 'record_revision' => 3,
            'selections' => array_fill_keys(['golden-hour', 'twilight', 'grass', 'declutter'], ['revision' => 0, 'changes' => new \stdClass]), 'completed_at' => now()]);
        foreach (['surveyed', 'failed'] as $position => $status) {
            $case = (string) Str::uuid();
            DB::table('iguide_lab_cases')->insert(['id' => $case, 'project_id' => $this->project->id, 'label' => 'Room', 'projection' => 'equirectangular',
                'width' => 2048, 'height' => 1024, 'source_sha256' => hash('sha256', $case), 'source_path' => 'fixture/'.$case.'.png', 'source_mime' => 'image/png',
                'created_at' => now(), 'updated_at' => now()]);
            IguideLabTourCapture::create(['id' => (string) Str::uuid(), 'analysis_id' => $analysis->id, 'project_id' => $this->project->id, 'case_id' => $case,
                'position' => $position, 'source_sha256' => hash('sha256', $case), 'preview_sha256' => str_repeat('2', 64), 'status' => $status,
                'error_code' => $status === 'failed' ? 'survey_unreadable' : null]);
        }

        return $analysis;
    }
}
