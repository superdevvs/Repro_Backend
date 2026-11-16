<?php

namespace Tests\Support;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use App\Services\IguideLab\LabAnalysisViews;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabTourRevisions;
use App\Services\IguideLab\LabTourRuns;
use App\Services\IguideLab\LabTourView;
use App\Services\IguideLab\LabWorkflowArtifacts;
use App\Services\IguideLab\LabWorkflows;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeding and assertions shared by the WP4 tests (spec 13.5). Doubles for WP3 (LabTourSelections) and WP5
 * (LabTourCapabilities) are bound until those packages merge; LabWorkflows and LabWorkflowPolicy are the real classes
 * behind the WP4 spies. The workflow artifact and view services are mocked as in IguideLabWorkflowTest.php:33-52.
 */
trait TourRunFixtures
{
    protected IguideLabProject $project;
    protected RecordingQueueFake $queue;
    protected TourRunSelectionsDouble $selections;
    protected TourRunCapabilitiesDouble $capabilities;
    protected SpyLabWorkflowPolicy $policy;
    protected TransactionAssertingLabWorkflows $workflows;
    protected string $keyFile;
    private static ?string $panoramaPng = null;

    protected function setUpTourRuns(): void
    {
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        $this->keyFile = tempnam(sys_get_temp_dir(), 'tour-run-key-');
        file_put_contents($this->keyFile, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback',
            'iguide-lab.openai_primary_key_path' => $this->keyFile, 'iguide-lab-tour.tour_analysis_enabled' => true,
            'iguide-lab.lighting_repair_enabled' => true]);
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
        $this->selections = new TourRunSelectionsDouble;
        $this->app->instance('App\Services\IguideLab\LabTourSelections', $this->selections);
        $this->capabilities = new TourRunCapabilitiesDouble;
        $this->app->instance('App\Services\IguideLab\LabTourCapabilities', $this->capabilities);
        $this->project = $this->makeProject();
    }

    protected function tearDownTourRuns(): void
    {
        if (isset($this->keyFile) && is_file($this->keyFile)) unlink($this->keyFile);
        $this->travelBack();
    }

    protected function makeProject(array $attributes = []): IguideLabProject
    {
        return IguideLabProject::create($attributes + ['name' => 'Tour runs fixture', 'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(10), 'max_runs' => 120]);
    }

    /**
     * A finished analysis with $rooms surveyed captures, each with one executable, default-selected golden-hour (g{n})
     * and twilight (t{n}) candidate, and owned 2048x1024 source files (LabWorkflowSources::capture passes).
     *
     * @return array{0: IguideLabTourAnalysis, 1: list<string>}
     */
    protected function seedAnalysis(?IguideLabProject $project = null, int $rooms = 3, array $options = []): array
    {
        $project ??= $this->project;
        $importId = (string) Str::uuid();
        DB::table('iguide_lab_imports')->insert(['id' => $importId, 'project_id' => $project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'source_kind' => 'zip', 'private_input' => 'fixture', 'status' => 'completed',
            'sources' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $cases = [];
        if (isset($options['cases'])) $cases = array_values($options['cases']);
        else for ($i = 0; $i < $rooms; $i++) $cases[] = $this->seedCase($project, 'Room '.$i, $options['files'] ?? true);
        $selections = [];
        foreach (['golden-hour', 'twilight', 'grass', 'declutter'] as $preset) $selections[$preset] = ['revision' => 0, 'changes' => []];
        $analysis = IguideLabTourAnalysis::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'import_id' => $importId,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'priority_preset' => 'golden-hour',
            'status' => $options['status'] ?? 'ready', 'record_revision' => 1, 'selections' => $selections,
            'completed_at' => in_array($options['status'] ?? 'ready', ['queued', 'analysing'], true) ? null : now()]);
        foreach ($cases as $n => $case) {
            IguideLabTourCapture::create(['id' => (string) Str::uuid(), 'analysis_id' => $analysis->id, 'project_id' => $project->id,
                'case_id' => $case, 'position' => $n, 'room_name' => 'Room '.$n, 'source_sha256' => str_repeat('1', 64), 'preview_sha256' => str_repeat('2', 64),
                'status' => 'surveyed', 'candidates' => $this->lightingCandidates($n, $options['grass'] ?? false),
                'defaults_version' => 'tour-defaults/1', 'surveyed_at' => now()]);
        }

        return [$analysis->fresh(), $cases];
    }

    protected function seedCase(IguideLabProject $project, string $label = 'Room', bool $file = true): string
    {
        $id = (string) Str::uuid();
        $path = 'iguide-lab/'.$project->id.'/cases/'.$id.'.png';
        $png = self::panorama();
        if ($file) Storage::disk(LabService::DISK)->put($path, $png);
        DB::table('iguide_lab_cases')->insert(['id' => $id, 'project_id' => $project->id, 'label' => $label, 'projection' => 'equirectangular',
            'width' => 2048, 'height' => 1024, 'source_sha256' => hash('sha256', $png), 'source_path' => $path, 'source_mime' => 'image/png',
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    protected static function panorama(): string
    {
        if (self::$panoramaPng === null) {
            $image = imagecreatetruecolor(2048, 1024);
            imagefill($image, 0, 0, imagecolorallocate($image, 120, 130, 140));
            ob_start();
            imagepng($image);
            self::$panoramaPng = (string) ob_get_clean();
            imagedestroy($image);
        }

        return self::$panoramaPng;
    }

    /** @return list<array<string, mixed>> */
    protected function lightingCandidates(int $n, bool $grass = false): array
    {
        $candidate = fn (string $id, string $preset, string $kind, string $origin, bool $executable) => ['id' => $id, 'groupId' => null,
            'preset' => $preset, 'kind' => $kind, 'label' => 'Whole room', 'description' => '', 'origin' => $origin,
            'region' => ['yaw' => 0, 'pitch' => 0, 'hfov' => 360, 'vfov' => 180], 'confidence' => 0.9, 'executable' => $executable,
            'defaultSelected' => $executable, 'reasons' => $executable ? ['daylight_windows'] : ['preset_runs_not_enabled']];
        $list = [$candidate('g'.$n, 'golden-hour', 'whole_room', 'rule', true), $candidate('t'.$n, 'twilight', 'whole_room', 'rule', true)];
        if ($grass) $list[] = ['region' => ['yaw' => 10, 'pitch' => -40, 'hfov' => 60, 'vfov' => 20]] + $candidate('l'.$n.'a', 'grass', 'lawn_area', 'survey', true);

        return $list;
    }

    /**
     * A run row with one target per state, on the first cases of the analysis, written directly (no workflow rows).
     * Bound states (queued, processing, ready, held, failed) get a workflow and item id; held and ready can get an attempt.
     *
     * @param list<string> $states
     */
    protected function seedRun(IguideLabTourAnalysis $analysis, string $preset, array $states, string $status = 'running', array $attributes = []): IguideLabTourRun
    {
        $cases = IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->pluck('case_id')->all();
        $this->assertGreaterThanOrEqual(count($states), count($cases));
        $admitted = count(array_filter($states, fn ($s) => $s !== 'not_admitted'));
        $run = IguideLabTourRun::create($attributes + ['id' => (string) Str::uuid(), 'project_id' => $analysis->project_id, 'analysis_id' => $analysis->id,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('f', 64), 'preset' => $preset, 'status' => $status,
            'selection_revision' => 0, 'selection_sha256' => str_repeat('a', 64), 'chunk_size' => 8,
            'estimate' => ['selectionRevision' => 0, 'roomsSelected' => count($states), 'itemsSelected' => count($states), 'roomsThatFit' => $admitted,
                'worstCaseImages' => 3 * $admitted, 'limitingReason' => $admitted === count($states) ? null : 'evaluation_ceiling',
                'estimatedMinutes' => ['min' => 3 * $admitted, 'max' => 5 * $admitted], 'computedAt' => now()->toIso8601String()],
            'completed_at' => in_array($status, ['creating', 'running'], true) ? null : now()]);
        $workflow = (string) Str::uuid();
        foreach (array_values($states) as $i => $state) {
            $bound = in_array($state, ['queued', 'processing', 'ready', 'held', 'failed'], true);
            DB::table('iguide_lab_tour_run_targets')->insert(['id' => (string) Str::uuid(), 'run_id' => $run->id, 'project_id' => $analysis->project_id,
                'case_id' => $cases[$i], 'position' => $i, 'candidate_ids' => json_encode([($preset === 'twilight' ? 't' : 'g').$i]), 'state' => $state,
                'workflow_id' => $bound ? $workflow : null, 'workflow_item_id' => $bound ? (string) Str::uuid() : null,
                'has_attempt' => in_array($state, ['ready', 'held'], true), 'reasons' => json_encode($state === 'not_admitted' ? ['evaluation_ceiling'] : []),
                'acceptance_log' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $run->fresh();
    }

    /** Facts as POST runs builds them (spec 8, 9.1 step 2), with a lighting-repair policy unless told otherwise. */
    protected function facts(array $overrides = [], bool $lightingRepair = true): array
    {
        return $overrides + ['policy' => $lightingRepair ? ['lightingRepair' => ['version' => 'fixture']] : ['version' => 'fixture'],
            'runnable' => true, 'schedulerEnabled' => false, 'tourBudgetEnabled' => false];
    }

    protected function tourView(IguideLabTourAnalysis $analysis): LabTourView
    {
        return DB::transaction(fn () => LabTourView::load($analysis->project_id, $analysis->id));
    }

    /** A POST runs body for the current selection of $preset (spec 10.3 E6). */
    protected function runInput(IguideLabTourAnalysis $analysis, string $preset = 'golden-hour', array $overrides = []): array
    {
        $view = $this->tourView($analysis);

        return $overrides + ['requestId' => (string) Str::uuid(), 'preset' => $preset,
            'selectionRevision' => (int) ($view->analysis->selections[$preset]['revision'] ?? 0),
            'selectionSha256' => TourRunSelectionsDouble::sha256($preset, $this->selections->effective($view, $preset))];
    }

    /** POST runs through the service; returns [run, created, dto]. */
    protected function createRun(IguideLabTourAnalysis $analysis, string $preset = 'golden-hour', array $overrides = []): array
    {
        return app(LabTourRuns::class)->create($analysis->fresh(), $this->runInput($analysis, $preset, $overrides));
    }

    protected function tick(IguideLabTourRun|string $run): bool
    {
        return app(LabTourRuns::class)->tick(is_string($run) ? $run : $run->id);
    }

    protected function runDto(IguideLabTourRun|string $run): array
    {
        $run = IguideLabTourRun::findOrFail(is_string($run) ? $run : $run->id);
        $view = $this->tourView(IguideLabTourAnalysis::findOrFail($run->analysis_id));

        return app(LabTourRuns::class)->dto($view, $view->runs->firstWhere('id', $run->id));
    }

    /** Finish every non-terminal item of the workflow through the real (unedited) LabWorkflows::stop(). */
    protected function finishWorkflow(string $workflowId, string $code = 'quality_checks_failed'): void
    {
        $workflow = IguideLabWorkflow::findOrFail($workflowId);
        foreach (IguideLabWorkflowItem::where('workflow_id', $workflowId)->whereNotIn('status', LabWorkflows::TERMINAL)->get() as $item) {
            app(LabWorkflows::class)->finish($item, 'needs_review', $code, 'Fixture review reason.');
        }
        IguideLabWorkflow::whereKey($workflow->id)->update(['status' => 'needs_review', 'completed_at' => now()]);
    }

    /** POST cancel as WP3's LabTourAnalyses::cancel does it for a finished analysis (spec 9.6): one lab write transaction, then cancel. */
    protected function cancelAnalysis(IguideLabTourAnalysis $analysis): array
    {
        $ids = LockedWrite::run(fn () => DB::transaction(function () use ($analysis) {
            IguideLabProject::whereKey($analysis->project_id)->update(['updated_at' => now()]);
            $result = app(LabTourRuns::class)->cancelWaiting(IguideLabTourAnalysis::findOrFail($analysis->id));
            $this->assertSame(['changed', 'cancelWorkflowIds'], array_keys($result));
            $this->assertTrue(array_is_list($result['cancelWorkflowIds']));
            if ($result['changed']) LabTourRevisions::bump($analysis->id);

            return $result['cancelWorkflowIds'];
        }), 'test.tour-cancel');
        foreach ($ids as $id) app(LabWorkflows::class)->cancel(IguideLabWorkflow::findOrFail($id));

        return $ids;
    }

    /** Insert $count lab records of one model kind (capacity counts, spec 8.3). */
    protected function seedRecords(string $table, IguideLabProject $project, int $count, ?\DateTimeInterface $createdAt = null): void
    {
        $createdAt ??= now();
        $case = $this->seedCase($project, 'Filler', false);
        $rows = [];
        $stepIds = [];
        if (in_array($table, ['iguide_lab_workflow_evaluations', 'iguide_lab_grass_assessments', 'iguide_lab_grass_source_admissions', 'iguide_lab_declutter_removal_plans'], true)) {
            $workflow = (string) Str::uuid();
            DB::table('iguide_lab_workflows')->insert(['id' => $workflow, 'project_id' => $project->id, 'request_id' => (string) Str::uuid(),
                'request_hash' => str_repeat('c', 64), 'status' => 'needs_review', 'policy' => '{}', 'completed_at' => $createdAt,
                'created_at' => $createdAt, 'updated_at' => $createdAt]);
            $item = (string) Str::uuid();
            DB::table('iguide_lab_workflow_items')->insert(['id' => $item, 'workflow_id' => $workflow, 'project_id' => $project->id, 'case_id' => $case,
                'position' => 0, 'preset' => 'twilight', 'instructions' => '', 'source' => '{}', 'status' => 'needs_review', 'data' => '{}',
                'created_at' => $createdAt, 'updated_at' => $createdAt]);
            $steps = [];
            for ($i = 0; $i < $count; $i++) {
                $steps[] = ['id' => $stepIds[] = (string) Str::uuid(), 'item_id' => $item, 'project_id' => $project->id, 'step_key' => 'seed-'.$table.'-'.$i,
                    'kind' => 'evaluation', 'request_id' => (string) Str::uuid(), 'input' => '{}', 'input_sha256' => str_repeat('d', 64),
                    'created_at' => $createdAt, 'updated_at' => $createdAt];
            }
            foreach (array_chunk($steps, 100) as $chunk) DB::table('iguide_lab_workflow_steps')->insert($chunk);
        }
        for ($i = 0; $i < $count; $i++) {
            $base = ['id' => (string) Str::uuid(), 'project_id' => $project->id, 'created_at' => $createdAt, 'updated_at' => $createdAt];
            $rows[] = $base + match ($table) {
                'iguide_lab_workflows' => ['request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'status' => 'cancelled', 'policy' => '{}'],
                'iguide_lab_runs' => ['case_id' => $case, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'model_id' => 'gpt-image-2.6-sunburst',
                    'provider' => 'openai', 'preset' => 'twilight', 'prompt' => 'fixture', 'options' => '{}', 'status' => 'completed'],
                'iguide_lab_analyses' => ['case_id' => $case, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64),
                    'source_sha256' => str_repeat('e', 64), 'settings' => '{}', 'status' => 'completed'],
                'iguide_lab_composites' => ['case_id' => $case, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'label' => 'Fixture',
                    'preview_path' => 'fixture', 'manifest' => '{}'],
                'iguide_lab_sections' => ['case_id' => $case, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64),
                    'source_sha256' => str_repeat('e', 64), 'view' => '{}', 'status' => 'completed'],
                'iguide_lab_workflow_evaluations', 'iguide_lab_grass_assessments', 'iguide_lab_grass_source_admissions', 'iguide_lab_declutter_removal_plans' => [
                    'step_id' => $stepIds[$i], 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'status' => 'completed', 'data' => '{}'],
                default => throw new \InvalidArgumentException('Unknown record table '.$table),
            };
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table($table)->insert($chunk);
    }

    /** PHP mirror of the run reader (tourContract.ts runTargetSchema/tourRunSchema/estimateSchema, amended in spec 12.1). */
    protected function assertRunDtoValid(array $dto): void
    {
        $uuid = '/\A(?:[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\z/D';
        $wire = json_decode(json_encode($dto, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['id', 'requestId', 'analysisId', 'preset', 'status', 'selectionRevision', 'selectionSha256', 'estimate', 'counts', 'workflows',
            'targets', 'error', 'createdAt', 'updatedAt', 'completedAt'], array_keys($dto));
        foreach (['id', 'requestId', 'analysisId'] as $key) $this->assertMatchesRegularExpression($uuid, $dto[$key], $key);
        $this->assertContains($dto['preset'], ['golden-hour', 'twilight', 'grass', 'declutter']);
        $this->assertContains($dto['status'], ['creating', 'running', 'completed', 'cancelled', 'failed']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $dto['selectionSha256']);
        $e = $dto['estimate'];
        $this->assertInstanceOf(\stdClass::class, $wire->estimate);
        $this->assertSame(['selectionRevision', 'roomsSelected', 'itemsSelected', 'roomsThatFit', 'worstCaseImages', 'limitingReason', 'estimatedMinutes', 'computedAt'], array_keys($e));
        $this->assertInstanceOf(\stdClass::class, $wire->estimate->estimatedMinutes);
        $this->assertSame(['min', 'max'], array_keys($e['estimatedMinutes']));
        foreach (['selectionRevision', 'roomsSelected', 'itemsSelected', 'roomsThatFit', 'worstCaseImages'] as $key) $this->assertIsInt($e[$key], $key);
        $this->assertLessThanOrEqual($e['roomsSelected'], $e['roomsThatFit']);
        $this->assertSame($e['limitingReason'] === null, $e['roomsThatFit'] === $e['roomsSelected'], 'limitingReason is null exactly when every room fits');
        if ($e['limitingReason'] !== null) $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,64}\z/D', $e['limitingReason']);
        $this->assertGreaterThanOrEqual($e['roomsSelected'], $e['itemsSelected']);
        $this->assertLessThanOrEqual(1000, $e['worstCaseImages']);
        $this->assertTrue($e['estimatedMinutes']['min'] <= $e['estimatedMinutes']['max'] && $e['estimatedMinutes']['max'] <= 10000);
        $this->assertNotFalse(strtotime($e['computedAt']));
        $this->assertTrue(is_array($wire->workflows) && is_array($wire->targets), 'workflows and targets are JSON lists');
        $this->assertLessThanOrEqual(10, count($dto['workflows']));
        $this->assertNotEmpty($dto['targets']);
        $states = array_count_values(array_column($dto['targets'], 'state'));
        $n = fn (string ...$s) => array_sum(array_map(fn ($x) => $states[$x] ?? 0, $s));
        $c = $dto['counts'];
        $this->assertSame(['selected', 'admitted', 'pending', 'ready', 'held', 'failed', 'cancelled', 'accepted'], array_keys($c));
        $this->assertSame([count($dto['targets']), count($dto['targets']) - $n('not_admitted'), $n('waiting', 'queued', 'processing'), $n('ready'), $n('held'), $n('failed'), $n('cancelled'),
            count(array_filter($dto['targets'], fn ($t) => $t['acceptance'] !== null))], array_values($c), 'run counts match targets');
        $this->assertSame($dto['selectionRevision'], $e['selectionRevision']);
        $this->assertSame($c['selected'], $e['roomsSelected']);
        $this->assertLessThanOrEqual($e['roomsThatFit'], $c['admitted'], 'amended reader: admitted <= roomsThatFit');
        $requestIds = [$dto['requestId']];
        foreach ($dto['workflows'] as $i => $w) {
            $this->assertSame(['id', 'requestId', 'caseIds'], array_keys($w));
            $this->assertMatchesRegularExpression($uuid, $w['id']);
            $this->assertMatchesRegularExpression($uuid, $w['requestId']);
            $requestIds[] = $w['requestId'];
            $this->assertTrue(is_array($wire->workflows[$i]->caseIds) && $w['caseIds'] !== [] && count($w['caseIds']) <= 20);
            $this->assertSame(count($w['caseIds']), count(array_unique($w['caseIds'])));
            foreach ($w['caseIds'] as $caseId) $this->assertNotEmpty(array_filter($dto['targets'], fn ($t) => $t['caseId'] === $caseId && $t['workflowId'] === $w['id']), 'workflow room has a bound target');
        }
        $this->assertSame(count($requestIds), count(array_unique($requestIds)), 'run and workflow request ids are unique');
        $this->assertSame(count($dto['workflows']), count(array_unique(array_column($dto['workflows'], 'id'))));
        $this->assertSame(count($dto['targets']), count(array_unique(array_map('strtolower', array_column($dto['targets'], 'caseId')))));
        foreach ($dto['targets'] as $i => $t) {
            $this->assertSame(['caseId', 'candidateIds', 'state', 'workflowId', 'workflowItemId', 'hasAttempt', 'reasons', 'acceptance'], array_keys($t));
            $this->assertMatchesRegularExpression($uuid, $t['caseId']);
            $this->assertTrue(is_array($wire->targets[$i]->candidateIds) && is_array($wire->targets[$i]->reasons));
            if (in_array($dto['preset'], ['golden-hour', 'twilight'], true)) $this->assertCount(1, $t['candidateIds']);
            foreach ($t['candidateIds'] as $id) $this->assertMatchesRegularExpression('/\A[a-z0-9]{1,8}\z/D', $id);
            $this->assertLessThanOrEqual(8, count($t['reasons']));
            $this->assertSame(count($t['reasons']), count(array_unique($t['reasons'])));
            foreach ($t['reasons'] as $reason) $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,64}\z/D', $reason);
            $unbound = in_array($t['state'], ['not_admitted', 'waiting'], true);
            if ($unbound) $this->assertNull($t['workflowId']);
            if (! $unbound && $t['state'] !== 'cancelled') $this->assertNotNull($t['workflowId'], 'bound targets name their workflow');
            $this->assertSame($t['workflowId'] === null, $t['workflowItemId'] === null);
            if ($t['workflowId'] !== null) {
                $this->assertMatchesRegularExpression($uuid, $t['workflowItemId']);
                $workflow = collect($dto['workflows'])->firstWhere('id', $t['workflowId']);
                $this->assertNotNull($workflow, 'target workflow is listed');
                $this->assertContains($t['caseId'], $workflow['caseIds']);
            }
            if ($t['state'] === 'ready') $this->assertTrue($t['hasAttempt']);
            if ($t['hasAttempt']) $this->assertContains($t['state'], ['ready', 'held']);
            if ($t['acceptance'] !== null) {
                $this->assertTrue($t['state'] === 'held' && $t['hasAttempt']);
                $this->assertSame(['acceptedAt'], array_keys($t['acceptance']));
            }
            if ($t['state'] === 'not_admitted') $this->assertNotEmpty($t['reasons']);
        }
        $active = in_array($dto['status'], ['creating', 'running'], true);
        $this->assertSame($active, $dto['completedAt'] === null, 'completedAt is null exactly while the run is active');
        if ($dto['status'] === 'completed') $this->assertSame(0, $c['pending']);
        if ($dto['status'] === 'running') $this->assertNotEmpty($dto['workflows']);
        $this->assertSame($dto['status'] === 'failed', $dto['error'] !== null, 'error is set exactly for failed runs');
    }
}
