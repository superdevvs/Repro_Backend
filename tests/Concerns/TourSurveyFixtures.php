<?php

namespace Tests\Concerns;

use App\Jobs\ProcessIguideLabTourSurvey;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabTourSurveyAttempt;
use App\Services\IguideLab\LabImages;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabStorage;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourRevisions;
use App\Services\IguideLab\LabTourSurveys;
use App\Services\IguideLab\LabTourSurveyViews;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\RecordingQueueFake;

/**
 * Shared set-up for the WP2 survey tests (spec 13.1, 13.3): a completed import with real preview files on the faked
 * private disk, its analysis and captures, the survey view fixture, the LabTourAnalyses (WP3) double and the queue fake.
 * No network: Http::preventStrayRequests(); the paid endpoint is always faked by the test.
 */
trait TourSurveyFixtures
{
    private const RESPONSES = __DIR__.'/../Fixtures/iguide-lab/tour/responses/';

    private string $primaryKeyPath;
    private RecordingQueueFake $queue;
    private TourSurveyAnalysesDouble $analyses;
    private FixtureTourSurveyViews $views;
    private int $colour = 0;

    protected function setUpTourSurveys(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->queue = RecordingQueueFake::install($this);
        Http::preventStrayRequests();
        $this->primaryKeyPath = tempnam(sys_get_temp_dir(), 'tour-survey-key-');
        file_put_contents($this->primaryKeyPath, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback',
            'iguide-lab.openai_primary_key_path' => $this->primaryKeyPath, 'iguide-lab-tour.tour_analysis_enabled' => true]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->views = new FixtureTourSurveyViews;
        $this->app->instance(LabTourSurveyViews::class, $this->views);
        $this->analyses = new TourSurveyAnalysesDouble(DB::transactionLevel());
        // WP3 is built in parallel: LabTourAnalyses is bound to a double that records the calls (spec 14).
        $this->app->instance(LabTourAnalyses::class, $this->analyses);
        $this->beforeApplicationDestroyed(fn () => $this->assertSame([], $this->analyses->violations,
            'LabTourAnalyses was called inside a transaction (spec conventions: callers run it at the baseline level).'));
    }

    protected function tearDownTourSurveys(): void
    {
        if (isset($this->primaryKeyPath) && is_file($this->primaryKeyPath)) unlink($this->primaryKeyPath);
        $this->travelBack();
    }

    private function project(array $values = []): IguideLabProject
    {
        return IguideLabProject::create($values + ['name' => 'Tour survey fixture', 'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(10), 'max_runs' => 60]);
    }

    /**
     * A completed import with $count sources (real originals and 2048x1024 previews on the private disk), its tour
     * analysis (analysing) and pending captures. Pass 'cases' => [[id, sourceSha256, preview]] to re-import cases.
     *
     * @return array{project: IguideLabProject, import: string, analysis: IguideLabTourAnalysis, captures: list<IguideLabTourCapture>, cases: list<array>}
     */
    private function seedTour(int $count = 1, array $options = []): array
    {
        $project = $options['project'] ?? $this->project();
        $importId = (string) Str::uuid();
        app(LabStorage::class)->prepareImport($project->id, $importId);
        $disk = Storage::disk(LabService::DISK);
        $prefix = 'iguide-lab/'.$project->id.'/imports/'.$importId;
        $sources = $cases = [];
        for ($i = 0; $i < $count; $i++) {
            $case = $options['cases'][$i] ?? null;
            if ($case === null) {
                $original = $this->png(2048, 1024);
                $caseId = (string) Str::uuid();
                $disk->put($prefix.'/'.$caseId.'.png', $original, ['visibility' => 'private']);
                DB::table('iguide_lab_cases')->insert(['id' => $caseId, 'project_id' => $project->id, 'label' => 'Room '.$i, 'projection' => 'equirectangular',
                    'tour_url' => $options['tourUrl'] ?? null, 'width' => 2048, 'height' => 1024, 'source_sha256' => hash('sha256', $original),
                    'source_path' => $prefix.'/'.$caseId.'.png', 'source_mime' => 'image/png', 'metadata' => json_encode(['roomName' => 'Room '.$i]),
                    'created_at' => now(), 'updated_at' => now()]);
                $case = ['id' => $caseId, 'sourceSha256' => hash('sha256', $original), 'preview' => LabImages::prepared($original)];
            }
            $previewPath = $prefix.'/'.$case['id'].'-preview.png';
            $disk->put($previewPath, $case['preview'], ['visibility' => 'private']);
            $sources[] = ['caseId' => $case['id'], 'importSourceKey' => hash('sha256', $case['id']), 'sourceSha256' => $case['sourceSha256'], 'width' => 2048,
                'height' => 1024, 'roomName' => $options['names'][$i] ?? 'Room '.$i, 'floorName' => 'Main Floor', 'previewPath' => $previewPath,
                'previewSha256' => hash('sha256', $case['preview']), 'previewDimensions' => [2048, 1024]];
            $cases[] = $case;
        }
        IguideLabImport::create(['id' => $importId, 'project_id' => $project->id, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'source_kind' => $options['kind'] ?? 'zip', 'private_input' => $options['private'] ?? ['tour' => null], 'status' => 'completed', 'sources' => $sources,
            'audit' => ['providerCalls' => 0], 'completed_at' => now()]);
        $analysis = IguideLabTourAnalysis::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'import_id' => $importId,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'priority_preset' => 'golden-hour', 'status' => 'analysing',
            'record_revision' => 1, 'selections' => ['golden-hour' => ['revision' => 0, 'changes' => []]]]);
        $captures = [];
        foreach ($sources as $position => $source) {
            $captures[] = IguideLabTourCapture::create(['id' => (string) Str::uuid(), 'analysis_id' => $analysis->id, 'project_id' => $project->id,
                'case_id' => $source['caseId'], 'position' => $position, 'room_name' => $source['roomName'], 'floor_name' => $source['floorName'],
                'source_sha256' => $source['sourceSha256'], 'preview_sha256' => $source['previewSha256']]);
        }

        return ['project' => $project, 'import' => $importId, 'analysis' => $analysis, 'captures' => $captures, 'cases' => $cases];
    }

    /**
     * Stand-in for the WP3 dispatch step (9.7 T1): findOrReserve in one transaction; a row that needs a push gets its
     * dispatch markers and the capture becomes surveying. Returns the linked survey.
     */
    private function dispatchCapture(IguideLabTourCapture $capture, bool $allowNewGeneration = true): IguideLabTourSurvey
    {
        $importId = IguideLabTourAnalysis::findOrFail($capture->analysis_id)->import_id;

        return DB::transaction(function () use ($capture, $importId, $allowNewGeneration) {
            IguideLabProject::whereKey($capture->project_id)->update(['updated_at' => now()]);
            $survey = app(LabTourSurveys::class)->findOrReserve(IguideLabTourCapture::findOrFail($capture->id), $importId, $allowNewGeneration);
            if ($survey->status === 'queued' && $survey->dispatched_at === null) {
                IguideLabTourSurvey::whereKey($survey->id)->toBase()->update(['dispatched_at' => now(), 'dispatch_count' => DB::raw('dispatch_count + 1')]);
            }
            if (in_array($survey->status, ['queued', 'submitting', 'received'], true)) {
                IguideLabTourCapture::whereKey($capture->id)->update(['status' => 'surveying', 'error_code' => null]);
            }

            return $survey->fresh();
        });
    }

    /** Run a survey job the way a worker does: through its lock middleware, with recorded queue interactions. */
    private function runJob(ProcessIguideLabTourSurvey $job): ProcessIguideLabTourSurvey
    {
        if ($job->job === null) $job->withFakeQueueInteractions();
        $job->middleware()[0]->handle($job, fn ($next) => $next->handle(app(LabTourSurveys::class)));

        return $job;
    }

    private function response(string $name): string
    {
        return file_get_contents(self::RESPONSES.$name.'.json');
    }

    private function fakeProvider(string|array ...$bodies): void
    {
        $sequence = Http::sequence();
        foreach ($bodies as $i => $body) {
            [$content, $status] = is_array($body) ? $body : [$body, 200];
            $sequence->push(is_string($content) && ! str_starts_with($content, '{') ? $this->response($content) : $content, $status, ['x-request-id' => 'req_'.$i]);
        }
        Http::fake(['https://api.openai.com/v1/responses' => $sequence]);
    }

    private function base(IguideLabTourSurvey $survey): string
    {
        return 'iguide-lab/'.$survey->project_id.'/tour-surveys/'.$survey->id;
    }

    /** Paid attempts of other work, written straight into the ledger (the cap counter, D8). */
    private function seedAttempts(IguideLabProject $project, int $count, Carbon $startedAt): void
    {
        $caseId = (string) Str::uuid();
        DB::table('iguide_lab_cases')->insert(['id' => $caseId, 'project_id' => $project->id, 'label' => 'Ledger', 'projection' => 'equirectangular',
            'width' => 2048, 'height' => 1024, 'source_sha256' => str_repeat('e', 64), 'source_path' => 'iguide-lab/'.$project->id.'/ledger.png',
            'source_mime' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
        $surveyId = (string) Str::uuid();
        DB::table('iguide_lab_tour_surveys')->insert(['id' => $surveyId, 'project_id' => $project->id, 'case_id' => $caseId, 'source_import_id' => (string) Str::uuid(),
            'case_source_sha256' => str_repeat('e', 64), 'preview_sha256' => str_repeat('f', 64), 'survey_version' => 'tour-survey/1', 'status' => 'failed',
            'error_code' => 'survey_rejected', 'protocol' => '{}', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'project_id' => $project->id, 'survey_id' => $surveyId, 'attempt_index' => $i, 'credential_source' => 'lab-primary',
                'requested_model' => 'gpt-6-astra', 'status' => 'rejected', 'http_status' => 400, 'started_at' => $startedAt, 'completed_at' => $startedAt,
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) IguideLabTourSurveyAttempt::insert($chunk);
    }

    /** A pending jobs-table row for this survey on the tour queue (a job waiting behind others on the shared worker). */
    private function pendingJob(string $surveyId): void
    {
        DB::table('jobs')->insert(['queue' => 'studio', 'payload' => json_encode(['displayName' => ProcessIguideLabTourSurvey::class,
            'data' => ['commandName' => ProcessIguideLabTourSurvey::class, 'command' => 'O:34:"App\\Jobs\\ProcessIguideLabTourSurvey":1:{s:8:"surveyId";s:36:"'.$surveyId.'";}']]),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
    }

    private function png(int $width, int $height): string
    {
        $this->colour++;
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, (37 * $this->colour) % 256, (91 * $this->colour) % 256, (53 * $this->colour) % 256));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}

/**
 * Survey views without Python (spec 13.1): execute() writes six 1024x1024 GD PNGs and a manifest whose adapter and
 * geometry fingerprints are the real file hashes, so every PHP check of spec 5.2 runs for real.
 */
class FixtureTourSurveyViews extends LabTourSurveyViews
{
    public int $runs = 0;
    /** 'failed' imitates analysis_views.py reporting a failure on stdout. */
    public string $status = 'completed';
    /** A drifted adapter fingerprint written into the manifest. */
    public ?string $adapterSha256 = null;

    protected function execute(string $requestPath): array
    {
        $this->runs++;
        if ($this->status !== 'completed') return ['status' => $this->status, 'code' => 'ValueError'];
        $request = json_decode(file_get_contents($requestPath), true, flags: JSON_THROW_ON_ERROR);
        $out = $request['outputDirectory'];
        if (is_dir($out)) return ['status' => 'completed', 'manifest' => json_decode(file_get_contents($out.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR)];
        mkdir($out.'/views', 0770, true);
        $views = [];
        foreach (['front' => [0, 0], 'right' => [90, 0], 'back' => [180, 0], 'left' => [270, 0], 'zenith' => [0, 90], 'nadir' => [0, -90]] as $id => [$yaw, $pitch]) {
            $image = imagecreatetruecolor(1024, 1024);
            imagefill($image, 0, 0, imagecolorallocate($image, $yaw % 256, 120, 90 + $pitch));
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            file_put_contents($out.'/views/'.$id.'.png', $bytes);
            $views[] = ['id' => $id, 'path' => $out.'/views/'.$id.'.png', 'sha256' => hash('sha256', $bytes), 'width' => 1024, 'height' => 1024,
                'yaw_degrees' => $yaw, 'pitch_degrees' => $pitch, 'hfov_degrees' => 100.0];
        }
        $manifest = ['pipeline_version' => 'fixture', 'source' => $request['source'], 'views' => $views, 'diagnosticOnly' => true,
            'adapterSha256' => $this->adapterSha256 ?? hash_file('sha256', resource_path('iguide-lab/analysis_views.py')),
            'geometrySha256' => hash_file('sha256', resource_path('iguide-lab/pano_geometry.py'))];
        file_put_contents($out.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        return ['status' => 'completed', 'manifest' => $manifest];
    }
}

/**
 * Double of LabTourAnalyses (WP3) for the two calls WP2 makes (spec 14.1). surveyFinished applies a minimal capture
 * sync through LabTourSurveys::captureOutcome (5.10) so the tests can show the capture outcome; the real sync, defaults
 * and status rule belong to WP3 (9.7). Every call records its transaction level: callers must be at the baseline.
 */
class TourSurveyAnalysesDouble
{
    /** @var list<array{0:string,1:string}> [surveyId, status at the call] */
    public array $finished = [];
    /** @var list<array{0:string,1:bool,2:string}> [surveyId, force, status at the call] */
    public array $reparsed = [];
    public array $violations = [];
    public int $capturesUpdated = 1;

    public function __construct(private int $baseline) {}

    public function surveyFinished(IguideLabTourSurvey $survey): void
    {
        $this->check('surveyFinished');
        $survey = IguideLabTourSurvey::findOrFail($survey->id);
        $this->finished[] = [$survey->id, $survey->status];
        [$status, $error] = app(LabTourSurveys::class)->captureOutcome($survey);
        $captures = IguideLabTourCapture::where('project_id', $survey->project_id)->where('survey_id', $survey->id)->whereIn('status', ['pending', 'surveying'])->get();
        foreach ($captures as $capture) {
            DB::transaction(function () use ($capture, $status, $error) {
                IguideLabTourCapture::whereKey($capture->id)->whereIn('status', ['pending', 'surveying'])->update(['status' => $status, 'error_code' => $error,
                    'surveyed_at' => $status === 'surveyed' ? now() : null]);
                LabTourRevisions::bump($capture->analysis_id);
            });
        }
    }

    public function surveyReparsed(IguideLabTourSurvey $survey, bool $force): int
    {
        $this->check('surveyReparsed');
        $this->reparsed[] = [$survey->id, $force, IguideLabTourSurvey::findOrFail($survey->id)->status];

        return $this->capturesUpdated;
    }

    private function check(string $method): void
    {
        if (DB::transactionLevel() !== $this->baseline) $this->violations[] = $method.' at level '.DB::transactionLevel();
    }
}
