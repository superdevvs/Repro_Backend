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
$db->getPdo();
[$directory, $id] = array_slice($argv, 1);
if (! in_array($id, ['1', '2'], true)) throw new RuntimeException('Unknown race participant.');
$fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);

// Pause after the real duplicate lookup but before the insert. With the row
// lock the other request cannot reach this boundary until we commit. Without
// it both requests see no event, then race the unique constraint.
$paused = false;
$db->listen(function (Illuminate\Database\Events\QueryExecuted $query) use ($directory, $id, &$paused): void {
    if ($paused || ! str_starts_with(strtolower($query->sql), 'select ')
        || ! str_contains($query->sql, '`shoot_provider_delivery_verifications`')
        || ! str_contains($query->sql, '`event_fingerprint`')) return;
    $paused = true;
    file_put_contents($directory.'/checked-'.$id, '1');
    $other = $id === '1' ? '2' : '1';
    $deadline = microtime(true) + 3;
    while (! is_file($directory.'/checked-'.$other) && microtime(true) < $deadline) usleep(10_000);
});
file_put_contents($directory.'/ready-'.$id, '1');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/start') && microtime(true) < $deadline) usleep(10_000);
if (! is_file($directory.'/start')) throw new RuntimeException('Provider race barrier timed out.');
try {
    $observation = app(App\Services\Shoots\ProviderAutoDeliveryService::class)->observe(
        App\Models\Shoot::findOrFail($fixture['shoot_id']), 'iguide', $fixture['parsed'],
        $fixture['source'], $fixture['fingerprint'], false,
    );
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'error_type' => get_class($error),
        'sqlstate' => $error->getCode(),
        'driver_error' => $error instanceof Illuminate\Database\QueryException ? ($error->errorInfo[1] ?? null) : null,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
Illuminate\Support\Facades\Http::assertNothingSent();
Illuminate\Support\Facades\Queue::assertNothingPushed();
Illuminate\Support\Facades\Mail::assertNothingSent();
Illuminate\Support\Facades\Notification::assertNothingSent();
echo json_encode(['observation_id' => $observation, 'no_provider_requests' => true], JSON_THROW_ON_ERROR);
