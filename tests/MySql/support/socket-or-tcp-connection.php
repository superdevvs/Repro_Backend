<?php

if (getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('Concurrency probes require the isolated test account.');
}

$socket = getenv('DB_SOCKET');
$address = $socket ? 'unix_socket='.$socket : 'host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: '3306');

return new PDO('mysql:'.$address.';dbname=repro_mysql_test;charset=utf8mb4', 'repro_test', getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
