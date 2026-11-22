<?php

declare(strict_types=1);

// Invoked only by run.py inside a new user/network namespace. All records are
// synthetic; this is not a production schema, migrations or HTTP load test.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);

final class SmokeRefusal extends RuntimeException {}

function requireSmoke(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new SmokeRefusal($reason);
    }
}

function connectSmoke(array $input, string $role): PDO
{
    $account = $input['accounts'][$role];
    $pdo = new PDO('mysql:unix_socket='.$input['socket'].';dbname='.$input['database'].';charset=utf8mb4',
        $account['username'], $account['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    $identity = $pdo->query('SELECT DATABASE() AS db,CURRENT_USER() AS account,@@server_uuid AS uuid,VERSION() AS version,'
        .'@@socket AS socket,@@skip_networking AS local_only,@@foreign_key_checks AS fk,'
        .'@@innodb_flush_log_at_trx_commit AS durable,@@sync_binlog AS binlog')->fetch(PDO::FETCH_ASSOC);
    requireSmoke($identity['db'] === 'repro_mysql84_acceptance'
        && $identity['account'] === $account['username'].'@localhost'
        && $identity['uuid'] === $input['server_uuid'] && $identity['version'] === '8.4.13'
        && $identity['socket'] === $input['socket'] && (int) $identity['local_only'] === 1
        && (int) $identity['fk'] === 1 && (int) $identity['durable'] === 1 && (int) $identity['binlog'] === 1,
        'actual-target-identity-'.$role);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    verifySmokeGrants($pdo, $role);

    return $pdo;
}

function verifySmokeGrants(PDO $pdo, string $role): void
{
    $allowed = match ($role) {
        'admin' => ['ALTER', 'CREATE', 'DELETE', 'DROP', 'INDEX', 'INSERT', 'REFERENCES', 'SELECT', 'TRIGGER', 'UPDATE'],
        'worker' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
        'reader' => ['SELECT'],
    };
    $seen = [];
    $partialRevokes = (int) $pdo->query('SELECT @@partial_revokes')->fetchColumn();
    foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        $principal = '`repro_accept_'.$role.'`@`localhost`';
        if ($grant === 'GRANT USAGE ON *.* TO '.$principal) {
            continue;
        }
        requireSmoke((bool) preg_match('/\AGRANT ([A-Z, ]+) ON `([^`]+)`\.\* TO '.preg_quote($principal, '/').'\z/', $grant, $match),
            'unexpected-privilege-'.$role);
        $expected = $partialRevokes ? 'repro_mysql84_acceptance' : 'repro\\_mysql84\\_acceptance';
        requireSmoke($match[2] === $expected, 'nonliteral-schema-grant-'.$role);
        $seen = array_merge($seen, explode(', ', $match[1]));
    }
    sort($seen);
    requireSmoke($seen === $allowed, 'privilege-set-'.$role);
}

function fixtureInventory(PDO $pdo): array
{
    $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $catalog => $column) {
        requireSmoke((int) $pdo->query('SELECT COUNT(*) FROM information_schema.'.$catalog.' WHERE '.$column.'=DATABASE()')->fetchColumn() === 0,
            'unexpected-schema-object');
    }

    return $tables;
}

$phase = 'input';
$admin = null;
$created = [];
$checks = [];
$report = ['scope' => 'synthetic-laravel-smoke', 'result' => 'refused', 'production_cutover' => false];
$exit = 1;

try {
    requireSmoke(PHP_OS_FAMILY === 'Linux' && count($argv) === 4 && extension_loaded('pdo_mysql'), 'linux-php-mysql-required');
    $parentNamespace = getenv('REPRO_WORKLOAD_PARENT_NETWORK_NAMESPACE');
    requireSmoke(is_string($parentNamespace) && preg_match('/\Anet:\[[0-9]+\]\z/', $parentNamespace) === 1
        && readlink('/proc/self/ns/net') !== $parentNamespace, 'launcher-network-namespace-required');
    $networkLines = array_slice(file('/proc/net/dev', FILE_IGNORE_NEW_LINES), 2);
    foreach ($networkLines as $line) {
        requireSmoke(trim(explode(':', $line, 2)[0]) === 'lo', 'network-interface-present');
    }
    requireSmoke(count(file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) <= 1, 'network-route-present');
    [$script, $source, $runtime, $mode] = $argv;
    requireSmoke(in_array($mode, ['check', 'smoke'], true), 'invalid-mode');
    requireSmoke(getenv('APP_ENV') === 'testing' && getenv('SERVER_MONITOR_ENABLED') === 'false', 'isolated-environment-required');
    requireSmoke(realpath($source) === $source && realpath($runtime) === $runtime && ! str_starts_with($source, '/var/www/'), 'isolated-paths-required');
    requireSmoke(! file_exists($source.'/.env') && ! file_exists($source.'/bootstrap/cache/config.php'), 'source-config-refused');
    $raw = stream_get_contents(STDIN, 16385);
    requireSmoke(strlen($raw) <= 16384, 'credential-input-size');
    $input = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    unset($raw);
    requireSmoke($input['version'] === 1 && $input['database'] === 'repro_mysql84_acceptance'
        && preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/', $input['server_uuid']) === 1
        && preg_match('#\A/[A-Za-z0-9_./-]+\z#', $input['socket']) === 1, 'explicit-fixture-target-required');
    foreach (['admin', 'worker', 'reader'] as $role) {
        requireSmoke($input['accounts'][$role]['username'] === 'repro_accept_'.$role, 'fixture-account-required');
    }
    $phase = 'identity-and-grants';
    $admin = connectSmoke($input, 'admin');
    $reader = connectSmoke($input, 'reader');
    $workerProbe = connectSmoke($input, 'worker');
    requireSmoke((int) $admin->query("SELECT GET_LOCK('repro_mysql84_acceptance_smoke',0)")->fetchColumn() === 1, 'fixture-already-in-use');
    requireSmoke(fixtureInventory($admin) === [], 'nonempty-schema-refused');
    $checks['all_account_identities_and_exact_grants'] = true;
    $workerProbe = null;
    if ($mode === 'check') {
        $report += ['mode' => 'check', 'server_uuid' => $input['server_uuid'], 'database' => $input['database'], 'checks' => $checks];
        $report['result'] = 'passed';
        $exit = 0;
    } else {
        $phase = 'synthetic-schema';
        $ddl = [
            'users' => 'CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,name VARCHAR(100) NOT NULL,email VARCHAR(150) NOT NULL UNIQUE,password VARCHAR(255) NOT NULL,role VARCHAR(30) NOT NULL,remember_token VARCHAR(100) NULL,email_verified_at TIMESTAMP NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,deleted_at TIMESTAMP NULL) ENGINE=InnoDB',
            'notification_read_states' => 'CREATE TABLE notification_read_states (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,context VARCHAR(100) NOT NULL,last_seen_at BIGINT UNSIGNED NULL,read_ids JSON NOT NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,UNIQUE KEY notification_context (user_id,context),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB',
            'workload_jobs' => 'CREATE TABLE workload_jobs (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,queue VARCHAR(255) NOT NULL,payload LONGTEXT NOT NULL,attempts TINYINT UNSIGNED NOT NULL,reserved_at INT UNSIGNED NULL,available_at INT UNSIGNED NOT NULL,created_at INT UNSIGNED NOT NULL,INDEX(queue)) ENGINE=InnoDB',
            'workload_effects' => 'CREATE TABLE workload_effects (id VARCHAR(64) PRIMARY KEY,value VARCHAR(100) NOT NULL) ENGINE=InnoDB',
        ];
        foreach ($ddl as $table => $sql) {
            $admin->exec($sql);
            $created[] = $table;
        }
        $phase = 'laravel-bootstrap';
        require $source.'/vendor/autoload.php';
        $app = require $source.'/bootstrap/app.php';
        $app->useEnvironmentPath($runtime)->loadEnvironmentFrom('.env.never-created');
        $app->useStoragePath($runtime.'/storage');
        $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
            static function ($app) use ($input, $runtime): void {
                $db = ['driver' => 'mysql', 'host' => '', 'port' => '', 'database' => $input['database'],
                    'username' => $input['accounts']['worker']['username'], 'password' => $input['accounts']['worker']['password'],
                    'unix_socket' => $input['socket'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin',
                    'prefix' => '', 'prefix_indexes' => true, 'strict' => true, 'engine' => 'InnoDB',
                    'repro_require_server_identity' => true, 'repro_expected_server_uuid' => $input['server_uuid'],
                    'options' => [PDO::ATTR_TIMEOUT => 5]];
                $app['config']->set([
                    'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.debug' => false,
                    'database.default' => 'mysql', 'database.connections' => ['mysql' => $db], 'database.redis' => [],
                    'services' => [], 'server-monitor' => ['enabled' => false, 'socket' => '', 'key_file' => ''],
                    'cache.default' => 'array', 'cache.stores' => ['array' => ['driver' => 'array', 'serialize' => false]],
                    'session.driver' => 'array', 'session.files' => $runtime.'/storage/framework/sessions',
                    'mail.default' => 'array', 'mail.mailers' => ['array' => ['transport' => 'array']],
                    'broadcasting.default' => 'null', 'broadcasting.connections' => ['null' => ['driver' => 'null']],
                    'logging.default' => 'single', 'logging.channels' => ['single' => ['driver' => 'single',
                        'path' => $runtime.'/storage/logs/smoke.log', 'level' => 'error']],
                    'filesystems.default' => 'local', 'filesystems.disks' => ['local' => ['driver' => 'local',
                        'root' => $runtime.'/storage/app/private', 'throw' => true]],
                    'view.compiled' => $runtime.'/storage/framework/views',
                    'queue.default' => 'database', 'queue.connections' => ['database' => ['driver' => 'database',
                        'connection' => 'mysql', 'table' => 'workload_jobs', 'queue' => 'synthetic-smoke',
                        'retry_after' => 90, 'after_commit' => true]],
                    'queue.failed.driver' => 'null',
                ]);
            });
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = Illuminate\Support\Facades\DB::connection();
        $identity = $db->selectOne('SELECT @@server_uuid AS uuid,DATABASE() AS db,CURRENT_USER() AS account');
        requireSmoke($identity->uuid === $input['server_uuid'] && $identity->db === $input['database']
            && $identity->account === 'repro_accept_worker@localhost' && $db->transactionLevel() === 0, 'laravel-identity');
        requireSmoke(array_keys(config('database.connections')) === ['mysql'] && config('services') === []
            && config('server-monitor.enabled') === false && storage_path() === $runtime.'/storage', 'laravel-isolation');
        $checks['laravel_worker_identity_and_isolated_config'] = true;

        $phase = 'transaction-rollback';
        $db->beginTransaction();
        $db->table('workload_effects')->insert(['id' => 'rolled-back', 'value' => 'synthetic']);
        requireSmoke((int) $reader->query('SELECT COUNT(*) FROM workload_effects')->fetchColumn() === 0, 'uncommitted-write-visible');
        $db->rollBack();
        requireSmoke((int) $reader->query('SELECT COUNT(*) FROM workload_effects')->fetchColumn() === 0, 'rollback-lost');
        $checks['transaction_visibility_and_rollback'] = true;

        $phase = 'synthetic-authentication';
        $password = bin2hex(random_bytes(24));
        foreach ([1, 2] as $id) {
            $db->table('users')->insert(['id' => $id, 'name' => 'Synthetic acceptance '.$id,
                'email' => 'synthetic-'.$id.'@example.invalid', 'password' => Illuminate\Support\Facades\Hash::make($password),
                'role' => 'client', 'created_at' => now(), 'updated_at' => now()]);
        }
        requireSmoke(! Illuminate\Support\Facades\Auth::once(['email' => 'synthetic-1@example.invalid', 'password' => $password.'wrong']), 'invalid-login-accepted');
        requireSmoke(Illuminate\Support\Facades\Auth::once(['email' => 'synthetic-1@example.invalid', 'password' => $password])
            && (int) Illuminate\Support\Facades\Auth::id() === 1, 'valid-login-refused');
        unset($password);
        $checks['synthetic_login_accepts_valid_and_rejects_invalid_password'] = true;

        $phase = 'notification-state';
        $states = $app->make(App\Services\NotificationReadStateService::class);
        $controller = $app->make(App\Http\Controllers\API\NotificationReadStateController::class);
        $user = App\Models\User::findOrFail(1);
        $watermark = (int) now()->getTimestampMs() - 1000;
        $request = static function (array $ids, int $stamp) use ($user): Illuminate\Http\Request {
            $request = Illuminate\Http\Request::create('/synthetic-notification-state', 'POST', ['ids' => $ids, 'lastSeenAt' => $stamp]);
            $request->setUserResolver(static fn () => $user);
            return $request;
        };
        requireSmoke($controller->update($request(['smoke:one'], $watermark), $states)->getStatusCode() === 200, 'notification-first-response');
        $response = $controller->update($request(['smoke:two'], $watermark - 1000), $states);
        $state = $response->getData(true)['data'];
        $keys = array_keys($state['readIds']);
        sort($keys);
        requireSmoke($response->getStatusCode() === 200 && $keys === ['smoke:one', 'smoke:two'] && $state['lastSeenAt'] === $watermark,
            'notification-merge-or-monotonicity');
        $stored = $reader->query('SELECT read_ids,last_seen_at FROM notification_read_states WHERE user_id=1')->fetch(PDO::FETCH_ASSOC);
        $persistedKeys = array_keys(json_decode($stored['read_ids'], true, flags: JSON_THROW_ON_ERROR));
        sort($persistedKeys);
        requireSmoke($persistedKeys === $keys && (int) $stored['last_seen_at'] === $watermark
            && (array) $states->forUser(App\Models\User::findOrFail(2))['readIds'] === [], 'notification-persistence-or-owner-isolation');
        $checks['notification_merge_persistence_monotonicity_and_owner_isolation'] = true;

        $phase = 'native-queue';
        // The serialized class exists only in this executable, so it cannot be
        // accidentally used as a production job through application autoloading.
        class SyntheticAcceptanceJob implements Illuminate\Contracts\Queue\ShouldQueue
        {
            public function handle(): void
            {
                Illuminate\Support\Facades\DB::transaction(static function (): void {
                    Illuminate\Support\Facades\DB::table('workload_effects')->insert(['id' => 'queued-receipt', 'value' => 'synthetic-committed']);
                });
            }
        }
        $queue = $app['queue']->connection('database');
        requireSmoke($queue::class === Illuminate\Queue\DatabaseQueue::class, 'native-queue-class');
        $queue->push(new SyntheticAcceptanceJob, '', 'synthetic-smoke');
        $job = $queue->pop('synthetic-smoke');
        requireSmoke($job !== null && $job->attempts() === 1, 'queue-reservation');
        $job->fire();
        requireSmoke($job->isDeleted() && $queue->size('synthetic-smoke') === 0, 'queue-acknowledgement');
        requireSmoke((int) $reader->query("SELECT COUNT(*) FROM workload_effects WHERE id='queued-receipt' AND value='synthetic-committed'")->fetchColumn() === 1,
            'queue-effect-persistence');
        $checks['native_queue_reservation_handler_acknowledgement_and_committed_effect'] = true;
        $report += ['mode' => 'smoke', 'server_uuid' => $input['server_uuid'], 'database' => $input['database'], 'checks' => $checks,
            'fixture_users' => 2, 'fixture_jobs' => 1, 'application_http_listener' => false];
        $report['result'] = 'passed';
        $exit = 0;
    }
} catch (Throwable $error) {
    $report['phase'] = $phase;
    $report['reason'] = $error instanceof SmokeRefusal ? $error->getMessage() : get_class($error);
    // No exception message, stack trace, credential or application record output.
} finally {
    $cleaned = true;
    if ($admin instanceof PDO) {
        try {
            if (isset($db) && $db->transactionLevel() > 0) {
                $db->rollBack();
            }
            foreach (array_reverse($created) as $table) {
                $admin->exec('DROP TABLE `'.$table.'`');
            }
            if ($created !== []) {
                requireSmoke(fixtureInventory($admin) === [], 'schema-not-empty-after-cleanup');
            }
            $admin->query("SELECT RELEASE_LOCK('repro_mysql84_acceptance_smoke')");
        } catch (Throwable) {
            $cleaned = false;
            $report['result'] = 'refused';
            $report['phase'] = 'fixture-cleanup';
            $exit = 1;
        }
    }
    $report['created_fixture_tables_removed'] = $cleaned;
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
}
exit($exit);
