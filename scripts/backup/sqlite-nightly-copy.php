<?php

declare(strict_types=1);

// Nightly compact copy of the live SQLite database (Phase 0 item 1).
//
//   php sqlite-nightly-copy.php SOURCE DESTINATION_DIR [--keep=7] [--reserve-bytes=N] [--allow-same-device]
//
// VACUUM INTO reads one consistent snapshot through a read-only connection.
// In WAL mode readers never block writers, so the live app keeps writing while
// the copy runs. Each copy is checked (quick_check), given a sha256 file that
// `sha256sum -c` understands, and only then counted. Rotation runs only after a
// good copy, so a failing night never reduces the number of good copies.
// The script does not boot Laravel and never writes to the source.
// Results go to DESTINATION_DIR/last-run.json for monitoring.

const COPY_PATTERN = '/\Adatabase-\d{8}-\d{6}\.sqlite\z/';
const PARTIAL_PATTERN = '/\A\.partial-\d{8}-\d{6}-[0-9a-f]{8}\.sqlite(?:-journal|-wal|-shm|\.sha256)?\z/';
const EXIT_LOCKED = 75;

function nightlyFail(string $message, int $code = 1): never
{
    throw new RuntimeException($message, $code);
}

function nightlyWriteStatus(string $directory, array $status): void
{
    $temporary = $directory.'/.last-run.json.'.bin2hex(random_bytes(4));
    if (file_put_contents($temporary, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false
        || ! chmod($temporary, 0600) || ! rename($temporary, $directory.'/last-run.json')) {
        @unlink($temporary);
        fwrite(STDERR, "Unable to record last-run.json.\n");
    }
}

function nightlyOwnedRegularFile(string $path): bool
{
    $info = @lstat($path);

    return $info !== false && ($info['mode'] & 0170000) === 0100000 && $info['nlink'] === 1
        && (! function_exists('posix_geteuid') || $info['uid'] === posix_geteuid());
}

function nightlyFsync(string $path): void
{
    $handle = fopen($path, 'r+');
    if ($handle === false || ! fsync($handle)) {
        nightlyFail('Unable to flush the copy to disk.');
    }
    fclose($handle);
}

$options = ['keep' => 7, 'reserve-bytes' => 2 * 1024 ** 3, 'allow-same-device' => false];
$positional = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/\A--(keep|reserve-bytes)=(\d+)\z/', $argument, $match)) {
        $options[$match[1]] = (int) $match[2];
    } elseif ($argument === '--allow-same-device') {
        $options['allow-same-device'] = true;
    } else {
        $positional[] = $argument;
    }
}

$destination = $positional[1] ?? '';
$statusDirectory = null;
$lock = null;
$partial = null;
$started = microtime(true);
$exit = 0;
try {
    if (count($positional) !== 2 || $options['keep'] < 1) {
        nightlyFail('Usage: sqlite-nightly-copy.php SOURCE DESTINATION_DIR [--keep=N] [--reserve-bytes=N] [--allow-same-device]');
    }
    [$source, $destination] = $positional;
    if (! class_exists(SQLite3::class)) {
        nightlyFail('The SQLite3 extension is required.');
    }
    if (! str_starts_with($destination, '/') || is_link($destination) || ! is_dir($destination)) {
        nightlyFail('The destination must be an existing absolute directory, not a link.');
    }
    $statusDirectory = $destination;
    $lock = fopen($destination.'/.sqlite-nightly-copy.lock', 'c');
    if ($lock === false) {
        nightlyFail('Unable to open the copy lock.');
    }
    if (! flock($lock, LOCK_EX | LOCK_NB)) {
        nightlyFail('Another nightly copy is still running.', EXIT_LOCKED);
    }
    if (is_link($source) || ! is_file($source)) {
        nightlyFail('The source must be an existing database file, not a link.');
    }
    $sourceInfo = stat($source);
    $destinationInfo = stat($destination);
    if ($sourceInfo['dev'] === $destinationInfo['dev'] && ! $options['allow-same-device']) {
        nightlyFail('The destination is on the same disk as the live database; choose the second disk.');
    }
    // SQLite gives new -wal/-shm files the creator's group. A copy run under the
    // wrong group could leave files the web server cannot write.
    if (function_exists('posix_getegid') && posix_getegid() !== $sourceInfo['gid']) {
        nightlyFail('Run with the database file\'s group (for example through sg www-data).');
    }

    // A crashed earlier run may have left a partial file. We hold the lock, so
    // any partial file now is stale.
    foreach (scandir($destination) ?: [] as $name) {
        if (preg_match(PARTIAL_PATTERN, $name) && nightlyOwnedRegularFile($destination.'/'.$name)) {
            unlink($destination.'/'.$name);
        }
    }

    $walBytes = is_file($source.'-wal') ? filesize($source.'-wal') : 0;
    $needed = $sourceInfo['size'] + $walBytes + $options['reserve-bytes'];
    $free = disk_free_space($destination);
    if ($free === false || $free < $needed) {
        nightlyFail(sprintf('Not enough free space at the destination: need %d bytes, have %d.', $needed, (int) $free));
    }

    $live = new SQLite3($source, SQLITE3_OPEN_READONLY);
    $live->enableExceptions(true);
    $live->busyTimeout(5000);
    if (strtolower((string) $live->querySingle('PRAGMA journal_mode')) !== 'wal') {
        $live->close();
        nightlyFail('The source is not in WAL mode; VACUUM INTO would block writers for the whole copy.');
    }
    $stamp = date('Ymd-His');
    $final = $destination.'/database-'.$stamp.'.sqlite';
    if (file_exists($final) || is_link($final)) {
        $live->close();
        nightlyFail('A copy with this timestamp already exists.');
    }
    $partial = $destination.'/.partial-'.$stamp.'-'.bin2hex(random_bytes(4)).'.sqlite';
    $statement = $live->prepare('VACUUM INTO :target');
    $statement->bindValue(':target', $partial, SQLITE3_TEXT);
    $statement->execute();
    $statement->close();
    $live->close();
    if (! chmod($partial, 0600)) {
        nightlyFail('Unable to protect the copy.');
    }

    $copy = new SQLite3($partial, SQLITE3_OPEN_READWRITE);
    $copy->enableExceptions(true);
    // Keep the copy self-contained: it must open without -wal/-shm files.
    if (strtolower((string) $copy->querySingle('PRAGMA journal_mode')) === 'wal'
        && $copy->querySingle('PRAGMA journal_mode=DELETE') !== 'delete') {
        nightlyFail('Unable to make the copy self-contained.');
    }
    if ($copy->querySingle('PRAGMA quick_check') !== 'ok') {
        nightlyFail('The copy failed quick_check.');
    }
    $tables = (int) $copy->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
    $copy->close();
    nightlyFsync($partial);
    $sha256 = hash_file('sha256', $partial);
    // link() never replaces an existing file, unlike rename().
    if (! link($partial, $final) || ! unlink($partial)) {
        nightlyFail('Unable to publish the copy.');
    }
    $partial = null;
    $checksum = $final.'.sha256';
    $temporary = $destination.'/.partial-'.$stamp.'-'.bin2hex(random_bytes(4)).'.sqlite.sha256';
    if (file_put_contents($temporary, $sha256.'  '.basename($final)."\n") === false
        || ! chmod($temporary, 0600) || ! rename($temporary, $checksum)) {
        @unlink($temporary);
        nightlyFail('Unable to write the checksum file.');
    }

    // Rotate only after a good copy. Only exact copy names owned by this user
    // are considered; anything else in the directory is left alone.
    $copies = array_values(array_filter(scandir($destination) ?: [], static fn (string $name): bool => (bool) preg_match(COPY_PATTERN, $name)
        && nightlyOwnedRegularFile($destination.'/'.$name)));
    rsort($copies, SORT_STRING);
    $removed = [];
    foreach (array_slice($copies, $options['keep']) as $name) {
        unlink($destination.'/'.$name);
        if (nightlyOwnedRegularFile($destination.'/'.$name.'.sha256')) {
            unlink($destination.'/'.$name.'.sha256');
        }
        $removed[] = $name;
    }

    $status = [
        'status' => 'ok',
        'finished_at' => date(DATE_ATOM),
        'duration_seconds' => round(microtime(true) - $started, 3),
        'file' => basename($final),
        'bytes' => filesize($final),
        'sha256' => $sha256,
        'quick_check' => 'ok',
        'tables' => $tables,
        'source_bytes' => $sourceInfo['size'] + $walBytes,
        'kept' => min(count($copies), $options['keep']),
        'removed' => $removed,
    ];
    nightlyWriteStatus($destination, $status);
    echo json_encode(['sqlite_nightly_copy' => 'passed'] + $status, JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    $exit = $error instanceof RuntimeException && $error->getCode() === EXIT_LOCKED ? EXIT_LOCKED : 1;
    if ($partial !== null) {
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            if (nightlyOwnedRegularFile($partial.$suffix)) {
                @unlink($partial.$suffix);
            }
        }
    }
    $message = $error instanceof RuntimeException ? $error->getMessage() : 'The copy failed: '.$error->getMessage();
    if ($statusDirectory !== null && $exit !== EXIT_LOCKED) {
        nightlyWriteStatus($statusDirectory, [
            'status' => 'failed',
            'finished_at' => date(DATE_ATOM),
            'duration_seconds' => round(microtime(true) - $started, 3),
            'error' => $message,
        ]);
    }
    fwrite(STDERR, 'SQLite nightly copy failed: '.$message.PHP_EOL);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
exit($exit);
