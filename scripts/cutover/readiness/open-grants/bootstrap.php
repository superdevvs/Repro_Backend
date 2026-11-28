<?php

use Illuminate\Foundation\Testing\RefreshDatabaseState;

$socket = '/fixture/socket/run/mysql.sock';
if (! is_file('/.dockerenv') || getenv('DB_SOCKET') !== $socket || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'repro_dashboard'
    || getenv('DB_USERNAME') !== 'repro_app' || ! is_file('/fixture/socket/.repro-mysql-fixture')
    || is_readable('/fixture/socket/root.cnf') || is_readable('/fixture/socket/repro_maint.cnf')
    || is_readable('/fixture/socket/repro_cutover_gate.cnf')) {
    throw new RuntimeException('The restricted socket fixture and unprivileged application process are required.');
}

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$pdo = new PDO('mysql:unix_socket='.$socket.';dbname=repro_dashboard', 'repro_app', getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity = $pdo->query('SELECT CURRENT_USER(), DATABASE(), @@server_uuid, @@skip_networking, CURRENT_ROLE()')
    ->fetch(PDO::FETCH_NUM);
$uuid = trim(file_get_contents('/fixture/socket/server_uuid'));
if (getenv('REPRO_MYSQL_EXPECTED_SERVER_UUID') !== $uuid
    || $identity !== ['repro_app@localhost', 'repro_dashboard', $uuid, 1, 'NONE']) {
    throw new RuntimeException('The application session failed the fixture identity or active-role check.');
}
$observed = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
$expected = [
    'GRANT USAGE ON *.* TO `repro_app`@`localhost`',
    'GRANT SELECT, INSERT, UPDATE, DELETE ON `repro\\_dashboard`.* TO `repro_app`@`localhost`',
    'GRANT SELECT, UPDATE (`id`) ON `repro_mysql_control`.`writer_token` TO `repro_app`@`localhost`',
];
sort($observed);
sort($expected);
if ($observed !== $expected) {
    throw new RuntimeException('Expected exact open application grants, observed '.json_encode($observed));
}
printf("app=%s schema=%s server_uuid=%s role=%s grants=%s\n",
    $identity[0], $identity[1], $uuid, $identity[4], json_encode($observed));
$pdo = null;
// The maintenance account loaded the candidate schema. Existing HTTP tests wrap their DML in rollbacks.
RefreshDatabaseState::$migrated = true;
