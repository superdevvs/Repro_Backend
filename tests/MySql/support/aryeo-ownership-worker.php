<?php

if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('This worker only accepts the isolated MySQL test database.');
}
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync']);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Notification::fake();
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'repro_mysql_test' || $db->getConfig('username') !== 'repro_test') {
    throw new RuntimeException('Actual worker connection differs from its isolated test configuration.');
}
$db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
[$directory, $id] = array_slice($argv, 1);
if (! in_array($id, ['1', '2'], true)) throw new RuntimeException('Unknown race participant.');
$fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$export = App\Models\AryeoApiExport::findOrFail($fixture['exports'][$id]);
$paused = false;
$db->listen(function (Illuminate\Database\Events\QueryExecuted $query) use ($directory, $id, &$paused): void {
    if ($paused || ! str_starts_with(strtolower($query->sql), 'select exists(')
        || ! str_contains($query->sql, '`aryeo_api_destinations`')
        || ! str_contains($query->sql, '`active_export_id`')) return;
    $paused = true;
    file_put_contents($directory.'/checked-'.$id, '1');
    if ($id === '1') {
        $startDeadline = microtime(true) + 10;
        while (! is_file($directory.'/attempted-2') && microtime(true) < $startDeadline) usleep(10_000);
        if (! is_file($directory.'/attempted-2')) throw new RuntimeException('Competing ownership attempt did not start.');
        // Without the ownership row lock, process 2 can claim the same free
        // destination while process 1 is paused after its ownership check.
        $deadline = microtime(true) + 3;
        while (! is_file($directory.'/finished-2') && microtime(true) < $deadline) usleep(10_000);
    }
});
file_put_contents($directory.'/ready-'.$id, '1');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/start-'.$id) && microtime(true) < $deadline) usleep(10_000);
if (! is_file($directory.'/start-'.$id)) throw new RuntimeException('Ownership race barrier timed out.');
try {
    file_put_contents($directory.'/attempted-'.$id, '1');
    try {
        $owner = app(App\Services\Aryeo\Api\Guard::class)->claim($export);
        $result = ['status' => 200, 'owner' => $owner];
    } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
        $result = ['status' => $error->getStatusCode(), 'reason' => $error->getMessage()];
    }
    file_put_contents($directory.'/finished-'.$id, '1');
    Illuminate\Support\Facades\Http::assertNothingSent();
    Illuminate\Support\Facades\Queue::assertNothingPushed();
    Illuminate\Support\Facades\Mail::assertNothingSent();
    Illuminate\Support\Facades\Notification::assertNothingSent();
    echo json_encode($result + ['no_external_effects' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['error_type' => get_class($error), 'sqlstate' => $error->getCode(),
        'driver_error' => $error instanceof Illuminate\Database\QueryException ? ($error->errorInfo[1] ?? null) : null,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
