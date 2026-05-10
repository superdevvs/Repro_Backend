<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Broadcasting;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\ImpersonationMiddleware;
use App\Http\Middleware\SystemOverviewTelemetryMiddleware;
use App\Http\Middleware\ValidateExternalApiKey;
use App\Jobs\BackupToDropboxJob;
use App\Jobs\DispatchScheduledMessages;
use App\Services\SystemOverviewTelemetryService;
use Symfony\Component\HttpFoundation\Request;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Laravel 11 in this project boots schedules from the application builder.
        $schedule->command('automations:run-system')->everyFifteenMinutes();
        $schedule->job(new BackupToDropboxJob())->dailyAt('02:00');
        $schedule->job(new DispatchScheduledMessages())->everyMinute();
        $schedule->command('messaging:shoot-reminders')->everyFiveMinutes();
        $schedule->command('messaging:property-contact-reminders')->dailyAt('09:00');
        $schedule->command('messaging:invoice-reminders')->dailyAt('09:30');
        $schedule->command('messaging:invoice-summaries')->weeklyOn(1, '03:00');
        $schedule->command('payouts:send')->weeklyOn(0, '05:00');
        $schedule->command('system-overview:prune')->hourly();
        $schedule->command('messages:retry-stuck --minutes=5 --max-attempts=3 --limit=100')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('messaging:audit-transactional-email --hours=168 --limit=50')
            ->dailyAt('08:00')
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_TRAEFIK,
        );
        $middleware->validateCsrfTokens(except: [
            'iguide_webhook.php',
        ]);

        // Append impersonation middleware to run after auth
        $middleware->api(append: [
            ImpersonationMiddleware::class,
            SystemOverviewTelemetryMiddleware::class,
        ]);
        
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'impersonate' => ImpersonationMiddleware::class,
            'external_api_key' => ValidateExternalApiKey::class,
        ]);
    })
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withExceptions(function (Exceptions $exceptions) {
        // Add CORS headers to all error responses
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // Only handle API routes
            if ($request->is('api/*')) {
                $allowedOrigins = config('cors.allowed_origins', []);
                $originHeader = $request->headers->get('Origin');
                $origin = '*';

                if ($originHeader && in_array($originHeader, $allowedOrigins, true)) {
                    $origin = $originHeader;
                } elseif (!empty($allowedOrigins)) {
                    $origin = $allowedOrigins[0];
                } elseif (config('app.frontend_url')) {
                    $origin = config('app.frontend_url');
                }
                
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    $status = 403;
                } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                    $status = 422;
                } elseif ($e instanceof \Illuminate\Routing\Exceptions\InvalidSignatureException) {
                    $status = 403;
                } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $status = 404;
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $status = 401;
                }

                if ($request->user()) {
                    try {
                        app(SystemOverviewTelemetryService::class)->recordException($request, $e, $status);
                    } catch (\Throwable $telemetryError) {
                        // Never let telemetry capture break the error response.
                    }
                }
                
                return response()->json([
                    'message' => $e->getMessage() ?: 'An error occurred',
                    'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                    'debug' => config('app.debug') ? [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'class' => get_class($e),
                    ] : null,
                ], $status)
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-Impersonate-User-Id, X-Trace-Id, X-System-Session-Id, X-System-Current-Route')
                ->header('Access-Control-Allow-Credentials', 'true');
            }
        });
    })->create();
