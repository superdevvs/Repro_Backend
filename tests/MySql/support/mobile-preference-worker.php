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
$user = App\Models\User::findOrFail($fixture['user_id']);
$token = App\Models\PersonalAccessToken::findOrFail($fixture['token_id']);
$user->withAccessToken($token);
$preferences = App\Models\MobileDevicePreference::defaults();
$preferences['sms'] = $id === '1';
$request = Illuminate\Http\Request::create('/api/mobile-notifications/preferences', 'PUT', [
    'installation_id' => $fixture['installation_id'], 'sequence' => (int) $id, 'preferences' => $preferences,
]);
$request->setUserResolver(fn () => $user);

$paused = false;
$db->listen(function (Illuminate\Database\Events\QueryExecuted $query) use ($directory, $id, &$paused): void {
    if ($paused || ! str_starts_with(strtolower($query->sql), 'select ')
        || ! str_contains($query->sql, '`mobile_device_preferences`')) return;
    $paused = true;
    file_put_contents($directory.'/checked-'.$id, '1');
    if ($id === '1') {
        // Removing the parent-row lock allows the newer request to pass its
        // check while the older request still plans to create the missing row.
        // With the lock the newer request waits and then re-reads sequence 1.
        $deadline = microtime(true) + 3;
        while (! is_file($directory.'/finished-2') && microtime(true) < $deadline) usleep(10_000);
    }
});
file_put_contents($directory.'/ready-'.$id, '1');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/start-'.$id) && microtime(true) < $deadline) usleep(10_000);
if (! is_file($directory.'/start-'.$id)) throw new RuntimeException('Preference race barrier timed out.');

try {
    $response = app(App\Http\Controllers\API\MobileDevicePreferenceController::class)->update($request);
    file_put_contents($directory.'/finished-'.$id, '1');
    Illuminate\Support\Facades\Http::assertNothingSent();
    Illuminate\Support\Facades\Queue::assertNothingPushed();
    Illuminate\Support\Facades\Mail::assertNothingSent();
    Illuminate\Support\Facades\Notification::assertNothingSent();
    echo json_encode(['status' => $response->getStatusCode(), 'sequence' => $response->getData(true)['sequence'],
        'no_external_effects' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['error_type' => get_class($error), 'sqlstate' => $error->getCode(),
        'driver_error' => $error instanceof Illuminate\Database\QueryException ? ($error->errorInfo[1] ?? null) : null,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
