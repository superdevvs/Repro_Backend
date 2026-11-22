<?php

if (getenv('DB_DATABASE') !== 'repro_mysql_test' || getenv('DB_USERNAME') !== 'repro_test') {
    throw new RuntimeException('Concurrency probes require the isolated test account.');
}
$mode = $argv[1] ?? '';
$id = (int) ($argv[2] ?? 0);
$directory = $argv[3] ?? '';
$loops = (int) ($argv[4] ?? 1);
if (! in_array($id, [1, 2], true) || $loops < 1 || $loops > 100 || ! str_starts_with($directory, sys_get_temp_dir().'/repro-mysql-concurrency-') || ! is_dir($directory)) {
    throw new RuntimeException('Invalid probe arguments.');
}
$pdo = new PDO('mysql:host='.getenv('DB_HOST').';dbname=repro_mysql_test;charset=utf8mb4', 'repro_test', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
for ($i = 0; $i < $loops; $i++) {
    $pdo->beginTransaction();
    $value = (int) $pdo->query('SELECT counter FROM _mysql_migration_concurrency_probe WHERE id='.$id.' FOR UPDATE')->fetchColumn();
    if ($mode === 'hold') {
        file_put_contents($directory.'/locked', '1');
        $deadline = microtime(true) + 6;
        while (! is_file($directory.'/release') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! is_file($directory.'/release')) {
            throw new RuntimeException('Probe release timed out.');
        }
    }
    $pdo->prepare('UPDATE _mysql_migration_concurrency_probe SET counter=? WHERE id=?')->execute([$value + 1, $id]);
    $pdo->commit();
}
echo "completed\n";
