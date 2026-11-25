<?php

if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('Only the isolated MySQL test database is permitted.');
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
    throw new RuntimeException('The actual worker database is not the isolated test target.');
}
// The application fixes READ COMMITTED and five-second lock waits on its MySQL sessions.
[$directory, $slot] = array_slice($argv, 1);
if (! in_array($slot, ['a', 'b'], true)) {
    throw new RuntimeException('Unknown synthetic device.');
}
$fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$user = App\Models\User::findOrFail($fixture['user_id']);
$request = Illuminate\Http\Request::create('/synthetic-read-state', 'POST');
$request->setUserResolver(static fn () => $user);
$paused = false;
// Widen the real initial duplicate-check boundary, then exercise repeated
// updates with two devices. No transaction/query behavior is replaced, and
// failed merges are never retried by this fixture.
$db->listen(function (Illuminate\Database\Events\QueryExecuted $query) use ($directory, $slot, &$paused): void {
    if ($paused || ! str_starts_with(strtolower($query->sql), 'insert ignore into `notification_read_states`')) {
        return;
    }
    if ($query->connection->transactionLevel() === 0) {
        throw new RuntimeException('Receipt insertion escaped its transaction.');
    }
    $paused = true;
    file_put_contents($directory.'/inserted-'.$slot, '1');
    $other = $slot === 'a' ? 'b' : 'a';
    $deadline = microtime(true) + 2;
    while (! is_file($directory.'/inserted-'.$other) && microtime(true) < $deadline) {
        usleep(10_000);
    }
});
file_put_contents($directory.'/ready-'.$slot, '1');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/start') && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! is_file($directory.'/start')) {
    throw new RuntimeException('Synthetic device start barrier timed out.');
}
try {
    $states = app(App\Services\NotificationReadStateService::class);
    for ($index = 0; $index < 100; $index++) {
        $states->merge($request, ['device-'.$slot.'-'.$index], $fixture['watermark'] - ($slot === 'b' ? 1000 : 0));
        $states->get($request);
    }
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['error_type' => get_class($error), 'sqlstate' => $error->getCode(),
        'driver_error' => $error instanceof Illuminate\Database\QueryException ? ($error->errorInfo[1] ?? null) : null], JSON_THROW_ON_ERROR));
    exit(1);
}
Illuminate\Support\Facades\Http::assertNothingSent();
Illuminate\Support\Facades\Queue::assertNothingPushed();
Illuminate\Support\Facades\Mail::assertNothingSent();
Illuminate\Support\Facades\Notification::assertNothingSent();
echo json_encode(['paused_at_real_insert' => $paused, 'no_external_effects' => true, 'committed_merges' => 100], JSON_THROW_ON_ERROR);
