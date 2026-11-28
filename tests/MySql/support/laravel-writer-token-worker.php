<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/FixtureWriterTokenConnection.php';

use Tests\MySql\Support\FixtureWriterTokenConnection;

$mode = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if (! in_array($mode, ['hold', 'increment'], true)
    || ! str_starts_with($directory, sys_get_temp_dir().'/repro-laravel-token-') || ! is_dir($directory)) {
    throw new RuntimeException('Invalid Laravel writer-token probe.');
}
$db = FixtureWriterTokenConnection::fromEnvironment();
file_put_contents($directory.'/ready', '1');
$value = $db->transaction(function ($db) use ($mode, $directory) {
    file_put_contents($directory.'/acquired', '1');
    $value = (int) $db->table('_writer_token_probe')->where('id', 1)->value('counter');
    $db->table('_writer_token_probe')->where('id', 1)->update(['counter' => $value + 1]);
    file_put_contents($directory.'/written', '1');
    if ($mode === 'hold') {
        $deadline = microtime(true) + 23;
        while (! is_file($directory.'/release') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! is_file($directory.'/release')) {
            throw new RuntimeException('Probe release timed out.');
        }
    }

    return $value + 1;
});
echo json_encode(['value' => $value, 'state' => $db->fixtureState(), 'active' => $db->getPdo()->inTransaction()], JSON_THROW_ON_ERROR).PHP_EOL;
