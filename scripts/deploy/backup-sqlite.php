<?php

declare(strict_types=1);

// SQLite's online backup includes committed WAL data in one consistent snapshot.
// Copying database/WAL/SHM separately while workers run cannot provide that guarantee.
$created = false;
$destination = null;
$source = null;
$output = $argv[2] ?? '';
try {
    if ($argc !== 3 || ! is_file($argv[1]) || is_link($output) || file_exists($output)) {
        throw new RuntimeException('Expected an existing source database and a new backup path.');
    }
    if (! class_exists(SQLite3::class)) {
        throw new RuntimeException('The SQLite3 extension is required for a consistent backup.');
    }
    // Reserve the destination exclusively, and never overwrite an earlier backup.
    $handle = fopen($output, 'x');
    if ($handle === false) {
        throw new RuntimeException('Unable to reserve the backup file.');
    }
    $created = true;
    fclose($handle);
    if (! chmod($output, 0600)) {
        throw new RuntimeException('Unable to protect the backup file.');
    }
    $source = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
    $source->enableExceptions(true);
    $source->busyTimeout(5000);
    $destination = new SQLite3($output, SQLITE3_OPEN_READWRITE);
    $destination->enableExceptions(true);
    if (! $source->backup($destination) || $destination->querySingle('PRAGMA quick_check') !== 'ok') {
        throw new RuntimeException('SQLite backup verification failed.');
    }
    $destination->close();
    $source->close();
    echo "sqlite_consistent_backup=passed\n";
} catch (Throwable $error) {
    $destination?->close();
    $source?->close();
    if ($created) {
        @unlink($output);
    }
    fwrite(STDERR, 'SQLite backup failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
