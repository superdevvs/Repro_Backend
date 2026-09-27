<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('__multi_unit_contention_no_environment__');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'app.env' => 'testing',
    'app.key' => 'base64:'.base64_encode(random_bytes(32)),
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $argv[1],
    'database.connections.sqlite.busy_timeout' => 10000,
    'database.connections.sqlite.journal_mode' => null,
    'database.connections.sqlite.url' => null,
    'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync',
]);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\Queue::fake();
$db = \Illuminate\Support\Facades\DB::connection()->getPdo();
file_put_contents($argv[4].'.ready.'.$argv[5], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[4]) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($argv[4])) {
    exit(2);
}
if ($argv[5] === 'telemetry') {
    $deadline = microtime(true) + 2;
    $count = 0;
    do {
        $db->exec('BEGIN IMMEDIATE');
        $db->exec('UPDATE users SET updated_at = updated_at');
        usleep(3000);
        $db->exec('COMMIT');
        $count++;
        usleep(3000);
    } while (microtime(true) < $deadline);
    echo 'telemetry:'.$count;
    exit(0);
}
$shoot = \App\Models\Shoot::findOrFail($argv[2]);
$actor = \App\Models\User::findOrFail($argv[3]);
$units = $shoot->units()->get()->toArray();
$units[99]['label'] = 'Saved under concurrent writes';
$changes = ['expected_units_revision' => $shoot->units_revision, 'units' => $units];
\App\Support\LockedWrite::run(fn () => \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Services\Shoots\ShootEditablePayloadService::class)->apply($shoot, $changes, $actor)), 'test-unit-write-contention');
echo 'edited';
