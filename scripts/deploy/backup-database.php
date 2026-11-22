<?php

declare(strict_types=1);

// Read the active configuration without booting Laravel, model events, or workers.
// Connection secrets stay in a protected option file, never in argv or output.
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException('Database backup filesystem/process operation failed.', 0, $severity);
});

function backupRun(array $command): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start database backup client.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        preg_match('/\bERROR\s+(\d+)\b/', $errors, $match);
        throw new RuntimeException('Database client failed.', isset($match[1]) ? (int) $match[1] : $exit);
    }

    return $output;
}

function backupBinary(string $variable, string $name): string
{
    if (($configured = getenv($variable)) !== false && $configured !== '') {
        return $configured;
    }
    foreach (['/opt/repro-mysql84-native/mysql-8.4.13/bin/', '/opt/repro-mysql84/client/', '/opt/mysql84/bin/'] as $prefix) {
        if (is_executable($prefix.$name)) {
            return $prefix.$name;
        }
    }

    return $name;
}

function backupWrite($stream, string $bytes): void
{
    for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
        $written = fwrite($stream, substr($bytes, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Unable to finish the database backup file.');
        }
        $offset += $written;
    }
}

function backupClientFile(string $directory, string $user, string $password, array &$temporary): string
{
    $path = $directory.'/.mysql-client-'.bin2hex(random_bytes(8)).'.cnf';
    $temporary[] = $path;
    $handle = fopen($path, 'x');
    chmod($path, 0600);
    $quote = static fn (string $item): string => '"'.strtr($item, ["\\" => "\\\\", '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']).'"';
    backupWrite($handle, "[client]\nuser=".$quote($user)."\npassword=".$quote($password)."\n");
    fclose($handle);

    return $path;
}

function backupPitrCredentials(string $path): array
{
    if ($path === '' || ! is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 16384) {
        throw new RuntimeException('PITR requires a protected, explicit backup account option file.');
    }
    $settings = parse_ini_file($path, true, INI_SCANNER_RAW);
    $client = is_array($settings) ? ($settings['client'] ?? null) : null;
    // This mode only accepts credentials. In particular no options may filter
    // tables, override the target, use includes, or send output elsewhere.
    if (! is_array($settings) || count($settings) !== 1 || ! is_array($client) || count($client) !== 2
        || ! is_string($client['user'] ?? null) || $client['user'] === ''
        || ! is_string($client['password'] ?? null) || $client['password'] === ''
        || str_contains($client['user'].$client['password'], "\0")) {
        throw new RuntimeException('PITR option file must contain only client user and password.');
    }

    return [$client['user'], $client['password']];
}

function backupPitrCoordinates(string $prefix, string $tail): array
{
    // Matched Oracle clients emit this heading and commented statement before
    // any table contents. Never take a position from a row value or a separate
    // query running outside the dump's snapshot.
    $boundary = preg_split('/^-- (?:Table structure for table|Temporary view structure for view) /m', $prefix, 2);
    $header = $boundary[0];
    if (count($boundary) !== 2
        || ! preg_match('/\A-- MySQL dump /', $header)
        || substr_count($header, '-- Position to start replication or point-in-time recovery from') !== 1
        || preg_match_all('/^(?:-- )?CHANGE (?:REPLICATION SOURCE|MASTER) TO\b/m', $header) !== 1
        || ! preg_match("/^-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='([A-Za-z0-9][A-Za-z0-9_.-]*\.[0-9]{6,})', SOURCE_LOG_POS=([0-9]+);\r?$/m", $header, $match)
        || strlen($match[1]) > 255
        || ($position = filter_var($match[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 4]])) === false
        || ! preg_match('/^-- Dump completed on [0-9 -]+[0-9:]+\r?\n?\z/m', $tail)) {
        throw new RuntimeException('Missing or invalid snapshot coordinates or dump completion marker.');
    }

    return ['binlog_file' => $match[1], 'binlog_position' => $position, 'coordinate_source' => 'mysqldump-source-data'];
}

function backupMysqlSchema(PDO $pdo): array
{
    $queries = [
        'tables' => "SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME",
        'columns' => 'SELECT TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION',
        'indexes' => 'SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE,COLLATION,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
        'foreign_keys' => 'SELECT TABLE_NAME,CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE,REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME',
        'triggers' => 'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME',
        'views' => 'SELECT TABLE_NAME,VIEW_DEFINITION,CHECK_OPTION,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME',
        'routines' => 'SELECT ROUTINE_NAME,ROUTINE_TYPE,DTD_IDENTIFIER,ROUTINE_DEFINITION,SECURITY_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_NAME',
        'events' => 'SELECT EVENT_NAME,EVENT_DEFINITION,EVENT_TYPE,INTERVAL_VALUE,INTERVAL_FIELD,STATUS FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY EVENT_NAME',
    ];
    $state = [];
    foreach ($queries as $name => $query) {
        $state[$name] = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($state['tables'] === [] || array_filter($state['tables'], fn ($table) => $table['ENGINE'] !== 'InnoDB')) {
        throw new RuntimeException('A consistent MySQL backup requires nonempty InnoDB tables.');
    }

    return ['schema_sha256' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)),
        'tables' => count($state['tables']), 'foreign_keys' => count($state['foreign_keys']), 'triggers' => count($state['triggers'])];
}

$temporary = [];
$artifact = null;
$artifactCreated = false;
$manifestCreated = false;
$lock = null;
$directory = null;
$exitCode = 0;
$process = null;
$stage = 'configuration';
try {
    $expectedUuid = null;
    if ($argc === 6 && $argv[3] === '--pitr' && $argv[4] === '--expected-server-uuid'
        && preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/', $argv[5])) {
        $expectedUuid = $argv[5];
    }
    $pitr = $expectedUuid !== null;
    if (($argc !== 3 && ! $pitr) || ! ($app = realpath($argv[1])) || ! ($directory = realpath($argv[2])) || ! is_dir($directory)
        || is_link($argv[2]) || ! is_file($app.'/.env') || ! is_file($app.'/vendor/autoload.php')) {
        throw new RuntimeException('Expected an application root and an existing private backup directory.');
    }
    if ((fileperms($directory) & 0077) !== 0 || file_exists($directory.'/database-backup.json')) {
        throw new RuntimeException('Backups require a private, unused directory.');
    }
    $lock = fopen($directory.'/.database-backup.lock', 'x');
    chmod($directory.'/.database-backup.lock', 0600);
    require $app.'/vendor/autoload.php';
    $environment = Dotenv\Dotenv::parse(file_get_contents($app.'/.env'));
    $value = static fn (string $key, mixed $default = null) => getenv($key) !== false ? getenv($key) : ($environment[$key] ?? $default);
    $cached = $app.'/bootstrap/cache/config.php';
    if (is_file($cached)) {
        $configuration = require $cached;
        $name = $configuration['database']['default'];
        $connection = $configuration['database']['connections'][$name];
    } else {
        $connection = ['driver' => $value('DB_CONNECTION', 'sqlite'), 'database' => $value('DB_DATABASE', $app.'/database/database.sqlite'),
            'host' => $value('DB_HOST', '127.0.0.1'), 'port' => $value('DB_PORT', '3306'), 'unix_socket' => $value('DB_SOCKET', ''),
            'username' => $value('DB_USERNAME', ''), 'password' => $value('DB_PASSWORD', ''), 'url' => $value('DB_URL'), 'options' => []];
    }
    if (! empty($connection['url'])) {
        $connection = (new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($connection);
    }
    $driver = $connection['driver'];
    $manifest = ['format_version' => 1, 'driver' => $driver, 'created_utc' => gmdate('c'), 'application_booted' => false];
    if ($pitr && $driver !== 'mysql') {
        throw new RuntimeException('PITR base backups require an active MySQL database.');
    }
    if ($driver === 'sqlite') {
        $source = $connection['database'];
        if (! str_starts_with($source, '/')) {
            $source = $app.'/'.$source;
        }
        $source = realpath($source);
        if ($source === false || ! is_file($source)) {
            throw new RuntimeException('The active SQLite database must be an existing file.');
        }
        $artifact = $directory.'/database.sqlite';
        backupRun([PHP_BINARY, __DIR__.'/backup-sqlite.php', $source, $artifact]);
        $artifactCreated = true;
        $check = new SQLite3($artifact, SQLITE3_OPEN_READONLY);
        if ($check->querySingle('PRAGMA quick_check') !== 'ok') {
            throw new RuntimeException('SQLite backup integrity verification failed.');
        }
        $manifest['tables'] = $check->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $check->close();
    } elseif ($driver === 'mysql') {
        $stage = 'connection';
        if (! preg_match('/\A[A-Za-z0-9_]+\z/', (string) $connection['database']) || isset($connection['read']) || isset($connection['write'])) {
            throw new RuntimeException('The authoritative MySQL backup connection must be explicit.');
        }
        $database = $connection['database'];
        $socket = $connection['unix_socket'] ?? '';
        $defaults = getenv('REPRO_MYSQL_BACKUP_DEFAULTS_FILE') ?: '';
        $username = (string) $connection['username'];
        $password = (string) $connection['password'];
        $stage = 'credentials';
        if ($pitr) {
            [$username, $password] = backupPitrCredentials($defaults);
            // Pass our own credentials-only file to both clients so they
            // cannot inherit additional dump or connection options.
            $defaults = backupClientFile($directory, $username, $password, $temporary);
        } elseif ($defaults === '') {
            $defaults = backupClientFile($directory, $username, $password, $temporary);
        } elseif (! is_file($defaults) || is_link($defaults) || (fileperms($defaults) & 0077) !== 0) {
            throw new RuntimeException('Backup credentials require a protected regular option file.');
        }
        $stage = 'connection';
        $dsn = 'mysql:'.($socket !== '' ? 'unix_socket='.$socket : 'host='.$connection['host'].';port='.$connection['port']).';dbname='.$database.';charset=utf8mb4';
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + ($connection['options'] ?? []));
        $identity = $pdo->query('SELECT VERSION() AS version,@@server_uuid AS server_uuid,DATABASE() AS db,@@GLOBAL.log_bin AS log_bin')->fetch(PDO::FETCH_ASSOC);
        preg_match('/\A(8\.4\.\d+(?:\.\d+)?)(?:\z|[-+])/', $identity['version'], $serverVersion);
        if (! isset($serverVersion[1]) || $identity['db'] !== $database) {
            throw new RuntimeException('This migration release requires the validated MySQL 8.4 target.');
        }
        if ($pitr && (! hash_equals($expectedUuid, $identity['server_uuid']) || (int) $identity['log_bin'] !== 1)) {
            throw new RuntimeException('PITR requires the expected source UUID and enabled binary logging.');
        }
        $mysql = backupBinary('REPRO_MYSQL_CLIENT', 'mysql');
        $dump = backupBinary('REPRO_MYSQLDUMP', 'mysqldump');
        $stage = 'client_versions';
        foreach ([$mysql, $dump] as $binary) {
            $version = backupRun([$binary, '--no-defaults', '--version']);
            if (! preg_match('/\b(?:Ver|ver)\s+(8\.4\.\d+(?:\.\d+)?)(?:\s|\z|[-+])/', $version, $clientVersion)
                || ($pitr && $clientVersion[1] !== $serverVersion[1])) {
                throw new RuntimeException('MySQL backup clients must match the validated server version.');
            }
        }
        $options = ['--defaults-file='.$defaults, '--no-login-paths', '--default-character-set=utf8mb4'];
        $options = array_merge($options, $socket !== '' ? ['--protocol=SOCKET', '--socket='.$socket] : ['--protocol=TCP', '--host='.$connection['host'], '--port='.(string) $connection['port']]);
        $stage = 'server_identity';
        $probe = trim(backupRun([$mysql, ...$options, '--batch', '--skip-column-names', '--database='.$database, '--execute=SELECT @@server_uuid']));
        if (! hash_equals($identity['server_uuid'], $probe)) {
            throw new RuntimeException('The backup client connected to a different MySQL server.');
        }
        $stage = 'schema';
        $schema = backupMysqlSchema($pdo);
        $stage = 'dump';
        $artifact = $directory.'/database.sql.gz';
        $output = fopen($artifact, 'x');
        $artifactCreated = true;
        chmod($artifact, 0600);
        $errorPath = $directory.'/.mysql-dump-stderr-'.bin2hex(random_bytes(8));
        $temporary[] = $errorPath;
        $errorStream = fopen($errorPath, 'x+');
        chmod($errorPath, 0600);
        $pitrOptions = $pitr ? ['--source-data=2', '--comments', '--dump-date'] : [];
        $process = proc_open([$dump, ...$options, '--single-transaction', ...$pitrOptions, '--quick', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF',
            '--routines', '--events', '--triggers', '--skip-add-locks', '--column-statistics=0', '--', $database],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errorStream], $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the MySQL dump.');
        }
        fclose($pipes[0]);
        $compression = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 3]);
        $uncompressed = 0;
        $prefix = '';
        $tail = '';
        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 1048576);
            $uncompressed += strlen($chunk);
            if ($pitr) {
                if (strlen($prefix) < 32768) {
                    $prefix .= substr($chunk, 0, 32768 - strlen($prefix));
                }
                $tail = substr($tail.$chunk, -4096);
            }
            backupWrite($output, deflate_add($compression, $chunk, ZLIB_NO_FLUSH));
        }
        fclose($pipes[1]);
        $exit = proc_close($process);
        $process = null;
        rewind($errorStream);
        $errors = stream_get_contents($errorStream, 65536);
        fclose($errorStream);
        backupWrite($output, deflate_add($compression, '', ZLIB_FINISH));
        if ($pitr && ! fsync($output)) {
            throw new RuntimeException('Could not sync the PITR backup artifact.');
        }
        fclose($output);
        if ($exit !== 0 || $uncompressed === 0) {
            preg_match('/\bERROR\s+(\d+)\b/', $errors, $match);
            throw new RuntimeException('The MySQL dump did not complete.', isset($match[1]) ? (int) $match[1] : $exit);
        }
        if ($pitr) {
            $stage = 'pitr_coordinates';
            $manifest['pitr'] = backupPitrCoordinates($prefix, $tail);
            if (trim($errors) !== '') {
                $stage = 'pitr_diagnostics';
                throw new RuntimeException('MySQL reported diagnostics during the PITR dump.');
            }
            $stage = 'server_identity_recheck';
            $probe = trim(backupRun([$mysql, ...$options, '--batch', '--skip-column-names', '--database='.$database,
                "--execute=SELECT CONCAT(@@server_uuid, ':', @@GLOBAL.log_bin)"]));
            if (! hash_equals($expectedUuid.':1', $probe)) {
                throw new RuntimeException('The source UUID or binary log status changed during the PITR backup.');
            }
        }
        $stage = 'schema_recheck';
        if ($schema !== backupMysqlSchema($pdo)) {
            throw new RuntimeException('MySQL schema changed during the backup.');
        }
        $manifest += $schema + ['server_version' => $identity['version'], 'server_uuid' => $identity['server_uuid'],
            'database' => $database, 'uncompressed_bytes' => $uncompressed, 'consistent_transaction' => true,
            'includes_triggers_routines_events' => true];
    } else {
        throw new RuntimeException('Unsupported database driver for this release.');
    }
    $stage = 'manifest';
    $manifest['file'] = basename($artifact);
    $manifest['bytes'] = filesize($artifact);
    $manifest['sha256'] = hash_file('sha256', $artifact);
    $manifestPath = $directory.'/database-backup.json';
    $manifestTemporary = $directory.'/.database-manifest-'.bin2hex(random_bytes(8));
    $temporary[] = $manifestTemporary;
    $manifestHandle = fopen($manifestTemporary, 'x');
    chmod($manifestTemporary, 0600);
    backupWrite($manifestHandle, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    if (! fsync($manifestHandle)) {
        throw new RuntimeException('Could not sync the verified backup manifest.');
    }
    fclose($manifestHandle);
    rename($manifestTemporary, $manifestPath);
    $manifestCreated = true;
    echo json_encode(['database_backup' => 'passed', 'driver' => $driver, 'file' => $manifest['file'], 'bytes' => $manifest['bytes'], 'sha256' => $manifest['sha256']], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($manifestCreated) {
        unlink($manifestPath);
    }
    if ($artifactCreated && $artifact !== null && is_file($artifact)) {
        unlink($artifact);
    }
    fwrite(STDERR, json_encode(['database_backup' => 'failed', 'stage' => $stage, 'error_class' => $error::class, 'error_code' => $error->getCode()], JSON_THROW_ON_ERROR)."\n");
    $exitCode = 1;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach ($temporary as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_resource($lock)) {
        fclose($lock);
        unlink($directory.'/.database-backup.lock');
    }
}
exit($exitCode);
