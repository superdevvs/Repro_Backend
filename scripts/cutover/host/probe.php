<?php

declare(strict_types=1);

namespace Repro\CutoverRehearsal;

use App\Models\User;
use App\Services\NotificationReadStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

require '/workspace/vendor/autoload.php';

final class WriteEvidence implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        DB::table('fixture_effects')->insert(['receipt' => 'synthetic-queue-effect']);
    }
}

function requireFixture(bool $valid): void
{
    if (! $valid) {
        throw new RuntimeException('invalid fixture configuration');
    }
}

function held(bool $web): array
{
    if ($web) {
        http_response_code(503);
    }

    return ['admission_held' => true];
}

function runProbe(): array
{
    $engine = (string) getenv('REPRO_DRESS_ENGINE');
    $gate = json_decode(file_get_contents('/private/authority/gate.json'), true, flags: JSON_THROW_ON_ERROR);
    requireFixture(posix_geteuid() === 1000 && in_array($engine, ['sqlite', 'mysql'], true)
        && $gate['engine'] === $engine);
    $web = in_array(PHP_SAPI, ['cli-server', 'fpm-fcgi'], true);
    if ($web) {
        $operation = match ([$_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)]) {
            ['GET', '/fixture/notification-state'] => 'read',
            ['POST', '/fixture/notification-state'] => 'write',
            ['GET', '/api/public/crm/quotes/'.str_repeat('A', 48)] => 'quote-view',
            ['POST', '/api/webhooks/resend'] => 'webhook',
            ['POST', '/fixture/slow-write'] => 'slow-write',
            ['POST', '/cubicasa_webhook.php'], ['POST', '/iguide_webhook.php'] => 'direct-webhook',
            default => null,
        };
        $hosts = $operation === 'direct-webhook'
            ? ['legacy-api.fixture.test']
            : ['dashboard.fixture.test', 'legacy-api.fixture.test'];
        if ($operation === null || (in_array($operation, ['quote-view', 'webhook', 'slow-write', 'direct-webhook'], true)
            && (PHP_SAPI !== 'fpm-fcgi' || ! in_array($_SERVER['SERVER_NAME'], $hosts, true)))) {
            http_response_code(404);

            return ['missing' => true];
        }
        if ($operation === 'direct-webhook') {
            // Like public/cubicasa_webhook.php and public/iguide_webhook.php:
            // the provider is told 200 before Laravel boots. A PHP-level hold
            // therefore acknowledges and drops the event; only nginx can hold it.
            http_response_code(200);
        }
    } else {
        $operation = $GLOBALS['argv'][1] ?? '';
        requireFixture(in_array($operation, ['read', 'write', 'enqueue', 'work'], true));
    }

    if ($operation === 'read') {
        return exerciseLaravel($engine, $operation);
    }
    $acknowledged = $operation === 'direct-webhook' ? ['acknowledged' => true] : [];
    $holdStatus = $web && $acknowledged === [];
    if ($gate['open'] !== true) {
        return held($holdStatus) + $acknowledged;
    }
    $authority = getenv('REPRO_DRESS_WRITER_AUTHORITY');
    if ($authority === 'manufactured-postwrite') {
        return exerciseLaravel($engine, $operation);
    }
    if ($authority !== 'journal') {
        return held($holdStatus) + $acknowledged;
    }

    // This synthetic process owns its private journal. Retain the same shared
    // lock through bootstrap and execution so the runner cannot race admission.
    $lease = @fopen('/private/authority/operator.lock', 'rb');
    if ($lease === false) {
        return held($holdStatus) + $acknowledged;
    }
    try {
        if (! flock($lease, LOCK_SH | LOCK_NB)) {
            return held($holdStatus) + $acknowledged;
        }
        $check = @proc_open(
            ['/usr/bin/python3', '-I', '-S', '-B', '/host/verify-writer.py', $web ? 'web-writer' : 'queue-worker', $engine],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        if ($check === false || proc_close($check) !== 0) {
            return held($holdStatus) + $acknowledged;
        }

        return exerciseLaravel($engine, $operation);
    } finally {
        fclose($lease);
    }
}

function exerciseLaravel(string $engine, string $operation): array
{
    $application = require '/workspace/bootstrap/app.php';
    $application->useStoragePath('/private/storage');
    $application->make(Kernel::class)->bootstrap();
    $database = DB::connection();
    $sqlite = (string) getenv('REPRO_DRESS_SQLITE_PATH');
    requireFixture($application->configurationIsCached()
        && $application->getCachedConfigPath() === '/private/active-config.php'
        && config('app.env') === 'testing' && config('queue.default') === 'database'
        && config('mail.default') === 'array' && config('cache.default') === 'array'
        && config('session.driver') === 'array' && $database->getDriverName() === $engine
        && ($engine === 'sqlite'
            ? in_array($sqlite, ['/private/source.sqlite', '/private/failback/database.sqlite'], true)
                && $database->getDatabaseName() === $sqlite
            : $database->getDatabaseName() === 'repro_dress_fixture'
                && $database->getConfig('unix_socket') === '/fixture-socket/mysql.sock'));

    if ($operation === 'enqueue') {
        Bus::dispatch((new WriteEvidence)->onQueue('cutover-synthetic'));

        return ['queued' => $database->table('jobs')->where('queue', 'cutover-synthetic')->count()];
    }

    if ($operation === 'work') {
        $exit = Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'cutover-synthetic', '--once' => true,
            '--force' => true, '--sleep' => 0, '--tries' => 1,
        ]);

        return ['worker_exit' => $exit, 'effects' => $database->table('fixture_effects')->pluck('receipt')->all()];
    }

    if ($operation === 'slow-write') {
        // An admitted writer still in flight when the hold lands: it keeps the
        // shared lease across this pause, so the runner's drain must wait.
        touch('/private/slow-write-inflight');
        usleep(2_500_000);
        $database->table('fixture_effects')->insertOrIgnore(['receipt' => 'synthetic-slow-write']);

        return ['receipt' => 'synthetic-slow-write', 'host' => $_SERVER['SERVER_NAME']];
    }

    if ($operation === 'direct-webhook') {
        $receipt = str_starts_with($_SERVER['REQUEST_URI'], '/cubicasa') ? 'synthetic-cubicasa-direct' : 'synthetic-iguide-direct';
        $database->table('fixture_effects')->insertOrIgnore(['receipt' => $receipt]);

        return ['receipt' => $receipt, 'host' => $_SERVER['SERVER_NAME']];
    }

    if (in_array($operation, ['quote-view', 'webhook'], true)) {
        if ($operation === 'webhook') {
            requireFixture(file_get_contents('php://input') === '{"id":"synthetic-resend-event"}');
        }
        $receipt = $operation === 'quote-view' ? 'synthetic-quote-view' : 'synthetic-resend-event';
        $database->table('fixture_effects')->insertOrIgnore(['receipt' => $receipt]);

        return ['receipt' => $receipt, 'host' => $_SERVER['SERVER_NAME']];
    }

    $actor = new User;
    $actor->forceFill(['id' => 41, 'role' => 'client']);
    $request = Request::create('/fixture/notification-state');
    $request->setUserResolver(static fn () => $actor);
    $service = $application->make(NotificationReadStateService::class);
    $receipt = match (true) {
        $engine === 'mysql' => 'synthetic-mysql-receipt',
        $sqlite === '/private/failback/database.sqlite' => 'synthetic-sqlite-reopened',
        default => 'synthetic-http-receipt',
    };
    $state = $operation === 'read'
        ? $service->get($request)
        : $service->merge($request, [$receipt], null);

    return ['engine' => $engine, 'receipts' => (array) $state['readIds'],
        'pending' => $database->table('jobs')->count(), 'key_fingerprint' => hash('sha256', config('app.key'))];
}

try {
    echo json_encode(runProbe(), JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if (in_array(PHP_SAPI, ['cli-server', 'fpm-fcgi'], true)) {
        http_response_code(500);
    }
    $code = $error->getCode();
    echo json_encode(['refused' => get_class($error), 'code' => is_int($code) ? $code : (string) $code]).PHP_EOL;
    exit(1);
}
