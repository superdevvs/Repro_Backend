<?php

// A separate process that boots the application and writes through its own
// container-resolved 'mysql' connection, with the writer policy as configured.

if (getenv('MYSQL_WRITER_POLICY_FIXTURE') !== 'isolated-v1' || getenv('DB_DATABASE') !== 'repro_mysql_test'
    || getenv('DB_USERNAME') !== 'repro_test' || getenv('DB_SOCKET') !== '/fixture-socket/mysql.sock') {
    throw new RuntimeException('This worker only runs in the isolated MySQL writer fixture.');
}
[$mode, $directory] = array_slice($argv, 1, 2);
$arguments = array_slice($argv, 3);
if (! is_string($directory) || ! str_starts_with($directory, sys_get_temp_dir().'/repro-writer-policy-') || ! is_dir($directory)) {
    throw new RuntimeException('Invalid marker directory.');
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();

$mark = static fn (string $name) => file_put_contents($directory.'/'.$name, '1');
$await = static function (string $name, float $seconds) use ($directory): void {
    $deadline = microtime(true) + $seconds;
    while (! is_file($directory.'/'.$name) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($directory.'/'.$name)) {
        throw new RuntimeException("Timed out waiting for marker $name.");
    }
};

$db = Illuminate\Support\Facades\DB::connection('mysql');
if (! $db instanceof App\Database\MySqlConnection || $db->getConfig('repro_writer_policy') !== true) {
    throw new RuntimeException('The worker must use the application connection with the writer policy on.');
}

$started = microtime(true);
$result = match ($mode) {
    // Take the token in a normal Laravel transaction and keep it until released.
    'hold' => $db->transaction(function ($db) use ($mark, $await, $arguments) {
        $db->table('_writer_policy_probe')->where('id', 1)->increment('counter');
        $mark('acquired');
        if (isset($arguments[0])) {
            usleep((int) ((float) $arguments[0] * 1_000_000));
        } else {
            $await('release', 40);
        }

        return 'held';
    }),
    // One autocommit write through the query builder.
    'increment' => (function () use ($db, $mark) {
        $mark('ready');
        $db->table('_writer_policy_probe')->where('id', 1)->increment('counter');

        return (int) $db->table('_writer_policy_probe')->where('id', 1)->value('counter');
    })(),
    // Race for the last Google Routes quota unit through the real service.
    'route-reserve' => (function () use ($mark, $await, $arguments) {
        config(['services.google_routes.limit_elements' => 1]);
        $budget = app(App\Services\Scheduling\GoogleRoutesBudget::class);
        $mark('ready-'.$arguments[0]);
        $await('go', 30);

        return $budget->reserve();
    })(),
    default => throw new RuntimeException('Unknown worker mode.'),
};

echo json_encode(['mode' => $mode, 'result' => $result, 'seconds' => round(microtime(true) - $started, 3),
    'level' => $db->transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL;
