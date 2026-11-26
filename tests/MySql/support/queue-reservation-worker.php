<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'repro_mysql_test'
    || config('database.connections.mysql.username') !== 'repro_test') {
    throw new RuntimeException('Queue probes require the separately permissioned MySQL test database.');
}
[$script, $queue, $directory, $slot] = $argv;
if (! preg_match('/\Amigration-probe-[a-f0-9-]{36}\z/', $queue) || ! in_array($slot, ['a', 'b'], true)
    || ! str_starts_with($directory, sys_get_temp_dir().'/migration-queue-') || ! is_dir($directory)) {
    throw new RuntimeException('Invalid local queue probe arguments.');
}
file_put_contents($directory.'/'.$slot.'.ready', '1');
$deadline = microtime(true) + 20;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Local queue probe barrier timed out.');
    }
    usleep(10_000);
}
// Reserve only. Never invoke fire(), a worker, or an actual job handler.
$job = $app['queue']->connection('database')->pop($queue);
echo json_encode(['id' => $job ? (string) $job->getJobId() : null], JSON_THROW_ON_ERROR);
