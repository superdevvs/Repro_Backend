<?php

// Required only by explicit native fixtures after their no-environment bootstrap.
if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('iGuide concurrency requires the isolated MySQL test account.');
}
config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
    'database.connections.mysql.database' => 'repro_mysql_test', 'database.connections.mysql.username' => 'repro_test']);
\Illuminate\Support\Facades\DB::purge('mysql');
$connection = \Illuminate\Support\Facades\DB::connection();
if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== 'repro_mysql_test'
    || $connection->getConfig('username') !== 'repro_test') throw new RuntimeException('Unsafe iGuide fixture connection.');
// The application fixes READ COMMITTED and five-second lock waits on its MySQL sessions.
