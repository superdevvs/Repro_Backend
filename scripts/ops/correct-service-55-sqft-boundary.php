<?php

// Separate operator action: this script is NEVER invoked by a migration/deploy.
// Default is read-only. Apply requires both explicit flags and an operator's
// separately verified SQLite backup. No application notifications are triggered.
$options = getopt('', ['database:', 'apply', 'confirm:', 'backup:']);
$database = isset($options['database']) ? realpath($options['database']) : false;
$apply = array_key_exists('apply', $options);

try {
    if (! $database || ! is_file($database)) {
        throw new RuntimeException('Supply --database=/absolute/path/to/database.sqlite.');
    }
    if ($apply && ($options['confirm'] ?? '') !== 'service-55-range-154-1500-to-1501') {
        throw new RuntimeException('Apply requires --confirm=service-55-range-154-1500-to-1501.');
    }
    if ($apply) {
        $backup = isset($options['backup']) ? realpath($options['backup']) : false;
        if (! $backup || ! is_file($backup) || $backup === $database || filesize($backup) === 0) {
            throw new RuntimeException('Apply requires --backup=/path/to/a/verified/nonempty/SQLite-backup.');
        }
    }

    $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if (! $apply) {
        $pdo->exec('PRAGMA query_only = ON');
    } else {
        // Lock before checking so another writer cannot change the asserted row.
        $pdo->exec('BEGIN IMMEDIATE');
    }

    $row = $pdo->query('SELECT id, service_id, sqft_from, sqft_to, price FROM service_sqft_ranges WHERE id = 154')->fetch(PDO::FETCH_ASSOC);
    if (! $row || (int) $row['service_id'] !== 55 || (int) $row['sqft_to'] !== 3000 || (float) $row['price'] !== 575.0) {
        throw new RuntimeException('Refusing: range 154 must belong to service 55, end at 3000, and cost 575.00.');
    }
    if (! in_array((int) $row['sqft_from'], [1500, 1501], true)) {
        throw new RuntimeException('Refusing: expected current sqft_from 1500 (or already corrected 1501).');
    }

    $alreadyCorrected = (int) $row['sqft_from'] === 1501;
    if ($apply && ! $alreadyCorrected) {
        $statement = $pdo->prepare('UPDATE service_sqft_ranges SET sqft_from = 1501 WHERE id = 154 AND service_id = 55 AND sqft_from = 1500 AND sqft_to = 3000 AND price = 575');
        $statement->execute();
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Refusing: exact guarded update did not affect one row.');
        }
    }
    if ($apply) {
        $pdo->exec('COMMIT');
    }

    echo json_encode([
        'mode' => $apply ? 'apply' : 'dry-run',
        'status' => $alreadyCorrected ? 'already-corrected' : ($apply ? 'corrected' : 'would-correct'),
        'range_id' => 154,
        'service_id' => 55,
        'change' => ['sqft_from' => ['before' => (int) $row['sqft_from'], 'after' => 1501]],
        'unchanged' => ['sqft_to' => 3000, 'price' => '575.00'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $apply) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // A precondition may have failed before BEGIN.
        }
    }
    fwrite(STDERR, 'Catalog correction refused: '.$error->getMessage().PHP_EOL);
    exit(1);
}
