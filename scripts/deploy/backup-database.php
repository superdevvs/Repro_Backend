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
    foreach (['/opt/repro-mysql84/client/', '/opt/mysql84/bin/'] as $prefix) {
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
$lock = null;
$directory = null;
$exitCode = 0;
$process = null;
$stage = 'configuration';
try {
    if ($argc !== 3 || ! ($app = realpath($argv[1])) || ! ($directory = realpath($argv[2])) || ! is_dir($directory)
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
        $dsn = 'mysql:'.($socket !== '' ? 'unix_socket='.$socket : 'host='.$connection['host'].';port='.$connection['port']).';dbname='.$database.';charset=utf8mb4';
        $pdo = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + ($connection['options'] ?? []));
        $identity = $pdo->query('SELECT VERSION() AS version,@@server_uuid AS server_uuid,DATABASE() AS db')->fetch(PDO::FETCH_ASSOC);
        preg_match('/\A(\d+\.\d+)\./', $identity['version'], $serverVersion);
        if (($serverVersion[1] ?? null) !== '8.4' || $identity['db'] !== $database) {
            throw new RuntimeException('This migration release requires the validated MySQL 8.4 target.');
        }
        $mysql = backupBinary('REPRO_MYSQL_CLIENT', 'mysql');
        $dump = backupBinary('REPRO_MYSQLDUMP', 'mysqldump');
        $stage = 'client_versions';
        foreach ([$mysql, $dump] as $binary) {
            $version = backupRun([$binary, '--no-defaults', '--version']);
            if (! preg_match('/\b(?:Ver|ver)\s+8\.4\./', $version)) {
                throw new RuntimeException('MySQL backup clients must match the validated 8.4 server family.');
            }
        }
        $defaults = getenv('REPRO_MYSQL_BACKUP_DEFAULTS_FILE');
        $stage = 'credentials';
        if ($defaults === false || $defaults === '') {
            $defaults = $directory.'/.mysql-client-'.bin2hex(random_bytes(8)).'.cnf';
            $temporary[] = $defaults;
            $handle = fopen($defaults, 'x');
            chmod($defaults, 0600);
            $quote = static fn (string $item): string => '"'.strtr($item, ["\\" => "\\\\", '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']).'"';
            backupWrite($handle, "[client]\nuser=".$quote((string) $connection['username'])."\npassword=".$quote((string) $connection['password'])."\n");
            fclose($handle);
        } elseif (! is_file($defaults) || is_link($defaults) || (fileperms($defaults) & 0077) !== 0) {
            throw new RuntimeException('Backup credentials require a protected regular option file.');
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
        $process = proc_open([$dump, ...$options, '--single-transaction', '--quick', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF',
            '--routines', '--events', '--triggers', '--skip-add-locks', '--column-statistics=0', '--', $database],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errorStream], $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the MySQL dump.');
        }
        fclose($pipes[0]);
        $compression = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 3]);
        $uncompressed = 0;
        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 1048576);
            $uncompressed += strlen($chunk);
            backupWrite($output, deflate_add($compression, $chunk, ZLIB_NO_FLUSH));
        }
        fclose($pipes[1]);
        $exit = proc_close($process);
        $process = null;
        rewind($errorStream);
        $errors = stream_get_contents($errorStream, 65536);
        fclose($errorStream);
        backupWrite($output, deflate_add($compression, '', ZLIB_FINISH));
        fclose($output);
        if ($exit !== 0 || $uncompressed === 0) {
            preg_match('/\bERROR\s+(\d+)\b/', $errors, $match);
            throw new RuntimeException('The MySQL dump did not complete.', isset($match[1]) ? (int) $match[1] : $exit);
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
    $manifestHandle = fopen($directory.'/database-backup.json', 'x');
    chmod($directory.'/database-backup.json', 0600);
    backupWrite($manifestHandle, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    fclose($manifestHandle);
    echo json_encode(['database_backup' => 'passed', 'driver' => $driver, 'file' => $manifest['file'], 'bytes' => $manifest['bytes'], 'sha256' => $manifest['sha256']], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
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
