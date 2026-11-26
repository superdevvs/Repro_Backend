<?php
declare(strict_types=1);

/**
 * Independent PHP 8.3 field reader; no Laravel boot and no application writes.
 *
 * php verify-fields.php --snapshot=/private/copy.sqlite --snapshot-sha256=HEX
 *   --schema-plan=/private/schema-plan.json --schema-plan-sha256=HEX
 *   --credentials=/private/mysql.json --database=repro_fixture
 *   --expected-server-uuid=UUID --writers-stopped
 *
 * For a reviewed reverse export containing the optional native messaging
 * columns, pass --reviewed-reverse-layout with the freshly built SQLite plan.
 *
 * Credentials use migrate.py's {unix_socket,user,password} JSON contract.
 * Requires a standalone closed SQLite snapshot with no WAL/SHM/journal siblings,
 * an externally frozen MySQL target, PHP 64-bit, sqlite3 and pdo_mysql.
 * Stdout is JSON-lines containing only metadata/counts, never field values.
 * Checks data parity, not constraints, next IDs, backups or cutover readiness.
 */
namespace ReproMysqlFields;

use PDO;
use PDOException;
use SQLite3;
use Throwable;

const SQLITE_OPEN_URI = 0x40;

final class Refusal extends \RuntimeException {}

function requireThat(bool $valid, string $reason): void
{
    if (!$valid) {
        throw new Refusal($reason);
    }
}

function identifier(string $name): string
{
    requireThat(preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $name) === 1, 'invalid-identifier');
    return '`'.$name.'`';
}

/** Exact decimal normalization: no BC Math dependency or numeric float cast. */
function decimalString(string $value): string
{
    requireThat(strlen($value) <= 1024 && preg_match(
        '/\A([+-]?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d{1,3}))?\z/', $value, $parts
    ) === 1, 'invalid-number-representation');
    $fraction = $parts[3] ?? '';
    $exponent = (int) ($parts[4] ?? 0);
    requireThat(abs($exponent) <= 400, 'numeric-exponent-out-of-range');
    $digits = $parts[2].$fraction;
    $point = strlen($parts[2]) + $exponent;
    if ($point <= 0) {
        $fixed = '0.'.str_repeat('0', -$point).$digits;
    } elseif ($point >= strlen($digits)) {
        $fixed = $digits.str_repeat('0', $point - strlen($digits));
    } else {
        $fixed = substr($digits, 0, $point).'.'.substr($digits, $point);
    }
    [$whole, $tail] = array_pad(explode('.', $fixed, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    $tail = rtrim($tail, '0');
    $answer = $whole.($tail === '' ? '' : '.'.$tail);
    return ($parts[1] === '-' && $answer !== '0' ? '-' : '').$answer;
}

function shortestFloat(float $number): string
{
    requireThat(is_finite($number), 'non-finite-number');
    requireThat(ini_get('serialize_precision') === '-1', 'shortest-float-policy-not-set');
    return json_encode($number, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
}

/** Match the importer's str(float) spelling for the explicit legacy_text kind. */
function legacyText(mixed $value): ?string
{
    if ($value === null || is_string($value)) {
        return $value;
    }
    if (is_int($value)) {
        return (string) $value;
    }
    requireThat(is_float($value), 'unsupported-legacy-storage');
    $fixed = decimalString(shortestFloat($value));
    if ($fixed === '0') {
        return str_starts_with(shortestFloat($value), '-') ? '-0.0' : '0.0';
    }
    $negative = str_starts_with($fixed, '-');
    $absolute = ltrim($fixed, '-');
    [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
    if ($whole !== '0') {
        $exponent = strlen($whole) - 1;
        $digits = rtrim($whole.$fraction, '0');
    } else {
        $leading = strlen($fraction) - strlen(ltrim($fraction, '0'));
        $exponent = -$leading - 1;
        $digits = ltrim($fraction, '0');
    }
    if ($exponent >= 16 || $exponent < -4) {
        $coefficient = $digits[0].(strlen($digits) > 1 ? '.'.substr($digits, 1) : '');
        return ($negative ? '-' : '').$coefficient.'e'.($exponent < 0 ? '-' : '+')
            .str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
    }
    return $fixed.(str_contains($fixed, '.') ? '' : '.0');
}

/** Null is distinct from empty, integer 0, decimal 0 and text "0". */
function equivalent(mixed $source, mixed $target, string $kind, int $sourceType): bool
{
    if ($source === null || $target === null) {
        return $source === null && $target === null && $sourceType === SQLITE3_NULL;
    }
    switch ($kind) {
        case 'integer':
            requireThat($sourceType === SQLITE3_INTEGER && is_int($source), 'invalid-source-integer');
            requireThat((is_int($target) || is_string($target)) &&
                preg_match('/\A-?\d+\z/', (string) $target) === 1, 'invalid-target-integer');
            return (string) $source === decimalString((string) $target);
        case 'decimal':
            requireThat(in_array($sourceType, [SQLITE3_INTEGER, SQLITE3_FLOAT], true), 'invalid-source-decimal');
            requireThat(is_string($target) || is_int($target), 'decimal-driver-precision-loss');
            $left = is_int($source) ? (string) $source : shortestFloat($source);
            return decimalString($left) === decimalString((string) $target);
        case 'float':
            requireThat(in_array($sourceType, [SQLITE3_INTEGER, SQLITE3_FLOAT], true), 'invalid-source-float');
            requireThat(!is_int($source) || ($source >= -9007199254740992 && $source <= 9007199254740992),
                'source-integer-not-exact-double');
            requireThat(is_float($target) || is_int($target) ||
                (is_string($target) && is_numeric($target)), 'invalid-target-float');
            requireThat(is_finite((float) $source) && is_finite((float) $target), 'non-finite-number');
            return pack('E', (float) $source) === pack('E', (float) $target);
        case 'text':
        case 'temporal_text':
            requireThat($sourceType === SQLITE3_TEXT && is_string($source) && is_string($target),
                'text-or-temporal-storage-changed');
            return $source === $target;
        case 'blob':
            requireThat($sourceType === SQLITE3_BLOB && is_string($source) && is_string($target),
                'blob-storage-changed');
            return $source === $target;
        case 'legacy_text':
            requireThat(in_array($sourceType, [SQLITE3_INTEGER, SQLITE3_FLOAT, SQLITE3_TEXT], true)
                && is_string($target), 'legacy-storage-changed');
            return legacyText($source) === $target;
        default:
            throw new Refusal('unknown-column-kind');
    }
}

function privateFile(string $path): string
{
    requireThat(str_starts_with($path, '/') && !is_link($path) && is_file($path), 'private-file-required');
    $resolved = realpath($path);
    requireThat($resolved === $path && (fileperms($path) & 0077) === 0, 'private-file-path-or-mode');
    return $path;
}

function readJson(string $path, ?string $sha256 = null): array
{
    privateFile($path);
    requireThat(filesize($path) <= 32 * 1024 * 1024, 'metadata-too-large');
    $raw = file_get_contents($path);
    requireThat(is_string($raw), 'metadata-unreadable');
    if ($sha256 !== null) {
        requireThat(preg_match('/\A[a-f0-9]{64}\z/', $sha256) === 1 &&
            hash_equals($sha256, hash('sha256', $raw)), 'metadata-checksum-mismatch');
    }
    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    requireThat(is_array($value), 'metadata-shape');
    return $value;
}

function validatePlan(array $plan): array
{
    requireThat(array_is_list($plan) && count($plan) > 0, 'schema-plan-shape');
    $seen = [];
    $kinds = ['integer', 'decimal', 'float', 'text', 'blob', 'temporal_text', 'legacy_text'];
    foreach ($plan as $table) {
        requireThat(is_array($table) && isset($table['name'], $table['columns'], $table['primary']), 'schema-plan-table');
        identifier($table['name']);
        requireThat(!isset($seen[$table['name']]), 'duplicate-table');
        $seen[$table['name']] = true;
        requireThat(is_array($table['columns']) && array_is_list($table['columns']) &&
            count($table['columns']) > 0, 'schema-plan-columns');
        $columns = [];
        foreach ($table['columns'] as $column) {
            requireThat(is_array($column) && isset($column['name'], $column['kind'], $column['target_type']), 'schema-plan-column');
            identifier($column['name']);
            requireThat(!isset($columns[$column['name']]) && in_array($column['kind'], $kinds, true), 'column-policy');
            requireThat(preg_match('/\A(?:BIGINT|DOUBLE|LONGTEXT|LONGBLOB|VARCHAR\(\d+\)|DECIMAL\(\d+,\d+\))\z/',
                $column['target_type']) === 1, 'target-type-policy');
            $columns[$column['name']] = true;
        }
        requireThat(is_array($table['primary']) && array_is_list($table['primary']) &&
            count(array_unique($table['primary'])) === count($table['primary']), 'primary-key-plan');
        foreach ($table['primary'] as $key) {
            requireThat(isset($columns[$key]), 'unknown-order-column');
        }
    }
    return $plan;
}

function sortSql(array $table, bool $sqlite): string
{
    $columns = array_column($table['columns'], null, 'name');
    $keys = $table['primary'] ?: array_keys($columns);
    $parts = [];
    foreach ($keys as $name) {
        $quoted = identifier($name);
        $kind = $columns[$name]['kind'];
        if (in_array($kind, ['integer', 'decimal', 'float'], true)) {
            $parts[] = $quoted;
        } else {
            // Hash only controls ordering, never value equality. Fixed-width keys
            // avoid collations and MySQL max_sort_length truncation of LONGTEXT.
            $input = $sqlite && $kind === 'legacy_text' ? 'repro_verify_legacy('.$quoted.')' : $quoted;
            $parts[] = $sqlite ? 'repro_verify_sha256('.$input.')'
                : 'SHA2(CAST('.$input.' AS BINARY),256)';
        }
    }
    return implode(',', $parts);
}

function allRows(PDO $db, string $sql): array
{
    $result = $db->query($sql);
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    $result->closeCursor();
    return $rows;
}

function storageTypeMatches(string $table, array $column, string $actual, bool $reviewedReverse): bool
{
    if ($reviewedReverse && $column['kind'] === 'text' && $column['target_type'] === 'LONGTEXT') {
        $nativeType = match ($table.'.'.$column['name']) {
            'contacts.phones_json', 'contacts.tags_json', 'message_threads.tags_json' => 'json',
            'contacts.comment' => 'text',
            'message_threads.status' => 'varchar(255)',
            default => null,
        };
        if ($nativeType !== null) {
            return $actual === $nativeType;
        }
    }

    return $actual === strtolower($column['target_type']);
}

function schemaChecks(SQLite3 $source, PDO $target, array $plan, bool $reviewedReverse): void
{
    $expectedTables = array_column($plan, 'name');
    sort($expectedTables);
    $sourceTables = [];
    $result = $source->query("SELECT name FROM frozen.sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    while ($row = $result->fetchArray(SQLITE3_NUM)) {
        $sourceTables[] = $row[0];
    }
    $result->finalize();
    sort($sourceTables);
    requireThat($sourceTables === $expectedTables, 'source-table-set-mismatch');
    $actual = allRows($target, 'SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
    $targetTables = [];
    foreach ($actual as $row) {
        requireThat($row['ENGINE'] === 'InnoDB' && $row['TABLE_TYPE'] === 'BASE TABLE', 'target-not-innodb');
        $targetTables[] = $row['TABLE_NAME'];
    }
    sort($targetTables);
    requireThat($targetTables === $expectedTables, 'target-table-set-mismatch');
    $targetColumns = [];
    foreach (allRows($target, 'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS '
        .'WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION') as $row) {
        $targetColumns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = strtolower($row['COLUMN_TYPE']);
    }
    foreach ($plan as $table) {
        $sourceColumns = [];
        $result = $source->query('PRAGMA frozen.table_info('.identifier($table['name']).')');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sourceColumns[] = $row['name'];
        }
        $result->finalize();
        $expected = array_column($table['columns'], 'name');
        requireThat($sourceColumns === $expected && array_keys($targetColumns[$table['name']] ?? []) === $expected,
            'column-set-or-order-mismatch');
        foreach ($table['columns'] as $column) {
            requireThat(storageTypeMatches($table['name'], $column, $targetColumns[$table['name']][$column['name']], $reviewedReverse),
                'target-storage-type-mismatch');
        }
    }
}

function snapshotCheck(string $path, string $hash): void
{
    privateFile($path);
    foreach (['-wal', '-shm', '-journal'] as $suffix) {
        requireThat(!file_exists($path.$suffix) && !is_link($path.$suffix), 'snapshot-has-sidecar');
    }
    requireThat(preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1 && hash_equals($hash, hash_file('sha256', $path)),
        'snapshot-checksum-mismatch');
}

function openSnapshot(string $path): SQLite3
{
    // PHP resolves constructor file names as paths. Attach the immutable URI
    // through SQLite to read WAL-mode snapshots without creating sidecars.
    $uri = 'file:'.str_replace('%2F', '/', rawurlencode($path)).'?mode=ro&immutable=1';
    $source = new SQLite3(':memory:', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE | SQLITE_OPEN_URI);
    $source->enableExceptions(true);
    $source->exec("ATTACH DATABASE '".SQLite3::escapeString($uri)."' AS frozen");
    $source->exec('PRAGMA query_only=ON');
    requireThat($source->querySingle('PRAGMA query_only') === 1, 'source-not-query-only');

    return $source;
}

function run(array $options): array
{
    requireThat(PHP_INT_SIZE === 8 && PHP_VERSION_ID >= 80300 && PHP_OS_FAMILY === 'Linux', 'linux-64bit-php83-required');
    requireThat(extension_loaded('sqlite3') && extension_loaded('pdo_mysql'), 'required-reader-extension-missing');
    requireThat(isset($options['writers-stopped']), 'external-writer-freeze-attestation-required');
    ini_set('serialize_precision', '-1');
    $snapshot = $options['snapshot'];
    snapshotCheck($snapshot, $options['snapshot-sha256']);
    $plan = validatePlan(readJson($options['schema-plan'], $options['schema-plan-sha256']));
    $credentials = readJson($options['credentials']);
    $keys = array_keys($credentials);
    sort($keys);
    requireThat($keys === ['password', 'unix_socket', 'user'] && is_string($credentials['password']) &&
        is_string($credentials['user']) && $credentials['user'] !== '' && is_string($credentials['unix_socket']) &&
        preg_match('#\A/[A-Za-z0-9_./-]+\z#', $credentials['unix_socket']) === 1 &&
        realpath($credentials['unix_socket']) === $credentials['unix_socket'] &&
        filetype($credentials['unix_socket']) === 'socket', 'explicit-local-socket-credentials-required');
    requireThat(preg_match('/\Arepro_[A-Za-z0-9_]{1,50}\z/', $options['database']) === 1 &&
        preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/',
            $options['expected-server-uuid']) === 1, 'target-identity-required');
    $source = openSnapshot($snapshot);
    requireThat($source->querySingle('PRAGMA frozen.quick_check') === 'ok', 'source-integrity-failure');
    $source->createFunction('repro_verify_legacy', legacyText(...), 1, SQLITE3_DETERMINISTIC);
    $source->createFunction('repro_verify_sha256', static fn ($value) => $value === null ? null : hash('sha256', $value),
        1, SQLITE3_DETERMINISTIC);
    $target = new PDO('mysql:unix_socket='.$credentials['unix_socket'].';dbname='.$options['database'].';charset=utf8mb4',
        $credentials['user'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, PDO::MYSQL_ATTR_LOCAL_INFILE => false]);
    $identitySql = 'SELECT DATABASE() AS db,VERSION() AS version,@@server_uuid AS uuid,@@socket AS socket,'
        .'@@skip_networking AS socket_only,@@innodb_flush_log_at_trx_commit AS durable,@@sync_binlog AS binlog';
    $identity = allRows($target, $identitySql)[0];
    requireThat($identity['db'] === $options['database'] && explode('-', $identity['version'])[0] === '8.4.13'
        && $identity['uuid'] === $options['expected-server-uuid'] && $identity['socket'] === $credentials['unix_socket']
        && (int) $identity['socket_only'] === 1 && (int) $identity['durable'] === 1 && (int) $identity['binlog'] === 1,
        'target-identity-or-durability-mismatch');
    unset($credentials);
    $target->exec("SET SESSION time_zone='+00:00'");
    $target->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $target->exec('SET SESSION TRANSACTION READ ONLY');
    $reviewedReverse = isset($options['reviewed-reverse-layout']);
    schemaChecks($source, $target, $plan, $reviewedReverse);
    $target->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    requireThat((int) allRows($target, 'SELECT @@transaction_read_only AS value')[0]['value'] === 1,
        'target-transaction-not-read-only');
    $totals = ['tables' => 0, 'rows' => 0, 'fields' => 0];
    $context = [];
    try {
        foreach ($plan as $table) {
            $context = ['table' => $table['name'], 'row_ordinal' => 0];
            $names = implode(',', array_map(identifier(...), array_column($table['columns'], 'name')));
            $name = identifier($table['name']);
            $left = $source->query('SELECT '.$names.' FROM frozen.'.$name.' ORDER BY '.sortSql($table, true));
            $right = $target->query('SELECT '.$names.' FROM '.$name.' ORDER BY '.sortSql($table, false));
            $rows = 0;
            try {
                while (true) {
                    $a = $left->fetchArray(SQLITE3_NUM);
                    $b = $right->fetch(PDO::FETCH_NUM);
                    if ($a === false || $b === false) {
                        requireThat($a === false && $b === false, 'row-count-mismatch');
                        break;
                    }
                    $context['row_ordinal'] = ++$rows;
                    foreach ($table['columns'] as $index => $column) {
                        $context['column'] = $column['name'];
                        $context['kind'] = $column['kind'];
                        requireThat(equivalent($a[$index], $b[$index], $column['kind'], $left->columnType($index)),
                            'field-mismatch');
                        ++$totals['fields'];
                    }
                }
            } finally {
                $left->finalize();
                $right->closeCursor();
            }
            ++$totals['tables'];
            $totals['rows'] += $rows;
            echo json_encode(['event' => 'table-verified', 'table' => $table['name'], 'rows' => $rows,
                'fields' => $rows * count($table['columns'])], JSON_THROW_ON_ERROR)."\n";
        }
        $context = [];
        schemaChecks($source, $target, $plan, $reviewedReverse);
        requireThat(allRows($target, $identitySql)[0] === $identity, 'target-identity-changed');
        $target->rollBack();
        $source->close();
        snapshotCheck($snapshot, $options['snapshot-sha256']);
        requireThat(hash_equals($options['schema-plan-sha256'], hash_file('sha256', $options['schema-plan'])),
            'schema-plan-changed');
        return $totals + ['result' => 'all-fields-verified', 'field_mismatches' => 0,
            'source_sha256' => $options['snapshot-sha256'], 'schema_plan_sha256' => $options['schema-plan-sha256'],
            'target_server_uuid' => $identity['uuid'], 'target_database' => $identity['db'],
            'reader' => 'independent-php83', 'cutover_ready' => false];
    } catch (Throwable $error) {
        // Closing the connection rolls back without issuing application writes.
        $target = null;
        $source->close();
        echo json_encode(['result' => 'REFUSED', 'reason' => $error instanceof Refusal ? $error->getMessage() : 'reader-failure',
            'location' => $context, 'completed' => $totals], JSON_THROW_ON_ERROR)."\n";
        throw new Refusal('verification-stopped');
    }
}

function main(): int
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    set_error_handler(static function (): never { throw new Refusal('reader-warning'); });
    $names = ['snapshot', 'snapshot-sha256', 'schema-plan', 'schema-plan-sha256', 'credentials',
        'database', 'expected-server-uuid'];
    try {
        $options = getopt('', [...array_map(static fn ($name) => $name.':', $names), 'writers-stopped', 'reviewed-reverse-layout']);
        foreach ($names as $name) {
            requireThat(isset($options[$name]) && is_string($options[$name]) && $options[$name] !== '', 'missing-required-option');
        }
        echo json_encode(run($options), JSON_THROW_ON_ERROR)."\n";
        return 0;
    } catch (Throwable $error) {
        $report = ['result' => 'REFUSED', 'reason' => $error instanceof Refusal ? $error->getMessage() : 'reader-failure'];
        if (! $error instanceof Refusal) {
            $report['error_type'] = get_class($error);
            $report['reader_line'] = $error->getLine();
            if ($error instanceof PDOException && is_array($error->errorInfo)) {
                $report['driver_error_code'] = is_int($error->errorInfo[1] ?? null) ? $error->errorInfo[1] : null;
            }
        }
        echo json_encode($report, JSON_THROW_ON_ERROR)."\n";
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main());
}
