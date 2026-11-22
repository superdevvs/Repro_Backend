<?php

// Isolated temporary SQLite concurrency fixture for LabTourAnalyses::create: never load deployment credentials.
// argv: 1 database file, 2 project id, 3 import id, 4 request id, 5 barrier prefix, 6 worker label.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('__tour_analysis_create_test_no_environment__');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $argv[1], 'database.connections.sqlite.url' => null,
    'database.connections.sqlite.busy_timeout' => 2000, 'database.connections.sqlite.journal_mode' => null,
    'database.connections.sqlite.synchronous' => null, 'cache.default' => 'array', 'queue.default' => 'sync',
    'iguide-lab-tour.tour_analysis_enabled' => true]);
if ($argv[1] === 'mysql') require __DIR__.'/../MySql/support/use-isolated-iguide-mysql.php';
else \Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::connection()->getPdo();
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Queue::fake();
require __DIR__.'/../Support/TourAnalysis/survey-job-stand-in.php';
// WP2 double (same as the feature tests): the caps pre-check reads the attempt ledger through it.
app()->instance(\App\Services\IguideLab\LabTourSurveys::class, new \Tests\Support\TourAnalysis\FakeTourSurveys);
file_put_contents($argv[5].'.'.$argv[6], 'ready');
$deadline = microtime(true) + 40;
while (! is_file($argv[5].'.go') && microtime(true) < $deadline) usleep(10000);
if (! is_file($argv[5].'.go')) exit(2);
try {
    file_put_contents($argv[5].'.attempted.'.$argv[6], 'attempting');
    $project = \App\Models\IguideLabProject::findOrFail($argv[2]);
    [$analysis, $queued] = app(\App\Services\IguideLab\LabTourAnalyses::class)->create($project,
        ['requestId' => $argv[4], 'importId' => $argv[3], 'preset' => 'golden-hour']);
    \Illuminate\Support\Facades\Http::assertNothingSent();
    echo json_encode(['status' => $queued ? 202 : 200, 'id' => $analysis->id], JSON_THROW_ON_ERROR);
} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
    echo json_encode(['status' => $error->getStatusCode()], JSON_THROW_ON_ERROR);
}
