<?php

declare(strict_types=1);

// A private synthetic fixture process, invoked only by compare.py. No HTTP
// listener, scheduler, copied business records, or provider operation is used.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);

final class ComparisonRefusal extends RuntimeException {}

function comparisonNeed(bool $value, string $reason): void
{
    if (! $value) {
        throw new ComparisonRefusal($reason);
    }
}

function comparisonMysql(array $payload): PDO
{
    $target = $payload['target'];
    $role = match ($payload['action']) { 'setup', 'cleanup' => 'admin', 'verify' => 'reader', default => 'worker' };
    comparisonNeed($target['database'] === 'repro_mysql84_acceptance'
        && preg_match('#\A/[A-Za-z0-9_./-]+\z#', $target['socket']) === 1
        && realpath($target['socket']) === $target['socket']
        && $payload['account']['username'] === 'repro_accept_'.$role, 'dedicated-target-required');
    $pdo = new PDO('mysql:unix_socket='.$target['socket'].';dbname='.$target['database'].';charset=utf8mb4',
        $payload['account']['username'], $payload['account']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    $identity = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@server_uuid AS uuid,VERSION() AS version,'
        .'@@socket AS socket,@@skip_networking AS local_only,@@foreign_key_checks AS fk,'
        .'@@innodb_flush_log_at_trx_commit AS durable,@@sync_binlog AS binlog')->fetch(PDO::FETCH_ASSOC);
    comparisonNeed($identity['db'] === $target['database'] && $identity['account'] === 'repro_accept_'.$role.'@localhost'
        && $identity['uuid'] === $target['server_uuid'] && $identity['version'] === '8.4.13'
        && $identity['socket'] === $target['socket'] && (int) $identity['local_only'] === 1
        && (int) $identity['fk'] === 1 && (int) $identity['durable'] === 1 && (int) $identity['binlog'] === 1,
        'actual-target-identity');
    $allowed = match ($role) {
        'admin' => ['ALTER', 'CREATE', 'DELETE', 'DROP', 'INDEX', 'INSERT', 'REFERENCES', 'SELECT', 'TRIGGER', 'UPDATE'],
        'worker' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'], 'reader' => ['SELECT'],
    };
    $partialRevokes = (int) $pdo->query('SELECT @@partial_revokes')->fetchColumn();
    $seen = [];
    foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        $principal = '`repro_accept_'.$role.'`@`localhost`';
        if ($grant === 'GRANT USAGE ON *.* TO '.$principal) {
            continue;
        }
        comparisonNeed((bool) preg_match('/\AGRANT ([A-Z, ]+) ON `([^`]+)`\.\* TO '.preg_quote($principal, '/').'\z/', $grant, $match),
            'unexpected-privilege');
        comparisonNeed($match[2] === ($partialRevokes ? 'repro_mysql84_acceptance' : 'repro\\_mysql84\\_acceptance'), 'nonliteral-schema-grant');
        $seen = array_merge($seen, explode(', ', $match[1]));
    }
    sort($seen);
    comparisonNeed($seen === $allowed, 'privilege-set');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');

    return $pdo;
}

function comparisonTables(PDO $pdo, string $engine): array
{
    return $pdo->query($engine === 'mysql'
        ? 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
        : "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

function comparisonOwned(PDO $pdo, array $payload): void
{
    comparisonNeed(comparisonTables($pdo, $payload['engine']) === ['notification_read_states', 'users', 'workload_fixture'], 'fixture-table-set');
    comparisonNeed($pdo->query('SELECT token FROM workload_fixture')->fetchColumn() === $payload['token'], 'fixture-owner-token');
}

function comparisonSetup(PDO $pdo, array $payload): void
{
    $engine = $payload['engine'];
    comparisonNeed(comparisonTables($pdo, $engine) === [], 'nonempty-schema-refused');
    $pdo->exec('CREATE TABLE workload_fixture (token VARCHAR(64) PRIMARY KEY)'.($engine === 'mysql' ? ' ENGINE=InnoDB' : ''));
    $pdo->prepare('INSERT INTO workload_fixture(token) VALUES(?)')->execute([$payload['token']]);
    $pdo->exec($engine === 'mysql'
        ? 'CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(100) NOT NULL,email VARCHAR(150) NOT NULL UNIQUE,password VARCHAR(255) NOT NULL,role VARCHAR(30) NOT NULL,deleted_at TIMESTAMP NULL) ENGINE=InnoDB'
        : 'CREATE TABLE users (id INTEGER PRIMARY KEY,name TEXT NOT NULL,email TEXT NOT NULL UNIQUE,password TEXT NOT NULL,role TEXT NOT NULL,deleted_at TEXT NULL)');
    $pdo->exec($engine === 'mysql'
        ? 'CREATE TABLE notification_read_states (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,context VARCHAR(100) NOT NULL,last_seen_at BIGINT UNSIGNED NULL,read_ids JSON NOT NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,UNIQUE KEY notification_context(user_id,context),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB'
        : 'CREATE TABLE notification_read_states (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,context TEXT NOT NULL,last_seen_at INTEGER NULL,read_ids TEXT NOT NULL,created_at TEXT NULL,updated_at TEXT NULL,UNIQUE(user_id,context),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $profile = $payload['profile'];
    $baseline = [];
    for ($index = 0; $index < $profile['baseline_ids']; $index++) {
        $baseline['baseline:'.$index] = $profile['watermark_ms'];
    }
    $insertUser = $pdo->prepare('INSERT INTO users(id,name,email,password,role) VALUES(?,?,?,?,?)');
    $insertState = $pdo->prepare('INSERT INTO notification_read_states(user_id,context,last_seen_at,read_ids,created_at,updated_at) VALUES(?,?,?,?,?,?)');
    $pdo->beginTransaction();
    for ($id = 1; $id <= $profile['users']; $id++) {
        $insertUser->execute([$id, 'Synthetic comparison '.$id, 'synthetic-'.$id.'@example.invalid', 'not-a-login-credential', 'client']);
        $insertState->execute([$id, 'client', $profile['watermark_ms'] - 1000, json_encode($baseline, JSON_THROW_ON_ERROR), '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
    }
    $pdo->commit();
    comparisonOwned($pdo, $payload);
}

function comparisonBoot(array $payload, string $source, string $runtime, string $sqlite): object
{
    require $source.'/vendor/autoload.php';
    $app = require $source.'/bootstrap/app.php';
    $app->useEnvironmentPath($runtime)->loadEnvironmentFrom('.env.never-created');
    $app->useStoragePath($runtime.'/storage');
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        static function ($app) use ($payload, $runtime, $sqlite): void {
            $connection = $payload['engine'] === 'mysql'
                ? ['driver' => 'mysql', 'host' => '', 'port' => '', 'database' => $payload['target']['database'],
                    'username' => $payload['account']['username'], 'password' => $payload['account']['password'],
                    'unix_socket' => $payload['target']['socket'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin',
                    'prefix' => '', 'strict' => true, 'repro_require_server_identity' => true,
                    'repro_expected_server_uuid' => $payload['target']['server_uuid'], 'options' => [PDO::ATTR_TIMEOUT => 5]]
                : ['driver' => 'sqlite', 'database' => $sqlite, 'prefix' => '', 'foreign_key_constraints' => true,
                    'busy_timeout' => 5000, 'journal_mode' => 'WAL', 'synchronous' => 'FULL'];
            $app['config']->set([
                'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.debug' => false,
                'database.default' => 'fixture', 'database.connections' => ['fixture' => $connection], 'database.redis' => [],
                'services' => [], 'server-monitor' => ['enabled' => false, 'socket' => '', 'key_file' => ''],
                'cache.default' => 'array', 'cache.stores' => ['array' => ['driver' => 'array', 'serialize' => false]],
                'session.driver' => 'array', 'session.files' => $runtime.'/storage/framework/sessions',
                'mail.default' => 'array', 'mail.mailers' => ['array' => ['transport' => 'array']],
                'broadcasting.default' => 'null', 'broadcasting.connections' => ['null' => ['driver' => 'null']],
                'logging.default' => 'single', 'logging.channels' => ['single' => ['driver' => 'single', 'path' => $runtime.'/storage/logs/compare.log', 'level' => 'error']],
                'filesystems.default' => 'local', 'filesystems.disks' => ['local' => ['driver' => 'local', 'root' => $runtime.'/storage/app/private', 'throw' => true]],
                'view.compiled' => $runtime.'/storage/framework/views', 'queue.default' => 'null',
                'queue.connections' => ['null' => ['driver' => 'null']], 'queue.failed.driver' => 'null',
            ]);
        });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection();
    comparisonNeed($db->getDriverName() === $payload['engine'] && array_keys(config('database.connections')) === ['fixture']
        && config('services') === [] && config('server-monitor.enabled') === false && storage_path() === $runtime.'/storage', 'application-isolation');
    if ($payload['engine'] === 'mysql') {
        $actual = $db->selectOne('SELECT @@server_uuid AS uuid,CURRENT_USER() AS account');
        comparisonNeed($actual->uuid === $payload['target']['server_uuid'] && $actual->account === 'repro_accept_worker@localhost', 'application-mysql-identity');
    } else {
        comparisonNeed($db->getDatabaseName() === $sqlite && $db->selectOne('PRAGMA journal_mode')->journal_mode === 'wal'
            && (int) $db->selectOne('PRAGMA synchronous')->synchronous === 2
            && (int) $db->selectOne('PRAGMA foreign_keys')->foreign_keys === 1
            && (int) $db->selectOne('PRAGMA busy_timeout')->timeout === 5000, 'application-sqlite-durability');
    }

    return $app;
}

function comparisonBarrier(string $shared, string $ready, string $go): void
{
    $file = fopen($shared.'/'.$ready, 'x');
    comparisonNeed($file !== false, 'exclusive-ready-file');
    fclose($file);
    $deadline = microtime(true) + 45;
    while (! is_file($shared.'/'.$go)) {
        comparisonNeed(microtime(true) < $deadline, 'start-barrier-timeout');
        usleep(2000);
        clearstatcache(true, $shared.'/'.$go);
    }
}

function comparisonWorker(array $payload, string $source, string $runtime, string $shared, string $sqlite): array
{
    $GLOBALS['phase'] = $payload['engine'].'-worker-bootstrap';
    $app = comparisonBoot($payload, $source, $runtime, $sqlite);
    $profile = $payload['profile'];
    $GLOBALS['phase'] = $payload['engine'].'-worker-users';
    $users = App\Models\User::query()->orderBy('id')->get()->keyBy('id');
    comparisonNeed($users->count() === $profile['users'], 'synthetic-user-count');
    $states = $app->make(App\Services\NotificationReadStateService::class);
    $controller = $app->make(App\Http\Controllers\API\NotificationReadStateController::class);
    $slot = $payload['worker'];
    $retries = 0;
    Illuminate\Support\Facades\Event::listen(Illuminate\Log\Events\MessageLogged::class,
        static function ($event) use (&$retries): void {
            if ($event->message === 'Write blocked by another database writer; retrying.' && ($event->context['context'] ?? '') === 'notifications.read-state') {
                $retries++;
            }
        });
    $perform = static function (string $phase, int $index) use ($slot, $profile, $users, $states, $controller, $payload): array {
        $write = $index % $profile['write_every'] === 0;
        $GLOBALS['phase'] = $payload['engine'].'-'.$phase.'-'.($write ? 'write' : 'read');
        $readIndex = intdiv($index, 5) * 4 + ($index % 5) - 1;
        $userId = $write ? 1 : 1 + (($readIndex + $slot * intdiv($profile['users'], $profile['workers'])) % $profile['users']);
        $request = Illuminate\Http\Request::create('/synthetic-notification-state', $write ? 'POST' : 'GET',
            $write ? ['ids' => [$phase.':'.$slot.':'.$index], 'lastSeenAt' => $profile['watermark_ms']] : []);
        $request->setUserResolver(static fn () => $users->get($userId));
        $started = hrtime(true);
        if ($write) {
            $response = $controller->update($request, $states);
            comparisonNeed($response->getStatusCode() === 200, 'controller-status');
            $serialized = $response->getContent();
        } else {
            $serialized = json_encode(['data' => $states->get($request)], JSON_THROW_ON_ERROR);
        }
        $elapsed = max(1, (int) ceil((hrtime(true) - $started) / 1000));
        $state = json_decode($serialized, true, flags: JSON_THROW_ON_ERROR)['data'];
        comparisonNeed(is_array($state['readIds']) && count($state['readIds']) >= $profile['baseline_ids'], 'response-shape');
        if ($userId !== 1) {
            comparisonNeed(count($state['readIds']) === $profile['baseline_ids'], 'cross-user-write');
        }

        return [$write ? 'write' : 'read', $elapsed, $userId];
    };
    comparisonBarrier($shared, 'boot-ready-'.$slot, 'warmup-go');
    for ($index = 0; $index < $profile['warmup_operations']; $index++) {
        $perform('warmup', $index);
    }
    comparisonBarrier($shared, 'ready-'.$slot, 'go');
    $samples = [];
    $retries = 0;
    $started = hrtime(true);
    for ($index = 0; $index < $profile['measured_operations']; $index++) {
        $samples[] = $perform('measured', $index);
    }

    $ended = hrtime(true);
    return ['samples' => $samples, 'worker_elapsed_us' => (int) ceil(($ended - $started) / 1000),
        'measured_started_ns' => $started, 'measured_ended_ns' => $ended,
        'sqlite_locked_write_retry_events' => $retries, 'worker' => $slot];
}

function comparisonVerify(PDO $pdo, array $payload): array
{
    $profile = $payload['profile'];
    $baseline = array_map(static fn ($index) => 'baseline:'.$index, range(0, $profile['baseline_ids'] - 1));
    $combined = $baseline;
    foreach (['warmup' => $profile['warmup_operations'], 'measured' => $profile['measured_operations']] as $phase => $count) {
        for ($slot = 0; $slot < $profile['workers']; $slot++) {
            for ($index = 0; $index < $count; $index += $profile['write_every']) {
                $combined[] = $phase.':'.$slot.':'.$index;
            }
        }
    }
    sort($baseline);
    sort($combined);
    $rows = $pdo->query('SELECT user_id,context,last_seen_at,read_ids FROM notification_read_states ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    comparisonNeed(count($rows) === $profile['users'], 'receipt-row-count');
    $logical = [];
    foreach ($rows as $row) {
        $id = (int) $row['user_id'];
        $keys = array_keys(json_decode($row['read_ids'], true, flags: JSON_THROW_ON_ERROR));
        sort($keys);
        $watermark = $profile['watermark_ms'] - ($id === 1 ? 0 : 1000);
        comparisonNeed($row['context'] === 'client' && $keys === ($id === 1 ? $combined : $baseline)
            && (int) $row['last_seen_at'] === $watermark, 'lost-duplicate-or-cross-user-receipt');
        $logical[] = [$id, $row['context'], $watermark, $keys];
    }
    comparisonNeed((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $profile['users'], 'user-row-count');

    return ['logical_receipt_sha256' => hash('sha256', json_encode($logical, JSON_THROW_ON_ERROR)),
        'verified_users' => $profile['users'], 'verified_contended_user_receipts' => count($combined),
        'exact_receipt_union_verified' => true, 'owner_isolation_verified' => true, 'watermarks_verified' => true];
}

$phase = 'input';
$exit = 1;
$report = ['scope' => 'synthetic-laravel-smoke', 'kind' => 'notification-comparison', 'result' => 'refused'];
try {
    comparisonNeed(PHP_OS_FAMILY === 'Linux' && count($argv) === 4, 'linux-private-launcher-required');
    [$script, $source, $runtime, $shared] = $argv;
    comparisonNeed(realpath($source) === $source && realpath($runtime) === $runtime && realpath($shared) === $shared
        && ! str_starts_with($source, '/var/www/') && ! file_exists($source.'/.env')
        && ! file_exists($source.'/bootstrap/cache/config.php'), 'private-source-and-runtime-required');
    $parent = getenv('REPRO_WORKLOAD_PARENT_NETWORK_NAMESPACE');
    comparisonNeed(is_string($parent) && preg_match('/\Anet:\[[0-9]+\]\z/', $parent) === 1
        && readlink('/proc/self/ns/net') !== $parent && getenv('APP_ENV') === 'testing', 'network-namespace-required');
    foreach (array_slice(file('/proc/net/dev', FILE_IGNORE_NEW_LINES), 2) as $line) {
        comparisonNeed(trim(explode(':', $line, 2)[0]) === 'lo', 'network-interface-present');
    }
    comparisonNeed(count(file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) <= 1, 'network-route-present');
    $raw = stream_get_contents(STDIN, 16385);
    comparisonNeed(strlen($raw) <= 16384, 'input-size');
    $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    unset($raw);
    comparisonNeed(in_array($payload['engine'], ['mysql', 'sqlite'], true)
        && in_array($payload['action'], ['setup', 'worker', 'verify', 'cleanup'], true)
        && preg_match('/\A[a-f0-9]{32}\z/', $payload['token']) === 1, 'fixture-action');
    $profile = $payload['profile'];
    comparisonNeed($profile['version'] === 1 && $profile['users'] === 100 && $profile['baseline_ids'] === 10
        && $profile['workers'] >= 1 && $profile['workers'] <= 4 && $profile['warmup_operations'] === 10
        && $profile['measured_operations'] >= 25 && $profile['measured_operations'] <= 500
        && $profile['write_every'] === 5 && $payload['worker'] >= 0 && $payload['worker'] < $profile['workers']
        && is_int($profile['watermark_ms']) && abs((int) (microtime(true) * 1000) - $profile['watermark_ms']) < 3600000,
        'bounded-synthetic-profile');
    $phase = $payload['action'];
    $sqlite = $shared.'/fixture.sqlite';
    if ($payload['engine'] === 'mysql') {
        $pdo = comparisonMysql($payload);
    } else {
        if ($phase === 'setup') {
            $file = fopen($sqlite, 'x');
            comparisonNeed($file !== false, 'fresh-sqlite-file-required');
            fclose($file);
        }
        comparisonNeed(realpath($sqlite) === $sqlite && is_file($sqlite) && (fileperms($sqlite) & 0777) === 0600, 'private-sqlite-file-required');
        $pdo = new PDO('sqlite:'.$sqlite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=FULL');
    }
    if ($phase === 'setup') {
        comparisonSetup($pdo, $payload);
        $report['seeded_users'] = $profile['users'];
    } elseif ($phase === 'cleanup') {
        $tables = comparisonTables($pdo, $payload['engine']);
        comparisonNeed(in_array('workload_fixture', $tables, true)
            && $pdo->query('SELECT token FROM workload_fixture')->fetchColumn() === $payload['token']
            && array_diff($tables, ['workload_fixture', 'notification_read_states', 'users']) === [], 'cleanup-ownership');
        foreach (['notification_read_states', 'users', 'workload_fixture'] as $table) {
            if (in_array($table, $tables, true)) {
                $pdo->exec('DROP TABLE '.$table);
            }
        }
        comparisonNeed(comparisonTables($pdo, $payload['engine']) === [], 'cleanup-empty-schema');
        $report['fixture_tables_removed'] = true;
    } else {
        comparisonOwned($pdo, $payload);
        if ($phase === 'worker') {
            $pdo = null;
            $report += comparisonWorker($payload, $source, $runtime, $shared, $sqlite);
        } else {
            $report += comparisonVerify($pdo, $payload);
        }
    }
    $report['action'] = $payload['action'];
    $report['engine'] = $payload['engine'];
    $report['result'] = 'passed';
    $exit = 0;
} catch (Throwable $error) {
    $report['phase'] = $phase;
    $report['reason'] = $error instanceof ComparisonRefusal ? $error->getMessage() : get_class($error);
    if ($error instanceof PDOException && is_array($error->errorInfo ?? null)) {
        $report['driver_error_code'] = (int) ($error->errorInfo[1] ?? 0);
        $report['reason'] .= '-driver-'.$report['driver_error_code'];
    }
}
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($exit);
