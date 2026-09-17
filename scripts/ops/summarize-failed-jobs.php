<?php

$pdo = new PDO('sqlite:/var/www/backend/database/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rows = $pdo->query('select failed_at, exception, payload from failed_jobs')->fetchAll(PDO::FETCH_ASSOC);
$counts = [];
$oldest = null;
$newest = null;
foreach ($rows as $row) {
    $ex = $row['exception'] ?? '';
    $payload = $row['payload'] ?? '';
    $job = 'unknown';
    if (preg_match('/"displayName":"([^"]+)"/', $payload, $m)) {
        $job = $m[1];
    }
    if (str_contains($ex, 'ClamAvUnavailable')) {
        $kind = 'ClamAvUnavailable';
    } elseif (stripos($ex, 'database is locked') !== false) {
        $kind = 'sqlite_lock';
    } elseif (preg_match('/([A-Za-z0-9_\\\\]+(?:Exception|Error))/', $ex, $m)) {
        $parts = explode('\\', $m[1]);
        $kind = end($parts);
    } else {
        $kind = 'other';
    }
    $key = $kind.' | '.$job;
    $counts[$key] = ($counts[$key] ?? 0) + 1;
    $oldest = $oldest === null || $row['failed_at'] < $oldest ? $row['failed_at'] : $oldest;
    $newest = $newest === null || $row['failed_at'] > $newest ? $row['failed_at'] : $newest;
}
arsort($counts);
echo 'total='.count($rows)." oldest={$oldest} newest={$newest}\n";
foreach ($counts as $key => $n) {
    echo $n."\t".$key."\n";
}
