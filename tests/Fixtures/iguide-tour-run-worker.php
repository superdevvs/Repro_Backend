<?php

// Isolated temporary SQLite concurrency fixture for POST tour-analyses/{id}/runs: never load deployment credentials.
// Arguments: database file, analysis id, request id, barrier prefix, worker name, preset, selection revision, selection sha256.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('__tour_run_test_no_environment__');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[, $database, $analysisId, $requestId, $barrier, $name, $preset, $revision, $sha] = $argv;
config(['app.env' => 'testing', 'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $database, 'database.connections.sqlite.url' => null,
    'database.connections.sqlite.busy_timeout' => 5000, 'database.connections.sqlite.journal_mode' => null,
    'database.connections.sqlite.synchronous' => null, 'cache.default' => 'array', 'queue.default' => 'sync']);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::connection()->getPdo();
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Queue::fake();
// The policy snapshot hashes runtime files; the race is about the lab write transaction, so a fixed snapshot is used.
app()->instance(\App\Services\IguideLab\LabWorkflowPolicy::class, new class extends \App\Services\IguideLab\LabWorkflowPolicy {
    public function snapshot(): array { return ['version' => 'fixture', 'lightingRepair' => ['version' => 'fixture']]; }
});
app()->instance('App\Services\IguideLab\LabTourSelections', new \Tests\Support\TourRunSelectionsDouble);
$capabilities = new \Tests\Support\TourRunCapabilitiesDouble;
$capabilities->ready = [$preset => true];
app()->instance('App\Services\IguideLab\LabTourCapabilities', $capabilities);
file_put_contents($barrier.'.'.$name, 'ready');
$deadline = microtime(true) + 30;
while (! is_file($barrier.'.go') && microtime(true) < $deadline) usleep(10000);
if (! is_file($barrier.'.go')) exit(2);
try {
    [$run, $created] = app(\App\Services\IguideLab\LabTourRuns::class)->create(\App\Models\IguideLabTourAnalysis::findOrFail($analysisId),
        ['requestId' => $requestId, 'preset' => $preset, 'selectionRevision' => (int) $revision, 'selectionSha256' => $sha]);
    echo json_encode(['status' => $created ? 202 : 200, 'runId' => $run->id], JSON_THROW_ON_ERROR);
} catch (\App\Exceptions\PublicApiException $error) {
    echo json_encode(['status' => $error->getStatusCode(), 'code' => $error->errorCode, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
