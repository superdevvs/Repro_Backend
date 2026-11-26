<?php

/*
 * FastCGI engine probe (MySQL cutover design v2, 5.3).
 *
 * Root's FastCGI client (scripts/cutover/production/fastcgi.py on the lane)
 * sends this file straight to the PHP-FPM socket with
 * SCRIPT_FILENAME=/var/www/backend/scripts/cutover/probe/fpm-engine.php. It is
 * not under public/, so nginx never routes to it. It runs as the pool user and
 * proves FPM's effective config while the hold keeps every public route shut.
 *
 * It bootstraps the app (environment, cached config, providers) but handles no
 * request: no middleware, session, telemetry or terminate callback. It refuses
 * to start when a bootstrap manifest is missing, because booting would write
 * it, and refuses root. Logging goes to a null handler. The one database read,
 * `SELECT @@server_uuid, DATABASE()` or SQLite's database list, runs in a
 * session the database keeps from writing (CutoverEngineProbe::readOnly).
 *
 * It prints one JSON object: the engine, the database, the server identity,
 * the raw route values of the live config (the same as
 * `php artisan cutover:smoke --engine-only --json`; Python hashes them, see
 * route-hash-vectors.json next to this file), the OPcache state and any
 * problems. HTTP status 200 when ok, 500 otherwise; exit code 0 or 1 on the CLI.
 */

declare(strict_types=1);

use App\Support\Cutover\CutoverEngineProbe;
use App\Support\Cutover\CutoverRouteValues;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Monolog\Handler\NullHandler;

$started = hrtime(true);
$report = ['version' => 1, 'probe' => 'fpm-engine', 'ok' => false, 'sapi' => PHP_SAPI, 'pid' => getmypid()];

$emit = static function (array $report, array $problems) use ($started): never {
    $report['elapsed_ms'] = (int) round((hrtime(true) - $started) / 1e6);
    $report['problems'] = array_values($problems);
    $report['ok'] = $problems === [];
    if (PHP_SAPI !== 'cli') {
        http_response_code($report['ok'] ? 200 : 500);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
    }
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
    exit($report['ok'] ? 0 : 1);
};

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $emit($report, ['refused: the probe never runs application code as root; send it to the FPM socket instead']);
}

$base = dirname(__DIR__, 3);
try {
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
} catch (Throwable $error) {
    $emit($report, ['load: '.substr($error->getMessage(), 0, 300)]);
}

$missing = [];
foreach (['packages' => $app->getCachedPackagesPath(), 'services' => $app->getCachedServicesPath()] as $name => $path) {
    if (! is_file($path)) {
        $missing[] = "refused: the {$name} manifest is missing at {$path}; booting would write it";
    }
}
if ($missing !== []) {
    $emit($report, $missing);
}

// Nothing the probe does may write a log: every channel goes to a null handler before any provider boots.
$app->afterBootstrapping(LoadConfiguration::class, static function ($app): void {
    $app['config']->set('logging.channels.cutover-probe-null', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    $app['config']->set('logging.default', 'cutover-probe-null');
    $app['config']->set('logging.deprecations.channel', 'cutover-probe-null');
});

try {
    $app->make(Kernel::class)->bootstrap();
    $measured = CutoverEngineProbe::measure($app);
} catch (Throwable $error) {
    $emit($report, ['boot: '.CutoverEngineProbe::message($error)]);
}

$problems = $measured['problems'];
unset($measured['problems']);
$measured['route_values'] = CutoverRouteValues::forJson($measured['route_values']);

$opcache = ['enabled' => false, 'cache_full' => null, 'restart_pending' => null, 'num_cached_scripts' => null, 'start_time' => null,
    'last_restart_time' => null, 'validate_timestamps' => filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL)];
$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
if (is_array($status)) {
    $opcache = ['enabled' => (bool) ($status['opcache_enabled'] ?? false), 'cache_full' => (bool) ($status['cache_full'] ?? false),
        'restart_pending' => (bool) ($status['restart_pending'] ?? false),
        'num_cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? null,
        'start_time' => $status['opcache_statistics']['start_time'] ?? null,
        'last_restart_time' => $status['opcache_statistics']['last_restart_time'] ?? null] + $opcache;
}

$emit($report + $measured + ['opcache' => $opcache], $problems);
