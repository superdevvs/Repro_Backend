<?php

// Isolated concurrency fixture. No environment file or provider is used.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('__auth_security_test_no_environment__');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'app.env' => 'testing',
    'app.key' => 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $argv[1],
    'database.connections.sqlite.busy_timeout' => 10000,
    'database.connections.sqlite.journal_mode' => null,
    'database.connections.sqlite.synchronous' => null,
    'database.connections.sqlite.url' => null,
    'cache.default' => 'array',
    'mail.default' => 'array',
    'queue.default' => 'sync',
    'hashing.bcrypt.rounds' => 4,
]);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::connection()->getPdo();
file_put_contents($argv[5].'.ready.'.$argv[6], 'ready');
$deadline = microtime(true) + 15;
while (!is_file($argv[5]) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($argv[5])) {
    fwrite(STDERR, 'Barrier timed out.');
    exit(2);
}
$user = app(\App\Services\Users\PasswordRecoveryService::class)->consume($argv[2], $argv[3], $argv[4]);
echo $user ? 'consumed' : 'invalid';
