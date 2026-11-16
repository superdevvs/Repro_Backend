<?php

namespace Tests\Support;

use App\Jobs\MonitorIguideLabTourAnalysis;
use App\Jobs\MonitorIguideLabTourRun;
use App\Jobs\ProcessIguideLabTourSurvey;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabWorkflowItem;
use App\Services\IguideLab\LabAnalysisViews;
use App\Services\IguideLab\LabImages;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabStorage;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourRuns;
use App\Services\IguideLab\LabTourSurveys;
use App\Services\IguideLab\LabTourSurveyViews;
use App\Services\IguideLab\LabWorkflowArtifacts;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FixtureTourSurveyViews;
use Tests\Concerns\TourSurveyFixtures;

/**
 * End-to-end set-up for the integrated tour layer (spec 13.6, 14.2 item 6): every tour service is the real class
 * (LabTourAnalyses, LabTourSelections, LabTourDefaults, LabTourSurveys, LabTourRunCapacity, LabTourRuns,
 * LabTourAcceptances, LabTourCapabilities, LabTourRecords). Only what CI cannot run is replaced, as in the package tests:
 * - the paid provider: Http::fake answers each survey from tests/Fixtures/iguide-lab/tour/responses by room name;
 * - analysis_views.py: FixtureTourSurveyViews (spec 13.1);
 * - LabWorkflowArtifacts and LabAnalysisViews readiness (IguideLabWorkflowTest.php:33-52);
 * - the queue: RecordingQueueFake; queued jobs are run by hand, the way a worker would.
 * LabWorkflowPolicy and LabWorkflows are the real classes behind the WP4 spies, which fail the test at teardown when a
 * policy read or a workflow start/cancel/stop happens inside a transaction.
 */
trait TourEndToEndFixtures
{
    protected IguideLabProject $project;
    protected string $token;
    protected RecordingQueueFake $queue;
    protected SpyLabWorkflowPolicy $policy;
    protected TransactionAssertingLabWorkflows $workflows;
    protected FixtureTourSurveyViews $views;
    /** @var array<string, string> room name => response fixture name */
    protected array $answers = [];
    /** @var list<string> room names sent to the provider, in call order */
    protected array $providerCalls = [];
    private string $tourKeyFile;
    private int $tourColour = 0;
    /** @var array<int, true> spl_object_id of queued jobs already run */
    private array $ranJobs = [];

    protected function setUpTourEndToEnd(): void
    {
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        $this->tourKeyFile = tempnam(sys_get_temp_dir(), 'tour-e2e-key-');
        file_put_contents($this->tourKeyFile, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback',
            'iguide-lab.openai_primary_key_path' => $this->tourKeyFile, 'iguide-lab-tour.tour_analysis_enabled' => true,
            'iguide-lab.lighting_repair_enabled' => true, 'iguide-lab-tour.worker_count' => 8]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory('');
        $disk->setVisibility('', 'private');
        $this->mock(LabAnalysisViews::class)->shouldReceive('ready')->andReturn(true);
        $this->mock(LabWorkflowArtifacts::class, function ($mock) {
            $mock->shouldReceive('snapshot')->andReturn(['recipeVersion' => 'fixture/1']);
            $mock->shouldReceive('validateSnapshot')->andReturnNull();
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('preflight')->andReturnNull();
        });
        $this->queue = RecordingQueueFake::install($this);
        $this->policy = SpyLabWorkflowPolicy::install($this);
        $this->workflows = TransactionAssertingLabWorkflows::install($this);
        // FixtureTourSurveyViews is declared at the bottom of the WP2 helper file (build-notes-wp2.md).
        trait_exists(TourSurveyFixtures::class);
        $this->views = new FixtureTourSurveyViews;
        $this->app->instance(LabTourSurveyViews::class, $this->views);
        [$this->project, $this->token] = $this->tourProject();
        $this->answers = [];
        Http::fake(['https://api.openai.com/v1/responses' => function (HttpRequest $request) {
            foreach ($this->answers as $room => $fixture) {
                // Room names carry a space or a hyphen, which base64 image data never contains.
                if (str_contains($request->body(), $room)) {
                    $this->providerCalls[] = $room;

                    // A fixture name, or a whole response envelope (JSON text) for a room.
                    $body = str_starts_with($fixture, '{') ? $fixture : file_get_contents(base_path('tests/Fixtures/iguide-lab/tour/responses/'.$fixture.'.json'));

                    return Http::response($body, 200, ['x-request-id' => 'req_'.count($this->providerCalls)]);
                }
            }
            $this->fail('A survey request named no known room.');
        }]);
    }

    protected function tearDownTourEndToEnd(): void
    {
        if (isset($this->tourKeyFile) && is_file($this->tourKeyFile)) unlink($this->tourKeyFile);
        $this->travelBack();
    }

    /** @return array{0: IguideLabProject, 1: string} */
    protected function tourProject(array $attributes = []): array
    {
        $token = bin2hex(random_bytes(32));

        return [IguideLabProject::create($attributes + ['name' => 'Tour end-to-end fixture', 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(10), 'max_runs' => 60]), $token];
    }

    /**
     * A completed import of $rooms (room name => survey response fixture) with real 2048x1024 originals and previews on the
     * faked private disk, the shape LabImports writes. $reuse re-imports the cases of an earlier import (same case ids,
     * originals and previews), so their completed surveys are reused for free (D8).
     */
    protected function seedImport(array $rooms, ?IguideLabProject $project = null, ?IguideLabImport $reuse = null, string $status = 'completed'): IguideLabImport
    {
        $project ??= $this->project;
        $importId = (string) Str::uuid();
        app(LabStorage::class)->prepareImport($project->id, $importId);
        $disk = Storage::disk(LabService::DISK);
        $prefix = 'iguide-lab/'.$project->id.'/imports/'.$importId;
        $sources = [];
        $previous = $reuse ? array_values($reuse->sources) : [];
        foreach (array_keys($rooms) as $i => $room) {
            $this->answers[$room] = $rooms[$room];
            if (isset($previous[$i])) {
                $source = $previous[$i];
                $preview = $disk->get($source['previewPath']);
                $source['previewPath'] = $prefix.'/'.$source['caseId'].'-preview.png';
                $disk->put($source['previewPath'], $preview, ['visibility' => 'private']);
                $sources[] = $source;
                continue;
            }
            $original = $this->tourPng();
            $caseId = (string) Str::uuid();
            $disk->put($prefix.'/'.$caseId.'.png', $original, ['visibility' => 'private']);
            DB::table('iguide_lab_cases')->insert(['id' => $caseId, 'project_id' => $project->id, 'label' => $room, 'projection' => 'equirectangular',
                'width' => 2048, 'height' => 1024, 'source_sha256' => hash('sha256', $original), 'source_path' => $prefix.'/'.$caseId.'.png',
                'source_mime' => 'image/png', 'metadata' => json_encode(['roomName' => $room]), 'created_at' => now(), 'updated_at' => now()]);
            $preview = LabImages::prepared($original);
            $disk->put($prefix.'/'.$caseId.'-preview.png', $preview, ['visibility' => 'private']);
            $sources[] = ['caseId' => $caseId, 'importSourceKey' => hash('sha256', 'key:'.$caseId), 'sourceSha256' => hash('sha256', $original), 'width' => 2048,
                'height' => 1024, 'roomName' => $room, 'floorName' => 'Main Floor', 'previewPath' => $prefix.'/'.$caseId.'-preview.png',
                'previewSha256' => hash('sha256', $preview), 'previewDimensions' => [2048, 1024]];
        }

        return IguideLabImport::create(['id' => $importId, 'project_id' => $project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'import:'.$importId), 'source_kind' => 'zip', 'private_input' => ['tour' => null], 'status' => $status,
            'sources' => $status === 'completed' ? $sources : [], 'audit' => ['providerCalls' => 0], 'completed_at' => $status === 'completed' ? now() : null]);
    }

    protected function api(string $method, string $path, ?array $body = null, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->token)->json($method, '/api/iguide-lab/'.$path, $body ?? []);
    }

    /**
     * Drive the survey work of an analysis the way the workers do: run every queued monitor and survey job not run yet
     * (a received survey is released and run again for its free parse) until nothing new is queued.
     */
    protected function workTourQueue(int $rounds = 10): void
    {
        for ($round = 0; $round < $rounds; $round++) {
            $ran = 0;
            foreach ($this->queue->pushed(MonitorIguideLabTourAnalysis::class) as $job) $ran += $this->runQueued($job, fn ($job) => $job->handle(app(LabTourAnalyses::class)));
            foreach ($this->queue->pushed(ProcessIguideLabTourSurvey::class) as $job) {
                $ran += $this->runQueued($job, function (ProcessIguideLabTourSurvey $job) {
                    for ($pass = 0; $pass < 3; $pass++) {
                        $job->middleware()[0]->handle($job, fn ($next) => $next->handle(app(LabTourSurveys::class)));
                        if (IguideLabTourSurvey::find($job->surveyId)?->status !== 'received') return;
                    }
                });
            }
            if ($ran === 0) return;
        }
        $this->fail('The tour queue did not settle.');
    }

    /** Run every queued run monitor job not run yet (one tick each); returns how many ran. */
    protected function workRunMonitors(): int
    {
        $ran = 0;
        foreach ($this->queue->pushed(MonitorIguideLabTourRun::class) as $job) $ran += $this->runQueued($job, fn ($job) => $job->handle(app(LabTourRuns::class)));

        return $ran;
    }

    /** Finish the workflow items of a run's workflow: 'ready' or 'held' (needs_review) per case id, each with a composite. */
    protected function finishItems(string $workflowId, array $outcomes): void
    {
        foreach (IguideLabWorkflowItem::where('workflow_id', $workflowId)->orderBy('position')->get() as $item) {
            $outcome = $outcomes[$item->case_id] ?? null;
            if ($outcome === null) continue;
            $composite = (string) Str::uuid();
            DB::table('iguide_lab_composites')->insert(['id' => $composite, 'project_id' => $item->project_id, 'case_id' => $item->case_id,
                'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'label' => 'Fixture', 'preview_path' => 'fixture',
                'manifest' => json_encode(['preset' => $item->preset, 'resolutionStrategy' => 'fixture', 'preview' => ['width' => 2048, 'height' => 1024]]),
                'created_at' => now(), 'updated_at' => now()]);
            $outcome === 'ready'
                ? app(LabWorkflows::class)->finish($item, 'ready', null, null, ['compositeId' => $composite, 'reviewReasons' => []])
                : app(LabWorkflows::class)->finish($item, 'needs_review', 'quality_checks_failed', 'Fixture review reason.', ['compositeId' => $composite, 'reviewReasons' => []]);
        }
    }

    private function runQueued(object $job, callable $run): int
    {
        if (isset($this->ranJobs[spl_object_id($job)])) return 0;
        $this->ranJobs[spl_object_id($job)] = true;
        if ($job->job === null) $job->withFakeQueueInteractions();
        $run($job);

        return 1;
    }

    private function tourPng(): string
    {
        $this->tourColour++;
        $image = imagecreatetruecolor(2048, 1024);
        imagefill($image, 0, 0, imagecolorallocate($image, (37 * $this->tourColour) % 256, (91 * $this->tourColour) % 256, (53 * $this->tourColour) % 256));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
