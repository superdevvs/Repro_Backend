<?php

namespace Tests\MySql;

use App\Models\{IguideLabProject, IguideLabTourAnalysis, IguideLabTourAnalysisRequest, IguideLabTourCapture};
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\Support\TourAnalysis\SeedsTourAnalyses;
use Tests\TestCase;

final class IguideLabConcurrencyTest extends TestCase
{
    use FreshDatabaseOutsideTransaction, SeedsTourAnalyses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('repro_mysql_test', DB::connection()->getDatabaseName());
        $this->assertSame('repro_test', DB::connection()->getConfig('username'));
        $this->assertSame(0, DB::transactionLevel());
        $this->installTourDoubles();
        Http::preventStrayRequests();
    }

    public function test_competing_native_writers_cannot_both_extend_from_the_same_expected_limit(): void
    {
        $project = $this->tourProject(['max_runs' => 120]);
        $before = $project->fresh()->getRawOriginal();
        $results = $this->race($project, ['320', '480'], function (string $label, string $barrier) use ($project): array {
            return [PHP_BINARY, base_path('tests/Fixtures/iguide-run-capacity-worker.php'), 'mysql', $project->id, $label, $barrier];
        });
        $statuses = array_column($results, 'status'); sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $winner = collect($results)->firstWhere('status', 200)['receipt'];
        $after = $project->fresh()->getRawOriginal();
        $this->assertSame($winner['maxRuns'], $after['max_runs']);
        $this->assertSame(120, $winner['previousMaxRuns']);
        $this->assertSame(0, $winner['providerCalls']);
        foreach (array_diff(array_keys($before), ['max_runs', 'updated_at']) as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        Http::assertNothingSent();
    }

    public function test_competing_native_creates_for_one_import_share_one_record(): void
    {
        $project = $this->tourProject();
        $import = $this->tourImport($project, 3);
        $results = $this->race($project, ['a', 'b'], function (string $label, string $barrier) use ($project, $import): array {
            return [PHP_BINARY, base_path('tests/Fixtures/iguide-tour-analysis-create-worker.php'), 'mysql', $project->id,
                $import->id, (string) Str::uuid(), $barrier, $label];
        });
        $statuses = array_column($results, 'status'); sort($statuses);
        $this->assertSame([200, 202], $statuses);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, IguideLabTourAnalysis::where('project_id', $project->id)->count());
        $this->assertSame(2, IguideLabTourAnalysisRequest::where('analysis_id', $results[0]['id'])->count());
        $this->assertSame(3, IguideLabTourCapture::where('analysis_id', $results[0]['id'])->count());
        $this->assertSame($this->caseIds($import), IguideLabTourCapture::where('analysis_id', $results[0]['id'])->orderBy('position')->pluck('case_id')->all());
        Http::assertNothingSent();
    }

    private function race(IguideLabProject $project, array $labels, callable $command): array
    {
        $directory = sys_get_temp_dir().'/iguide-native-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700); $barrier = $directory.'/barrier'; $workers = [];
        try {
            foreach ($labels as $label) {
                $worker = new Process($command($label, $barrier), base_path());
                $worker->setTimeout(50); $worker->start(); $workers[$label] = $worker;
            }
            $this->waitForWorkers($workers, fn ($label) => $barrier.'.'.$label);
            DB::transaction(function () use ($project, $workers, $barrier) {
                DB::table('iguide_lab_projects')->where('id', $project->id)->lockForUpdate()->firstOrFail();
                file_put_contents($barrier.'.go', 'go');
                // Both processes enter the real mutation while this parent row
                // is held. Their competing transactions must serialize on it.
                $this->waitForWorkers($workers, fn ($label) => $barrier.'.attempted.'.$label);
            });
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            foreach (glob($directory.'/*') ?: [] as $path) unlink($path);
            rmdir($directory);
        }
    }

    private function waitForWorkers(array $workers, callable $path): void
    {
        $deadline = microtime(true) + 30;
        do {
            $ready = true;
            foreach ($workers as $label => $worker) {
                if (! is_file($path($label))) {
                    $ready = false;
                    if (! $worker->isRunning()) $this->fail($worker->getErrorOutput().$worker->getOutput());
                }
            }
            if ($ready) return;
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->fail('Native iGuide race barrier timed out.');
    }
}
