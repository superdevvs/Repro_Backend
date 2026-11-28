<?php

// Isolated temporary SQLite concurrency fixture: never load deployment credentials.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('__run_capacity_test_no_environment__');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $argv[1], 'database.connections.sqlite.url' => null,
    'database.connections.sqlite.busy_timeout' => 10, 'database.connections.sqlite.journal_mode' => null,
    'database.connections.sqlite.synchronous' => null, 'cache.default' => 'array', 'queue.default' => 'sync']);
if ($argv[1] === 'mysql') require __DIR__.'/../MySql/support/use-isolated-iguide-mysql.php';
else \Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::connection()->getPdo();
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Queue::fake();
file_put_contents($argv[4].'.'.$argv[3], 'ready');
$deadline = microtime(true) + 30;
while (!is_file($argv[4].'.go') && microtime(true) < $deadline) usleep(10000);
if (!is_file($argv[4].'.go')) exit(2);
try {
    file_put_contents($argv[4].'.attempted.'.$argv[3], 'attempting');
    $receipt = app(\App\Services\IguideLab\LabRunCapacity::class)->extend($argv[2], (int) $argv[3], 120);
    \Illuminate\Support\Facades\Http::assertNothingSent();
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
    echo json_encode(['status' => 200, 'receipt' => $receipt], JSON_THROW_ON_ERROR);
} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
    echo json_encode(['status' => $error->getStatusCode()], JSON_THROW_ON_ERROR);
}
