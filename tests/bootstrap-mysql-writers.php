<?php

// The multi-writer suite runs only against the disposable, socket-only MySQL
// fixture described in tests/MySqlWriters/README.md. It never wraps a test in a
// transaction: the token would then be held for the whole test.
$expected = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_DATABASE' => 'repro_mysql_test',
    'DB_USERNAME' => 'repro_test',
    'DB_SOCKET' => '/fixture-socket/mysql.sock',
    'MYSQL_WRITER_POLICY_FIXTURE' => 'isolated-v1',
];
foreach ($expected as $name => $value) {
    if (getenv($name) !== $value) {
        throw new RuntimeException('The MySQL writer suite requires the isolated socket-only fixture ('.$name.').');
    }
}
if (! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', (string) getenv('REPRO_MYSQL_EXPECTED_SERVER_UUID'))) {
    throw new RuntimeException('The MySQL writer suite requires the fixture server UUID.');
}

require dirname(__DIR__).'/vendor/autoload.php';
