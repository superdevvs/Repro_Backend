<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Database\MySqlWriterToken;
use App\Database\WriterTokenTimeout;

$mode = $argv[1] ?? '';
$directory = $argv[2] ?? '';
$slot = $argv[3] ?? '';
if (getenv('MYSQL_WRITER_TOKEN_FIXTURE') !== 'isolated-v1' || getenv('DB_DATABASE') !== 'repro_mysql_test'
    || getenv('DB_USERNAME') !== 'repro_test' || getenv('DB_SOCKET') !== '/fixture-socket/mysql.sock'
    || ! in_array($mode, ['increment', 'hold', 'timeout'], true) || ! in_array($slot, ['a', 'b'], true)
    || ! str_starts_with($directory, sys_get_temp_dir().'/repro-writer-token-') || ! is_dir($directory)) {
    throw new RuntimeException('Writer token probes require the explicitly provisioned disposable fixture.');
}
$pdo = require __DIR__.'/socket-or-tcp-connection.php';
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
$pdo->exec('SET SESSION lock_wait_timeout = 5');
file_put_contents($directory.'/'.$slot.'.ready', '1');
$started = microtime(true);
try {
    (new MySqlWriterToken)->beginAndAcquire($pdo, getenv('MYSQL_WRITER_TOKEN_UUID'), 'repro_mysql_test');
    file_put_contents($directory.'/'.$slot.'.acquired', '1');
    $value = (int) $pdo->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn();
    $pdo->prepare('UPDATE _writer_token_probe SET counter = ? WHERE id = 1')->execute([$value + 1]);
    file_put_contents($directory.'/'.$slot.'.written', '1');
    if ($mode === 'hold') {
        $deadline = microtime(true) + 12;
        while (! is_file($directory.'/'.$slot.'.release') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! is_file($directory.'/'.$slot.'.release')) {
            throw new RuntimeException('Probe release timed out.');
        }
    }
    $pdo->commit();
    echo json_encode(['status' => 'committed', 'active' => $pdo->inTransaction()], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (WriterTokenTimeout $error) {
    echo json_encode(['status' => 'timeout', 'active' => $pdo->inTransaction(), 'seconds' => microtime(true) - $started,
        'framework_retryable' => (new Illuminate\Database\ConcurrencyErrorDetector)->causedByConcurrencyError($error)], JSON_THROW_ON_ERROR).PHP_EOL;
}
