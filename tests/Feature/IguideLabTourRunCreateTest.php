<?php

namespace Tests\Feature;

use App\Exceptions\PublicApiException;
use App\Jobs\MonitorIguideLabTourRun;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabWorkflow;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourErrors;
use App\Services\IguideLab\LabTourRunCapacity;
use App\Services\IguideLab\LabTourRuns;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\TourRunFixtures;
use Tests\Support\TourRunSelectionsDouble;
use Tests\TestCase;

/** POST runs (spec 9.1, 10.3 E6): replay, conflicts in order, duplicate-active guard, frozen estimate and targets. */
class IguideLabTourRunCreateTest extends TestCase
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

    public function test_run_is_202_creating_with_waiting_and_not_admitted_targets(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 30, options: ['files' => false]);
        // A removed default leaves that room out of the run (effective selection, spec 7.3).
        $selections = $analysis->selections;
        $selections['golden-hour'] = ['revision' => 1, 'changes' => [$cases[2] => ['add' => [], 'remove' => ['g2']]]];
        IguideLabTourAnalysis::whereKey($analysis->id)->update(['selections' => json_encode($selections)]);
        $input = $this->runInput($analysis->fresh(), 'golden-hour');
        // Whatever order the selection service returns, rooms are admitted and listed in tour order (D2).
        $this->app->instance('App\Services\IguideLab\LabTourSelections', new class extends TourRunSelectionsDouble {
            public function effective(\App\Services\IguideLab\LabTourView $view, string $preset): array { return array_reverse(parent::effective($view, $preset), true); }
        });

        [$run, $created, $dto] = app(LabTourRuns::class)->create($analysis->fresh(), $input);

        $this->assertTrue($created);
        $this->assertSame(0, IguideLabWorkflow::count(), 'no workflow before the monitor runs (D3)');
        $this->assertSame(['creating', null, 8, 1], [$run->status, $run->completed_at, $run->chunk_size, $run->selection_revision]);
        $this->assertSame(hash('sha256', json_encode(['analysisId' => $analysis->id, 'preset' => 'golden-hour', 'selectionRevision' => 1,
            'selectionSha256' => $input['selectionSha256']])), $run->request_hash);
        $this->assertSame(2, $analysis->fresh()->record_revision);
        $this->assertRunDtoValid($dto);
        $this->assertSame([$run->id, $input['requestId'], $analysis->id, 'golden-hour', 'creating', 1, $input['selectionSha256'], [], null, null],
            [$dto['id'], $dto['requestId'], $dto['analysisId'], $dto['preset'], $dto['status'], $dto['selectionRevision'], $dto['selectionSha256'],
                $dto['workflows'], $dto['error'], $dto['completedAt']]);
        $this->assertSame(['selected' => 29, 'admitted' => 24, 'pending' => 24, 'ready' => 0, 'held' => 0, 'failed' => 0, 'cancelled' => 0, 'accepted' => 0], $dto['counts']);
        $this->assertSame([1, 29, 29, 24, 72, 'evaluation_ceiling', ['min' => 72, 'max' => 120]], array_slice(array_values($dto['estimate']), 0, 7));
        $expected = array_values(array_diff($cases, [$cases[2]]));
        $this->assertSame($expected, array_column($dto['targets'], 'caseId'), 'targets in tour order');
        $this->assertSame([...array_fill(0, 24, 'waiting'), ...array_fill(0, 5, 'not_admitted')], array_column($dto['targets'], 'state'));
        $this->assertSame([['g0'], ['g1'], ['g3']], array_slice(array_column($dto['targets'], 'candidateIds'), 0, 3));
        $this->assertSame([[], ['evaluation_ceiling']], [$dto['targets'][0]['reasons'], $dto['targets'][28]['reasons']]);
        $this->assertSame([0, 1, 3], IguideLabTourRunTarget::where('run_id', $run->id)->orderBy('position')->limit(3)->pluck('position')->all(), 'position = capture order');

        $this->queue->assertPushed(MonitorIguideLabTourRun::class, fn ($job) => $job->runId === $run->id && $job->seq === null && Str::isUuid($job->token));
        $this->assertCount(1, $this->queue->pushed(MonitorIguideLabTourRun::class));
        $this->assertSame(LabTourConfig::queueConnection(), $this->queue->pushed(MonitorIguideLabTourRun::class)[0]->connection);
    }

    public function test_replay_returns_200_and_reuse_with_another_body_is_409(): void
    {
        [$analysis] = $this->seedAnalysis(rooms: 3, options: ['files' => false]);
        $input = $this->runInput($analysis, 'twilight');
        [$run, $created] = app(LabTourRuns::class)->create($analysis, $input);
        $this->assertTrue($created);
        $revision = $analysis->fresh()->record_revision;
        config(['iguide-lab-tour.tour_runs_paused' => true]);

        // Replay before any check (LabWorkflows.php:36-38): even while paused and with an active run.
        [$again, $createdAgain, $dto] = app(LabTourRuns::class)->create($analysis->fresh(), $input + ['requestId' => strtoupper($input['requestId'])]);
        $this->assertSame([$run->id, false], [$again->id, $createdAgain]);
        $this->assertSame($run->id, $dto['id']);
        $this->assertRunDtoValid($dto);
        $this->assertSame($revision, $analysis->fresh()->record_revision);
        $this->assertCount(1, $this->queue->pushed(MonitorIguideLabTourRun::class), 'a replay pushes nothing');

        foreach ([['preset' => 'golden-hour'], ['selectionRevision' => 1], ['selectionSha256' => str_repeat('0', 64)]] as $change) {
            $this->assertPublicError('requestReused', fn () => app(LabTourRuns::class)->create($analysis->fresh(), $change + $input));
        }
        [$other] = $this->seedAnalysis(rooms: 1, options: ['files' => false]);
        $this->assertPublicError('requestReused', fn () => app(LabTourRuns::class)->create($other, $this->runInput($other, 'twilight', ['requestId' => $input['requestId']])));
        $this->assertSame(1, IguideLabTourRun::count());
    }

    public function test_conflicts_in_documented_order_with_exact_texts(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 3, options: ['files' => false]);
        $reused = $this->seedRun($analysis, 'twilight', ['ready'], 'completed');
        $active = $this->seedRun($analysis, 'golden-hour', ['waiting'], 'creating');
        for ($i = 0; $i < 38; $i++) $this->seedRun($analysis, 'twilight', ['ready'], 'completed');
        $this->assertSame(40, IguideLabTourRun::where('analysis_id', $analysis->id)->count());
        IguideLabTourAnalysis::whereKey($analysis->id)->update(['status' => 'analysing', 'completed_at' => null]);
        config(['iguide-lab-tour.tour_runs_paused' => true]);
        $this->capabilities->ready = ['golden-hour' => false];
        $this->project->update(['used_runs' => 120]);
        $good = $this->runInput($analysis, 'golden-hour');
        $input = ['requestId' => $reused->request_id, 'selectionRevision' => 7, 'selectionSha256' => str_repeat('9', 64)] + $good;
        $runs = IguideLabTourRun::count();
        $revision = $analysis->fresh()->record_revision;
        $post = function () use (&$input, $analysis) {
            return app(LabTourRuns::class)->create($analysis->fresh(), $input);
        };

        $this->assertPublicError('requestReused', $post);
        $input['requestId'] = $good['requestId'];
        $this->assertPublicError('analysisNotReady', $post);
        IguideLabTourAnalysis::whereKey($analysis->id)->update(['status' => 'cancelled', 'completed_at' => now()]);
        $this->assertPublicError('runsPaused', $post);
        config(['iguide-lab-tour.tour_runs_paused' => false]);
        $this->assertPublicError('presetNotRunnable', $post);
        $this->capabilities->ready = [];
        $this->assertPublicError('staleSelection', $post);
        $input['selectionRevision'] = 0;
        $this->assertPublicError('selectionMismatch', $post);
        $input['selectionSha256'] = $good['selectionSha256'];
        $this->assertPublicError('duplicateActiveRun', $post);
        DB::table('iguide_lab_tour_runs')->where('id', $active->id)->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertPublicError('runLimitReached', $post);
        IguideLabTourRun::whereKey($reused->id)->delete();
        $this->assertPublicError('nothingFits', $post);
        $this->assertSame([$runs - 1, $revision], [IguideLabTourRun::count(), $analysis->fresh()->record_revision], 'no conflict writes anything');
        $this->assertCount(0, $this->queue->pushed(MonitorIguideLabTourRun::class));

        $this->project->update(['used_runs' => 0]);
        [$run, $created, $dto] = $post();
        $this->assertTrue($created);
        $this->assertSame(['creating', 3], [$dto['status'], $dto['counts']['admitted']]);
        $this->assertSame($cases, array_column($dto['targets'], 'caseId'));
    }

    public function test_duplicate_active_guard_covers_editor_and_other_analyses(): void
    {
        [$analysis, $cases] = $this->seedAnalysis(rooms: 3);
        // (b) the manual editor has a non-terminal golden-hour item for a selected case.
        $editor = app(LabWorkflows::class)->start($this->project, ['requestId' => (string) Str::uuid(),
            'items' => [['caseId' => $cases[1], 'preset' => 'golden-hour', 'style' => null, 'instructions' => 'Keep the architecture.']]]);
        $this->assertPublicError('duplicateActiveRun', fn () => $this->createRun($analysis, 'golden-hour'));
        [$twilight, $created] = $this->createRun($analysis, 'twilight');
        $this->assertTrue($created, 'another preset is not blocked');
        app(LabWorkflows::class)->cancel($editor);

        // (b) a bound, non-terminal tour target of another analysis is an item of the same kind.
        [$other] = $this->seedAnalysis(rooms: 3, options: ['cases' => $cases]);
        [$otherRun] = $this->createRun($other, 'golden-hour');
        $this->tick($otherRun);
        $this->assertSame(['queued', 'queued', 'queued'], IguideLabTourRunTarget::where('run_id', $otherRun->id)->orderBy('position')->pluck('state')->all());
        $this->assertPublicError('duplicateActiveRun', fn () => $this->createRun($analysis, 'golden-hour'));
        app(LabWorkflows::class)->cancel(IguideLabWorkflow::findOrFail(IguideLabTourRunTarget::where('run_id', $otherRun->id)->value('workflow_id')));
        $this->tick($otherRun);
        [$third] = $this->seedAnalysis(rooms: 3, options: ['cases' => $cases]);

        // (c) a waiting target of another analysis's active run of the same preset.
        $this->seedRun($third, 'golden-hour', ['not_admitted', 'not_admitted', 'waiting'], 'creating');
        $this->assertPublicError('duplicateActiveRun', fn () => $this->createRun($analysis, 'golden-hour'));
        DB::table('iguide_lab_tour_run_targets')->where('state', 'waiting')->update(['state' => 'cancelled']);
        [, $created] = $this->createRun($analysis, 'golden-hour');
        $this->assertTrue($created);
        $this->assertSame('creating', $twilight->fresh()->status, 'never ticked');
    }

    public function test_grass_env_flag_true_is_still_not_runnable_in_d1(): void
    {
        config(['iguide-lab-tour.grass_runs_enabled' => true, 'iguide-lab-tour.declutter_runs_enabled' => true, 'iguide-lab.grass_workflow_enabled' => true]);
        $this->assertFalse(LabTourConfig::grassRunsEnabled());
        $this->assertFalse(LabTourConfig::declutterRunsEnabled());
        $this->assertFalse(LabTourRunCapacity::presetRunsEnabled('grass'));
        [$analysis] = $this->seedAnalysis(rooms: 2, options: ['files' => false, 'grass' => true]);
        // Even a capability that wrongly reports grass as ready cannot start a grass run in D1.
        $this->capabilities->ready = ['grass' => true, 'declutter' => true];
        $this->assertPublicError('presetNotRunnable', fn () => $this->createRun($analysis, 'grass'));
        $this->assertPublicError('presetNotRunnable', fn () => $this->createRun($analysis, 'declutter'));
        $this->assertSame(0, IguideLabTourRun::count());
        $this->assertSame([], array_column(array_filter($this->capabilities->runnableCalls, fn ($c) => in_array($c['preset'], ['grass', 'declutter'], true)), 'preset'),
            'the D1 flag check runs before the capability is asked');
    }

    public function test_concurrent_run_posts_for_one_preset(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite WAL/BEGIN IMMEDIATE child-process fixture; duplicate-active, replay and transaction behavior are tested on both engines in this class.');
        }
        [$analysis] = $this->seedAnalysis(rooms: 3, options: ['files' => false]);
        $input = $this->runInput($analysis, 'golden-hour');
        $directory = sys_get_temp_dir().'/iguide-tour-run-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/isolated.sqlite';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        foreach (DB::select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END") as $object) {
            $pdo->exec($object->sql);
        }
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'iguide_lab_%'") as $table) {
            foreach (DB::table($table->name)->get() as $row) {
                $row = (array) $row;
                $pdo->prepare('INSERT INTO '.$table->name.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')')->execute(array_values($row));
            }
        }
        $workers = [];
        try {
            foreach (['a', 'b'] as $name) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/iguide-tour-run-worker.php'), $database, $analysis->id, (string) Str::uuid(),
                    $directory.'/barrier', $name, 'golden-hour', (string) $input['selectionRevision'], $input['selectionSha256']], base_path());
                $worker->setTimeout(60);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 45;
            while (! is_file($directory.'/barrier.a') || ! is_file($directory.'/barrier.b')) {
                if (microtime(true) >= $deadline) $this->fail('Isolated run workers did not initialize.');
                foreach ($workers as $worker) if (! $worker->isRunning()) $this->fail($worker->getErrorOutput().$worker->getOutput());
                usleep(10000);
            }
            // Both real child processes contend on this writer lock before either reads the runs.
            $pdo->exec('BEGIN IMMEDIATE');
            file_put_contents($directory.'/barrier.go', 'go');
            usleep(100000);
            $pdo->exec('COMMIT');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            usort($results, fn ($a, $b) => $a['status'] <=> $b['status']);
            $this->assertSame([202, 409], array_column($results, 'status'));
            $this->assertSame(['tour_run_duplicate_active', LabTourErrors::MESSAGES['duplicateActiveRun'][2]], [$results[1]['code'], $results[1]['message']]);
            $this->assertSame([[$results[0]['runId']]], [array_column($pdo->query('SELECT id FROM iguide_lab_tour_runs')->fetchAll(\PDO::FETCH_ASSOC), 'id')]);
            $this->assertSame('3', (string) $pdo->query('SELECT count(*) FROM iguide_lab_tour_run_targets')->fetchColumn());
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop(1);
            $pdo = null;
            foreach (glob($directory.'/*') ?: [] as $file) @unlink($file);
            @rmdir($directory);
        }
    }

    private function assertPublicError(string $key, callable $call): void
    {
        [$status, $code, $text] = LabTourErrors::MESSAGES[$key];
        try {
            $call();
            $this->fail('Expected the '.$key.' conflict.');
        } catch (PublicApiException $error) {
            $this->assertSame([$status, $code, $text], [$error->getStatusCode(), $error->errorCode, $error->getMessage()], $key);
        }
    }
}
