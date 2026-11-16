<?php

namespace Tests\Support\TourAnalysis;

use App\Jobs\MonitorIguideLabTourAnalysis;
use App\Jobs\ProcessIguideLabTourSurvey;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourSurvey;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourRunCapacity;
use App\Services\IguideLab\LabTourRuns;
use App\Services\IguideLab\LabTourSurveys;
use App\Services\IguideLab\LabTourView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RecordingQueueFake;

/**
 * Seeding helpers and WP2/WP4 doubles for the WP3 tests (spec 13.1 "a seeding helper per test class").
 * Imports are seeded as literal completed rows (bulk case rows, JSON pre-encoded) like IguideLabCapacityTest.
 */
trait SeedsTourAnalyses
{
    protected FakeTourSurveys $surveys;
    protected FakeTourRuns $runs;
    protected FakeTourRunCapacity $capacity;
    protected RecordingQueueFake $queue;

    protected function installTourDoubles(): void
    {
        require_once __DIR__.'/survey-job-stand-in.php';
        $this->surveys = new FakeTourSurveys;
        $this->surveys->baselineLevel = DB::transactionLevel();
        $this->app->instance(LabTourSurveys::class, $this->surveys);
        $this->app->instance(LabTourRuns::class, $this->runs = new FakeTourRuns);
        $this->app->instance(LabTourRunCapacity::class, $this->capacity = new FakeTourRunCapacity);
        $this->queue = RecordingQueueFake::install($this);
        config(['iguide-lab-tour.tour_analysis_enabled' => true]);
    }

    protected function analyses(): LabTourAnalyses
    {
        return app(LabTourAnalyses::class);
    }

    protected function tourProject(array $attributes = []): IguideLabProject
    {
        return IguideLabProject::create($attributes + ['name' => 'Tour analysis fixture', 'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(10), 'max_runs' => 60]);
    }

    /**
     * A completed import with one case per room name (null = unnamed), in sources[] order.
     *
     * @param list<?string>|int $rooms
     */
    protected function tourImport(IguideLabProject $project, array|int $rooms, string $status = 'completed'): IguideLabImport
    {
        $rooms = is_int($rooms) ? array_map(fn ($i) => 'Room '.$i, range(0, $rooms - 1)) : $rooms;
        $importId = (string) Str::uuid();
        $cases = $sources = [];
        foreach (array_values($rooms) as $i => $room) {
            $caseId = (string) Str::uuid();
            $cases[] = ['id' => $caseId, 'project_id' => $project->id, 'label' => ($room ?? 'Unknown room').' · Panorama '.$i, 'projection' => 'equirectangular',
                'width' => 4096, 'height' => 2048, 'source_sha256' => hash('sha256', 'source:'.$caseId), 'source_path' => 'fixture/'.$caseId.'.jpg',
                'source_mime' => 'image/jpeg', 'metadata' => json_encode(['importId' => $importId]), 'created_at' => now(), 'updated_at' => now()];
            $sources[] = ['caseId' => $caseId, 'importSourceKey' => hash('sha256', 'key:'.$caseId), 'sourceSha256' => hash('sha256', 'source:'.$caseId),
                'width' => 4096, 'height' => 2048, 'panoId' => (string) ($i + 1), 'roomId' => null, 'originalRoomId' => null, 'roomName' => $room,
                'floorName' => $room === null ? null : 'Main Floor', 'metadataAuthority' => 'fixture', 'entry' => 'sample/p'.$i.'.jpg', 'reused' => false,
                'previewPath' => 'iguide-lab/'.$project->id.'/imports/'.$importId.'/'.$caseId.'-preview.png', 'previewSha256' => $this->previewSha($caseId),
                'previewDimensions' => [2048, 1024]];
        }
        foreach (array_chunk($cases, 40) as $chunk) DB::table('iguide_lab_cases')->insert($chunk);
        DB::table('iguide_lab_imports')->insert(['id' => $importId, 'project_id' => $project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'import:'.$importId), 'source_kind' => 'zip', 'private_input' => 'fixture', 'status' => $status,
            'sources' => json_encode($status === 'completed' ? $sources : []), 'audit' => json_encode(['warnings' => []]), 'created_at' => now(), 'updated_at' => now()]);

        return IguideLabImport::findOrFail($importId);
    }

    protected function previewSha(string $caseId): string
    {
        return hash('sha256', 'preview:'.strtolower($caseId));
    }

    /** @return list<string> */
    protected function caseIds(IguideLabImport $import): array
    {
        return array_column($import->sources, 'caseId');
    }

    protected function input(IguideLabImport $import, string $preset = 'golden-hour', ?string $requestId = null): array
    {
        return ['requestId' => $requestId ?? (string) Str::uuid(), 'importId' => $import->id, 'preset' => $preset];
    }

    /** A survey row for (project, case, preview) in the given state; completed rows carry a normalised result. */
    protected function seedSurvey(IguideLabProject $project, string $caseId, string $status = 'completed', ?string $error = null, ?array $result = null,
        int $generation = 1, array $attempts = []): IguideLabTourSurvey
    {
        $survey = IguideLabTourSurvey::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'case_id' => $caseId,
            'source_import_id' => (string) Str::uuid(), 'case_source_sha256' => hash('sha256', 'source:'.$caseId), 'preview_sha256' => $this->previewSha($caseId),
            'survey_version' => 'tour-survey/1', 'generation' => $generation, 'status' => $status, 'error_code' => $error,
            'protocol' => ['version' => 'tour-survey/1'], 'data' => ['base' => 'fixture', 'warnings' => []],
            'result' => $status === 'completed' ? ($result ?? $this->kitchenResult()) : null, 'completed_at' => in_array($status, ['completed', 'failed', 'needs_attention'], true) ? now() : null]);
        foreach ($attempts as $index => $attemptStatus) {
            DB::table('iguide_lab_tour_survey_attempts')->insert(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'survey_id' => $survey->id,
                'attempt_index' => $index, 'credential_source' => $index ? 'dashboard-fallback' : 'lab-primary', 'requested_model' => 'gpt-6-astra',
                'status' => $attemptStatus, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $survey;
    }

    /** Lab-wide attempt rows (the cap counter) on one survey row of the project. */
    protected function seedAttempts(IguideLabProject $project, int $count, ?\DateTimeInterface $startedAt = null): void
    {
        $caseId = (string) Str::uuid();
        DB::table('iguide_lab_cases')->insert(['id' => $caseId, 'project_id' => $project->id, 'label' => 'Cap fixture', 'projection' => 'equirectangular',
            'width' => 2048, 'height' => 1024, 'source_sha256' => hash('sha256', $caseId), 'source_path' => 'fixture/'.$caseId.'.png',
            'source_mime' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
        $rows = [];
        for ($done = 0; $done < $count; $done += 200) {
            $survey = $this->seedSurvey($project, $caseId, 'failed', 'survey_rejected', null, intdiv($done, 200) + 1);
            for ($i = $done; $i < min($count, $done + 200); $i++) {
                $rows[] = ['id' => (string) Str::uuid(), 'project_id' => $project->id, 'survey_id' => $survey->id, 'attempt_index' => $i - $done,
                    'credential_source' => 'lab-primary', 'requested_model' => 'gpt-6-astra', 'status' => 'rejected', 'http_status' => 400,
                    'started_at' => $startedAt ?? now(), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table('iguide_lab_tour_survey_attempts')->insert($chunk);
    }

    /** Normalised survey result (spec 5.8) of a lit interior with windows and lamps on. */
    protected function kitchenResult(array $clutter = []): array
    {
        return ['version' => 'tour-survey-result/1', 'space' => ['setting' => 'interior', 'label' => 'Kitchen', 'confidence' => 0.9], 'skyShare' => 0.02,
            'light' => ['windows' => true, 'windowKind' => 'normal', 'daylight' => true, 'daylightStrength' => 'good', 'lightFixtures' => true,
                'lampsOn' => true, 'hardShadows' => false, 'mirrorsOrGlass' => false],
            'lighting' => ['goldenHour' => ['description' => 'Warm light through the window over the sink.', 'confidence' => 0.9],
                'twilight' => ['description' => 'Blue hour in the window; pendants on.', 'confidence' => 0.86]],
            'lawn' => ['status' => 'none', 'condition' => null, 'areas' => []],
            'clutter' => ['surfaces' => $clutter], 'notes' => [], 'warnings' => []];
    }

    /** A surface of items for kitchenResult(); each item is [label, confidence, glassOrMirror, touchGroup]. */
    protected function surface(string $label, array $items): array
    {
        return ['label' => $label, 'region' => ['yaw' => -40.0, 'pitch' => -20.0, 'hfov' => 40.0, 'vfov' => 12.0], 'items' => array_map(fn ($i) => [
            'label' => $i[0], 'description' => '', 'region' => ['yaw' => -45.0, 'pitch' => -18.0, 'hfov' => 3.0, 'vfov' => 4.0],
            'confidence' => $i[1], 'glassOrMirror' => $i[2], 'touchGroup' => $i[3]], $items)];
    }

    /** @return list<string> survey ids of every ProcessIguideLabTourSurvey push so far */
    protected function surveyPushes(): array
    {
        return $this->queue->pushed(ProcessIguideLabTourSurvey::class)->map(fn ($job) => $job->surveyId)->values()->all();
    }

    /** @return list<MonitorIguideLabTourAnalysis> */
    protected function monitorPushes(): array
    {
        return $this->queue->pushed(MonitorIguideLabTourAnalysis::class)->values()->all();
    }

    protected function tourView(IguideLabTourAnalysis $analysis): LabTourView
    {
        return DB::transaction(fn () => LabTourView::load($analysis->project_id, $analysis->id));
    }

    /** @return array<string, string> caseId => capture status */
    protected function captureStatuses(IguideLabTourAnalysis $analysis): array
    {
        return IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->pluck('status', 'case_id')->all();
    }

    /** Complete every dispatched (queued, dispatched_at set) survey row as the WP2 job would, then notify the analyses. */
    protected function completeDispatchedSurveys(?array $result = null): int
    {
        $done = 0;
        foreach (IguideLabTourSurvey::where('status', 'queued')->whereNotNull('dispatched_at')->get() as $survey) {
            IguideLabTourSurvey::whereKey($survey->id)->toBase()->update(['status' => 'completed', 'result' => json_encode($result ?? $this->kitchenResult()),
                'completed_at' => now(), 'updated_at' => now()]);
            $this->analyses()->surveyFinished($survey->fresh());
            $done++;
        }

        return $done;
    }

    /**
     * The record-level reader rules this package is responsible for (tourContract.ts:182-222, 353-366, 460-467): counts,
     * statusIssue, capture error/survey presence and per-capture invariants. WP5's AssertsTourContract covers the whole record.
     */
    protected function assertRecordParts(IguideLabTourAnalysis $analysis): void
    {
        $view = $this->tourView($analysis);
        $counts = $this->analyses()->countsDto($view);
        $captures = $this->analyses()->capturesDto($view);
        $this->assertSame(['total', 'surveyed', 'failed', 'skipped', 'pending'], array_keys($counts));
        $this->assertTrue(array_is_list($captures));
        $this->assertSame($counts['total'], count($captures));
        $by = fn (string ...$s) => count(array_filter($captures, fn ($c) => in_array($c['status'], $s, true)));
        $this->assertSame([$by('surveyed'), $by('failed'), $by('skipped'), $by('pending', 'surveying')],
            [$counts['surveyed'], $counts['failed'], $counts['skipped'], $counts['pending']]);
        $this->assertSame($counts['total'], $counts['surveyed'] + $counts['failed'] + $counts['skipped'] + $counts['pending']);
        $status = $view->analysis->status;
        $completedAt = $view->analysis->completed_at;
        $issue = match (true) {
            $status === 'queued' && $counts['pending'] !== $counts['total'] => 'queued',
            $status === 'analysing' && $counts['pending'] === 0 => 'analysing',
            $status === 'ready' && ($counts['pending'] > 0 || $counts['surveyed'] === 0) => 'ready',
            $status === 'failed' && ($counts['pending'] > 0 || $counts['surveyed'] > 0) => 'failed',
            $status === 'cancelled' && $counts['pending'] > 0 => 'cancelled',
            in_array($status, ['queued', 'analysing'], true) !== ($completedAt === null) => 'completedAt',
            default => null,
        };
        $this->assertNull($issue, 'statusIssue: '.$issue);
        foreach ($captures as $i => $capture) {
            $this->assertSame(['caseId', 'order', 'roomName', 'floorName', 'status', 'error', 'survey', 'applicability', 'candidates'], array_keys($capture));
            $this->assertSame($i, $capture['order']);
            $surveyed = $capture['status'] === 'surveyed';
            $this->assertSame($surveyed, $capture['survey'] !== null);
            $this->assertSame($surveyed, $capture['applicability'] !== null);
            $this->assertSame(in_array($capture['status'], ['failed', 'skipped'], true), $capture['error'] !== null, 'error set exactly for failed/skipped');
            if ($capture['error'] !== null) $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,64}\z/', $capture['error']);
            if ($surveyed) app(\App\Services\IguideLab\LabTourDefaults::class)->assertCapture($capture);
            else $this->assertSame([], $capture['candidates']);
        }
        $this->assertStringStartsWith('[', json_encode($captures));
    }
}
