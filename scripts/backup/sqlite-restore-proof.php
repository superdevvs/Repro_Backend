<?php

declare(strict_types=1);

// Restore proof for a nightly SQLite copy (Phase 0 item 1).
//
//   php sqlite-restore-proof.php COPY SCRATCH_DIR [--keep-restored]
//
// Checks the copy against its .sha256 file, restores it to a new file in
// SCRATCH_DIR, checks the restored bytes again, opens it read-write the way the
// app would, runs a full integrity_check, takes and releases a write lock, and
// counts every table. The restored file is deleted unless --keep-restored.
// SCRATCH_DIR must never be the live application; /var/www is refused.

function proofFail(string $message): never
{
    throw new RuntimeException($message);
}

$keep = in_array('--keep-restored', $argv, true);
$positional = array_values(array_filter(array_slice($argv, 1), static fn (string $argument): bool => $argument !== '--keep-restored'));
$restored = null;
$exit = 0;
try {
    if (count($positional) !== 2) {
        proofFail('Usage: sqlite-restore-proof.php COPY SCRATCH_DIR [--keep-restored]');
    }
    [$copy, $scratch] = $positional;
    if (is_link($copy) || ! is_file($copy) || ! preg_match('/\Adatabase-\d{8}-\d{6}\.sqlite\z/', basename($copy))) {
        proofFail('The copy must be a database-YYYYMMDD-HHMMSS.sqlite file, not a link.');
    }
    $scratchPath = realpath($scratch);
    if ($scratchPath === false || is_link($scratch) || ! is_dir($scratchPath)) {
        proofFail('The scratch directory must exist and must not be a link.');
    }
    if ($scratchPath === '/var/www' || str_starts_with($scratchPath, '/var/www/')) {
        proofFail('Restore proofs never write inside /var/www.');
    }
    $sidecar = @file_get_contents($copy.'.sha256');
    if ($sidecar === false || ! preg_match('/\A([0-9a-f]{64})  '.preg_quote(basename($copy), '/').'\n\z/', $sidecar, $match)) {
        proofFail('The copy has no valid .sha256 checksum file.');
    }
    $expected = $match[1];
    if (! hash_equals($expected, hash_file('sha256', $copy))) {
        proofFail('The copy does not match its checksum file.');
    }

    $restored = $scratchPath.'/restored-'.basename($copy, '.sqlite').'-'.bin2hex(random_bytes(4)).'.sqlite';
    $input = fopen($copy, 'rb');
    $output = fopen($restored, 'xb');
    if ($input === false || $output === false || ! chmod($restored, 0600)
        || stream_copy_to_stream($input, $output) === false || ! fsync($output)) {
        proofFail('Unable to restore the copy to the scratch directory.');
    }
    fclose($input);
    fclose($output);
    if (! hash_equals($expected, hash_file('sha256', $restored))) {
        proofFail('The restored file does not match the checksum file.');
    }

    $database = new SQLite3($restored, SQLITE3_OPEN_READWRITE);
    $database->enableExceptions(true);
    $integrity = $database->querySingle('PRAGMA integrity_check');
    if ($integrity !== 'ok') {
        proofFail('The restored database failed integrity_check.');
    }
    // Prove the restore can take the write lock the app needs, then release it.
    $database->exec('BEGIN IMMEDIATE');
    $database->exec('ROLLBACK');
    $names = [];
    $result = $database->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $names[] = $row['name'];
    }
    $counts = [];
    foreach ($names as $name) {
        $counts[$name] = (int) $database->querySingle('SELECT COUNT(*) FROM "'.str_replace('"', '""', $name).'"');
    }
    $foreignKeyIssues = 0;
    $result = $database->query('PRAGMA foreign_key_check');
    while ($result->fetchArray(SQLITE3_NUM)) {
        $foreignKeyIssues++;
    }
    $database->close();

    echo json_encode([
        'restore_proof' => 'passed',
        'copy' => basename($copy),
        'sha256' => $expected,
        'integrity_check' => 'ok',
        'tables' => count($names),
        'rows' => array_sum($counts),
        'row_counts' => $counts,
        // Reported, not failed: production keeps reviewed historical exceptions.
        'foreign_key_issues' => $foreignKeyIssues,
        'migrations' => $counts['migrations'] ?? null,
        'restored_file' => $keep ? $restored : null,
    ], JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    $exit = 1;
    $message = $error instanceof RuntimeException ? $error->getMessage() : 'The restore proof failed: '.$error->getMessage();
    fwrite(STDERR, 'SQLite restore proof failed: '.$message.PHP_EOL);
} finally {
    if ($restored !== null && ($exit !== 0 || ! $keep)) {
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            if (is_file($restored.$suffix) && ! is_link($restored.$suffix)) {
                unlink($restored.$suffix);
            }
        }
    }
}
exit($exit);
