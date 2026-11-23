<?php

declare(strict_types=1);

// Private synthetic fixture entrypoint. It loads the unmodified application
// kernel, routes and middleware. Only the private application clock is pinned.
ini_set('display_errors', '0');
ini_set('log_errors', PHP_SAPI === 'cli' ? '0' : '1');
$phase = 'input';

function fixtureNeed(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function fixturePdo(array $config, string $account = 'database'): PDO
{
    if ($config['engine'] === 'sqlite') {
        $pdo = new PDO('sqlite:'.$config['sqlite'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
        return $pdo;
    }
    $credential = $config[$account];
    $database = $account === 'monitor' ? 'performance_schema' : $config['mysql_database'];
    return new PDO('mysql:unix_socket='.$config['mysql_socket'].';dbname='.$database.';charset=utf8mb4',
        $credential['username'], $credential['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}

function fixtureIdentity(PDO $pdo, array $config): void
{
    if ($config['engine'] === 'mysql') {
        $row = $pdo->query('SELECT @@server_uuid uuid,VERSION() version,@@skip_networking isolated,@@innodb_flush_log_at_trx_commit durable,@@sync_binlog binlog,@@foreign_key_checks fk,CURRENT_USER() principal')->fetch(PDO::FETCH_ASSOC);
        fixtureNeed($row['uuid'] === $config['uuid'] && $row['version'] === '8.4.13'
            && (int)$row['isolated'] === 1 && (int)$row['durable'] === 1 && (int)$row['binlog'] === 1
            && (int)$row['fk'] === 1 && $row['principal'] === $config['database']['username'].'@localhost', 'database-identity');
        if (isset($config['mysql_datadir'])) {
            $native = $pdo->query('SELECT @@datadir datadir,@@log_bin log_bin,@@event_scheduler events,@@local_infile local_files')->fetch(PDO::FETCH_ASSOC);
            fixtureNeed($native['datadir'] === $config['mysql_datadir'] && (int)$native['log_bin'] === 1
                && $native['events'] === 'OFF' && (int)$native['local_files'] === 0, 'native-copy-database-identity');
        }
    } else {
        fixtureNeed($pdo->query('PRAGMA journal_mode')->fetchColumn() === 'wal'
            && (int)$pdo->query('PRAGMA synchronous')->fetchColumn() === 2
            && (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'sqlite-durability');
    }
}

function fixtureHistory(PDO $monitor, int $thread, int $after = 0): array
{
    $statement = $monitor->prepare('SELECT COALESCE(SUM(EVENT_ID > ?),0) entries,COALESCE(SUM(CASE WHEN EVENT_ID > ? THEN WARNINGS ELSE 0 END),0) warnings,COALESCE(SUM(CASE WHEN EVENT_ID > ? THEN ERRORS ELSE 0 END),0) errors,COALESCE(MAX(EVENT_ID),0) last_event,COALESCE(SUM(EVENT_ID = ?),0) boundary_present FROM performance_schema.events_statements_history WHERE THREAD_ID=?');
    $statement->execute([$after, $after, $after, $after, $thread]);
    return $statement->fetch(PDO::FETCH_ASSOC);
}

function fixtureOfferIds(int $base): array
{
    return array_map(static fn (int $id) => '00000000-0000-4000-8000-'.str_pad((string)($base + $id), 12, '0', STR_PAD_LEFT), range(1, 5));
}

function fixtureVoiceDigest(PDO $pdo, int $base): string
{
    $graph = [];
    foreach (['voice_calls' => 'id', 'voice_incoming_offers' => 'voice_call_id'] as $table => $key) {
        $query = $pdo->prepare('SELECT * FROM '.$table.' WHERE '.$key.' BETWEEN ? AND ? ORDER BY id');
        $query->execute([$base + 1, $base + 5]);
        $graph[$table] = $query->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR));
}

function fixtureOwner(PDO $pdo, array $config): void
{
    fixtureNeed($pdo->query('SELECT token FROM _repro_http_fixture_owner')->fetchColumn() === $config['owner_token'], 'fixture-ownership-required');
}

function fixtureClaim(PDO $pdo, array $config): void
{
    fixtureNeed((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'empty-users-required');
    $pdo->exec('CREATE TABLE _repro_http_fixture_owner(token VARCHAR(64) NOT NULL)');
    $pdo->prepare('INSERT INTO _repro_http_fixture_owner(token) VALUES(?)')->execute([$config['owner_token']]);
}

try {
    $cli = PHP_SAPI === 'cli';
    $mode = $cli ? ($argv[1] ?? '') : 'http';
    fixtureNeed(in_array($mode, ['migrate', 'reset', 'seed', 'verify', 'warning-probe', 'synthetic-history', 'http'], true), 'fixture-mode');
    $root = getenv('REPRO_HTTP_ROOT');
    $native = is_string($root) && preg_match('~\A/run/repro-mysql-http-[a-f0-9]{12}\z~', $root) === 1;
    fixtureNeed($native || $root === false, 'fixture-root-path');
    $root = $native ? $root : '';
    $appRoot = $root.'/workspace';
    $runRoot = $root.'/runs';
    if ($cli) {
        $raw = stream_get_contents(STDIN, 65537);
    } else {
        $path = getenv('REPRO_HTTP_CONFIG');
        fixtureNeed(is_string($path) && preg_match('~\A'.preg_quote($runRoot, '~').'/round-[1-2]-(sqlite|mysql)/app-config.json\z~', $path) === 1
            && !is_link($path) && (fileperms($path) & 0777) === 0600, 'protected-fixture-config');
        $raw = file_get_contents($path);
    }
    fixtureNeed(is_string($raw) && strlen($raw) <= 65536, 'fixture-input-budget');
    $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    unset($raw);
    $copied = ($config['scope'] ?? null) === 'copied-laravel-http-v1';
    fixtureNeed(($copied || ($config['scope'] ?? null) === 'synthetic-laravel-http-v1') && (!$native || $copied)
        && in_array($config['engine'] ?? null, ['sqlite', 'mysql'], true)
        && is_dir($appRoot) && !file_exists($appRoot.'/.env')
        && !file_exists($appRoot.'/bootstrap/cache/config.php'), 'private-application-required');
    if ($copied) {
        fixtureNeed(!in_array($mode, ['migrate', 'reset', 'synthetic-history'], true)
            && preg_match('/\A[a-f0-9]{64}\z/', $config['owner_token'] ?? '') === 1, 'owned-copy-mode-required');
    }
    fixtureNeed(preg_match('~\A'.preg_quote($runRoot, '~').'/[a-z0-9-]+\z~', $config['runtime']) === 1, 'private-runtime-paths');
    $sqlitePath = $native ? $root.'/database/database.sqlite' : $config['runtime'].'/database.sqlite';
    fixtureNeed($config['sqlite'] === $sqlitePath, 'private-sqlite-path-required');
    $config['mysql_socket'] = $root.'/db/mysql.sock';
    $config['mysql_database'] = $native ? 'repro_dashboard_rehearsal_20261122' : 'repro_http_fixture';
    $base = $copied ? 2000000000 : 0;
    $operator = $base + 3;
    $clientIds = [$base + 1, $base + 2];
    fixtureNeed(posix_geteuid() !== 0 && getenv('APP_ENV') === 'testing', 'unprivileged-testing-process');
    fixtureNeed(preg_match('/\Anet:\[[0-9]+\]\z/', (string)getenv('REPRO_HTTP_PARENT_NET')) === 1
        && readlink('/proc/self/ns/net') !== getenv('REPRO_HTTP_PARENT_NET')
        && count(file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) <= 1, 'private-network-required');
    $anchor = $config['offer_time'];
    // Even the live fixture offer must already be expired according to wall time.
    fixtureNeed(is_int($anchor) && $anchor >= 946684800 && $anchor < min(time() - 300, 2147483347), 'voice-fixture-anchor');
    $environment = [
        'DB_CONNECTION' => $config['engine'], 'DB_DATABASE' => $config['engine'] === 'sqlite' ? $config['sqlite'] : $config['mysql_database'],
        'DB_SOCKET' => $config['mysql_socket'], 'DB_USERNAME' => $config['database']['username'], 'DB_PASSWORD' => $config['database']['password'],
        'DB_COLLATION' => 'utf8mb4_0900_bin', 'DB_JOURNAL_MODE' => 'WAL', 'DB_SYNCHRONOUS' => 'FULL', 'DB_BUSY_TIMEOUT' => '5000',
        'DB_FOREIGN_KEYS' => 'true', 'APP_KEY' => $config['app_key'], 'APP_URL' => 'http://repro-http-workload.invalid',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'database',
        'BROADCAST_DRIVER' => 'log', 'FILESYSTEM_DISK' => 'local', 'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'debug',
        'SERVER_MONITOR_ENABLED' => 'false', 'CLAMAV_SCAN_ON_UPLOAD' => 'false', 'APP_DEBUG' => 'false',
    ];
    foreach (['CONFIG', 'SERVICES', 'PACKAGES', 'ROUTES', 'EVENTS'] as $name) {
        $environment['APP_'.$name.'_CACHE'] = $config['runtime'].'/cache/'.strtolower($name).'.php';
    }
    foreach ($environment as $name => $value) { putenv($name.'='.$value); $_ENV[$name] = $_SERVER[$name] = $value; }
    unset($environment);
    require $appRoot.'/vendor/autoload.php';
    $app = require $appRoot.'/bootstrap/app.php';
    $app->useStoragePath($config['runtime'].'/storage');

    if ($mode === 'reset') {
        $phase = 'owned-fixture-reset';
        fixtureNeed($config['engine'] === 'mysql', 'mysql-reset-only');
        $pdo = fixturePdo($config);
        fixtureIdentity($pdo, $config);
        fixtureOwner($pdo, $config);
        $pdo->exec('DROP DATABASE repro_http_fixture');
        $pdo->exec('CREATE DATABASE repro_http_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
        echo json_encode(['result' => 'owned-fixture-reset']);
        exit;
    }

    if ($mode === 'migrate') {
        $phase = 'fresh-schema';
        $pdo = fixturePdo($config);
        $count = $config['engine'] === 'sqlite'
            ? $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn()
            : $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='repro_http_fixture'")->fetchColumn();
        fixtureNeed((int)$count === 0, 'empty-fixture-database-required');
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        fixtureNeed($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'fixture-migrations-failed');
        echo json_encode(['result' => 'migrated', 'engine' => $config['engine']]);
        exit;
    }

    if (in_array($mode, ['seed', 'verify', 'warning-probe', 'synthetic-history'], true)) {
        $phase = $mode;
        $pdo = fixturePdo($config);
        fixtureIdentity($pdo, $config);
        if ($copied) fixtureOwner($pdo, $config);
        if ($mode === 'synthetic-history') {
            fixtureClaim($pdo, $config);
            $pdo->prepare("INSERT INTO users(id,name,username,email,password,role) VALUES(101,'Synthetic History','synthetic-history','history@example.invalid',?,'client')")
                ->execute([$config['password_hash']]);
            $pdo->exec("INSERT INTO jobs(queue,payload,attempts,available_at,created_at) VALUES('synthetic-history','{}',0,1,1)");
            $pdo->exec("INSERT INTO voice_calls(id,direction,status,caller_user_id) VALUES(101,'INBOUND','completed',101)");
            echo json_encode(['result' => 'history-staged']);
        } elseif ($mode === 'seed') {
            if ($copied) {
                foreach (['users', 'personal_access_tokens', 'notification_read_states', 'voice_calls'] as $table) {
                    fixtureNeed((int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM '.$table)->fetchColumn() <= $base, 'fixture-id-range-in-use');
                }
            } else {
                fixtureClaim($pdo, $config);
            }
            $pdo->beginTransaction();
            foreach ([$clientIds[0] => 'client', $clientIds[1] => 'client', $operator => 'admin'] as $id => $role) {
                $stamp = '2026-01-01 00:00:00';
                $statement = $pdo->prepare('INSERT INTO users(id,name,username,email,password,role,email_verified_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
                $statement->execute([$id, 'Synthetic HTTP '.$id, 'synthetic-http-'.$id, 'http-'.$id.'@example.invalid', $config['password_hash'], $role, $stamp, $stamp, $stamp]);
                $statement = $pdo->prepare('INSERT INTO personal_access_tokens(id,tokenable_type,tokenable_id,name,token,abilities,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
                $statement->execute([$id, 'App\\Models\\User', $id, 'synthetic-http', hash('sha256', $config['tokens'][(string)$id]), '["*"]', $stamp, $stamp]);
                if ($role === 'client') {
                    $statement = $pdo->prepare('INSERT INTO notification_read_states(user_id,context,last_seen_at,read_ids,created_at,updated_at) VALUES(?,?,?,?,?,?)');
                    $statement->execute([$id, 'client', 1793491200000 + $id, json_encode(['synthetic-owner-'.$id => 1793491200000 + $id]), $stamp, $stamp]);
                }
            }
            $pdo->prepare('INSERT INTO personal_access_tokens(id,tokenable_type,tokenable_id,name,token,abilities,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$base + 4, 'App\\Models\\User', $operator, 'synthetic-preexisting', hash('sha256', $config['preexisting_token']), '["*"]', $stamp, $stamp]);
            $call = $pdo->prepare("INSERT INTO voice_calls(id,direction,status,caller_user_id,from_phone,to_phone,created_at,updated_at) VALUES(?,'INBOUND','ringing',?,'+12025550101','+12025550102',?,?)");
            $offer = $pdo->prepare("INSERT INTO voice_incoming_offers(id,voice_call_id,status,eligible_user_ids,expires_at,created_at,updated_at) VALUES(?,?,'waiting',?,?,?,?)");
            // Live and expired offers for the operator, then unrelated, wrong JSON type and stale controls.
            $cases = [[[$operator], 0, 300], [[$operator], -90, -30], [[$base + 30], -10, 300], [[(string)$operator], -15, 300], [[$operator], -600, -300]];
            foreach (fixtureOfferIds($base) as $index => $id) {
                [$targets, $createdOffset, $expiresOffset] = $cases[$index];
                $created = gmdate('Y-m-d H:i:s', $anchor + $createdOffset);
                $expires = gmdate('Y-m-d H:i:s', $anchor + $expiresOffset);
                $call->execute([$base + $index + 1, $clientIds[0], $created, $created]);
                $offer->execute([$id, $base + $index + 1, json_encode($targets, JSON_THROW_ON_ERROR), $expires, $created, $created]);
            }
            $pdo->commit();
            echo json_encode(['result' => 'seeded', 'users' => 3, 'voice_graph_sha256' => fixtureVoiceDigest($pdo, $base)]);
        } elseif ($mode === 'warning-probe') {
            fixtureNeed($config['engine'] === 'mysql', 'mysql-warning-probe-required');
            $monitor = fixturePdo($config, 'monitor');
            $connection = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
            $thread = (int)$monitor->query('SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID='.$connection)->fetchColumn();
            $before = (int)fixtureHistory($monitor, $thread)['last_event'];
            $pdo->query("SELECT CAST('synthetic-invalid-number' AS UNSIGNED)")->fetchColumn();
            $after = fixtureHistory($monitor, $thread, $before);
            fixtureNeed((int)$after['warnings'] === 1 && (int)$after['errors'] === 0
                && (int)$after['boundary_present'] === 1, 'warning-observation-failed');
            echo json_encode(['result' => 'warning-detected', 'warnings' => 1]);
        } else {
            $counts = [];
            foreach (['users', 'notification_read_states', 'system_overview_request_traces', 'system_overview_sessions', 'system_overview_error_events', 'jobs', 'messages', 'voice_calls', 'voice_incoming_offers', 'voice_browser_calls', 'voice_browser_legs', 'voice_phone_offers'] as $table) {
                $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
            }
            $readStates = $pdo->prepare('SELECT user_id,context,last_seen_at,read_ids FROM notification_read_states WHERE user_id IN (?,?) ORDER BY user_id');
            $readStates->execute($clientIds);
            $rows = $readStates->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $id = (int)$row['user_id'];
                fixtureNeed($row['context'] === 'client' && (int)$row['last_seen_at'] === 1793491200000 + $id
                    && json_decode($row['read_ids'], true) === ['synthetic-owner-'.$id => 1793491200000 + $id], 'persisted-owner-state-mismatch');
            }
            $actorTraces = $pdo->prepare('SELECT path,status_code,COUNT(*) AS count FROM system_overview_request_traces WHERE user_id IN (?,?,?) GROUP BY path,status_code ORDER BY path,status_code');
            $actorTraces->execute([...$clientIds, $operator]);
            $traces = $actorTraces->fetchAll(PDO::FETCH_ASSOC);
            $actorSessions = $pdo->prepare('SELECT COUNT(*) FROM system_overview_sessions WHERE user_id IN (?,?,?)');
            $actorSessions->execute([...$clientIds, $operator]);
            $uncheckedToken = $pdo->prepare('SELECT COUNT(*) FROM personal_access_tokens WHERE token=? AND last_used_at IS NULL');
            $uncheckedToken->execute([hash('sha256', $config['preexisting_token'])]);
            echo json_encode(['result' => 'verified', 'engine' => $config['engine'], 'counts' => $counts,
                'traces' => $traces, 'owner_state_preserved' => count($rows) === 2, 'voice_graph_sha256' => fixtureVoiceDigest($pdo, $base),
                'actor_sessions' => (int)$actorSessions->fetchColumn(), 'preexisting_token_unused' => (int)$uncheckedToken->fetchColumn() === 1]);
        }
        exit;
    }

    $phase = 'http-bootstrap';
    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestampUTC($anchor));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    fixtureNeed(now()->getTimestamp() === $anchor, 'application-clock-not-pinned');
    $db = $app['db']->connection();
    $pdo = $db->getPdo();
    fixtureIdentity($pdo, $config);
    if ($copied) fixtureOwner($pdo, $config);
    $monitor = null;
    $thread = $before = 0;
    if ($config['engine'] === 'mysql') {
        $monitor = fixturePdo($config, 'monitor');
        $connection = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $thread = (int)$monitor->query('SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID='.$connection)->fetchColumn();
        fixtureNeed($thread > 0, 'instrumented-thread-required');
        $before = (int)fixtureHistory($monitor, $thread)['last_event'];
    }
    $db->flushQueryLog();
    $db->enableQueryLog();
    $phase = 'real-http-kernel';
    $request = Illuminate\Http\Request::capture();
    $voice = $request->path() === 'api/voice/incoming-offers';
    fixtureNeed($request->getMethod() === 'GET' && ($voice || $request->path() === 'api/notifications')
        && $request->getQueryString() === null, 'fixed-fixture-route');
    foreach ($config['http_contract'] as $name => $expected) {
        fixtureNeed($request->header('X-Repro-'.$name) === (string)$expected, 'fixed-http-contract');
    }
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    fixtureNeed(now()->getTimestamp() === $anchor && time() > $anchor + 300, 'application-clock-changed');
    $queries = count($db->getQueryLog());
    $db->disableQueryLog();
    $warnings = 0;
    if ($monitor) {
        $history = fixtureHistory($monitor, $thread, $before);
        fixtureNeed((int)$history['entries'] >= $queries && (int)$history['boundary_present'] === 1
            && (int)$history['errors'] === 0, 'request-history-incomplete-or-errors');
        $warnings = (int)$history['warnings'];
    }
    fixtureNeed($queries > 0, 'request-query-instrumentation-required');
    $phase = 'response-validity';
    $controller = $voice ? App\Http\Controllers\API\Voice\VoiceTeamController::class : App\Http\Controllers\API\DashboardController::class;
    fixtureNeed($request->route()?->getController() instanceof $controller
        && $request->route()->getActionMethod() === ($voice ? 'offers' : 'notifications'), 'normal-controller-required');
    $middleware = $app['router']->gatherRouteMiddleware($request->route());
    fixtureNeed(in_array(Illuminate\Auth\Middleware\Authenticate::class.':sanctum', $middleware, true), 'normal-auth-middleware-required');
    if ($voice) {
        fixtureNeed(in_array(App\Http\Middleware\PermissionMiddleware::class.':voice-calls', $middleware, true)
            && in_array(App\Http\Middleware\PermissionMiddleware::class.':voice-calls,operate', $middleware, true), 'normal-voice-permissions-required');
    }
    if ($response->getStatusCode() === 200) {
        $id = (int)$request->user()?->id;
        $data = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR)['data'] ?? null;
        if ($voice) {
            fixtureNeed($id === $operator && is_array($data) && array_column($data, 'id') === array_slice(fixtureOfferIds($base), 0, 2)
                && array_column($data, 'status') === ['waiting', 'expired']
                && array_column($data, 'can_claim') === [true, false]
                && array_column($data, 'can_send_robbie') === [false, false]
                && array_column($data, 'phone_available') === [false, false], 'real-voice-offer-visibility');
        } else {
            fixtureNeed(in_array($id, $clientIds, true) && is_array($data) && isset($data['read_state'])
                && $data['user_role'] === 'client' && $data['read_state']['lastSeenAt'] === 1793491200000 + $id
                && $data['read_state']['readIds'] === ['synthetic-owner-'.$id => 1793491200000 + $id]
                && is_array($data['activity_log']) && isset($data['unread_counts']['total']), 'real-response-or-owner-check');
        }
    } elseif ($voice && $response->getStatusCode() === 403) {
        $denial = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);
        fixtureNeed((int)$request->user()?->id === $clientIds[0] && ($denial['resource'] ?? null) === 'voice-calls'
            && ($denial['action'] ?? null) === 'view', 'real-voice-permission-denial');
    } else {
        fixtureNeed($response->getStatusCode() === 401, 'unexpected-route-failure');
    }
    foreach (['run-token', 'request-nonce', 'profile-sha256', 'schedule-sha256', 'sequence', 'round'] as $name) {
        $value = $request->header('X-Repro-'.$name);
        fixtureNeed(is_string($value) && preg_match('/\A[a-z0-9-]{1,128}\z/', $value) === 1, 'request-metric-binding');
        $response->headers->set('X-Repro-'.$name, $value);
    }
    $response->headers->set('X-Repro-Engine', $config['engine']);
    $response->headers->set('X-Repro-Database-Identity', $config['engine'] === 'mysql' ? $config['uuid'] : $config['sqlite_source_sha256']);
    $response->headers->set('X-Repro-Server-Kind', 'reviewed-http-instrumentation');
    $response->headers->set('X-Repro-Query-Count', (string)$queries);
    $response->headers->set('X-Repro-Database-Warning-Count', (string)$warnings);
    $response->send();
} catch (Throwable $error) {
    $safe = ['result' => 'refused', 'phase' => $phase, 'class' => $error::class,
        'source' => basename($error->getFile()), 'line' => $error->getLine(), 'code' => (string)$error->getCode()];
    if (get_class($error) === RuntimeException::class && preg_match('/\A[a-z-]{1,80}\z/', $error->getMessage())) $safe['reason'] = $error->getMessage();
    if ($error instanceof Symfony\Component\Process\Exception\ProcessFailedException) {
        $safe['client_exit'] = $error->getProcess()->getExitCode();
        preg_match_all('/ERROR ([0-9]{4})(?: \(([A-Z0-9]+)\))?(?: at line ([0-9]+))?/', $error->getProcess()->getErrorOutput(), $matches, PREG_SET_ORDER);
        $safe['client_errors'] = array_map(static fn ($match) => array_slice($match, 1), $matches);
    }
    if (PHP_SAPI !== 'cli') { http_response_code(500); header('Content-Type: application/json'); }
    echo json_encode($safe);
    if (PHP_SAPI === 'cli') exit(1);
}
