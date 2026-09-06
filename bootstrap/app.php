<?php

use App\Http\Middleware\EnsureAuthenticatedUserIsActive;
use App\Http\Middleware\ImpersonationMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SystemOverviewTelemetryMiddleware;
use App\Http\Middleware\TelnyxToolBridgeAuth;
use App\Http\Middleware\ValidateExternalApiKey;
use App\Jobs\DispatchScheduledMessages;
use App\Services\SystemOverviewTelemetryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        $schedule->job(new DispatchScheduledMessages)->everyMinute();
        $schedule->command('messaging:shoot-reminders')->everyFiveMinutes();
        $schedule->command('messaging:property-contact-reminders')->dailyAt('09:00');
        $schedule->command('messaging:invoice-reminders')->dailyAt('09:30');
        $schedule->command('messaging:invoice-summaries')->weeklyOn(1, '03:00');
        $schedule->command('payouts:send')->weeklyOn(0, '05:00');
        $schedule->command('cubicasa:resync-pending')->everyThirtyMinutes()->withoutOverlapping();
        // Reconciliation safety net for an iGuide the photographer produces
        // hours or days after the booking, when no webhook reached us. This was
        // only ever registered in app/Console/Kernel.php, which withSchedule()
        // makes inert, so it never actually ran.
        //
        // Offset to :15/:45 rather than sharing CubiCasa's :00/:30 slot. Both
        // resync commands write shoot rows, the database is SQLite, and
        // cubicasa:resync-pending is already failing on "database is locked".
        // Running a second writer in the same minute would only add to that.
        $schedule->command('iguide:resync-pending')->cron('15,45 * * * *')->withoutOverlapping();
        $schedule->command('iguide-uploads:prune')
            ->cron('7,37 * * * *')
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('system-overview:prune')->hourly();
        $schedule->command('auth:prune-security-limits --limit=1000')->hourly()->withoutOverlapping();
        $schedule->command('telnyx:prune-webhook-events')->dailyAt('02:30');
        $schedule->command('messages:retry-stuck --minutes=5 --max-attempts=3 --limit=100')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('voice:dispatch-scheduled-calls')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('messaging:audit-transactional-email --hours=168 --limit=50')
            ->dailyAt('08:00')
            ->onOneServer();
        $schedule->command('system-emails:recover --limit=100')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('shoot-uploads:audit-pending --minutes=5')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();
        // Completed/failed upload replays expire after 30 days; pending attempts are never pruned.
        $schedule->command('model:prune')->dailyAt('03:45')->withoutOverlapping()->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(\App\Http\Middleware\ApiRequestContextMiddleware::class);
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustConfiguredProxies::class);
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_TRAEFIK,
        );
        $middleware->validateCsrfTokens(except: [
            'iguide_webhook.php',
            'cubicasa_webhook.php',
        ]);

        // Append impersonation middleware to run after auth
        $middleware->api(append: [
            ImpersonationMiddleware::class,
            EnsureAuthenticatedUserIsActive::class,
            \App\Http\Middleware\EnforceEmailVerificationPilot::class,
            SystemOverviewTelemetryMiddleware::class,
        ]);

        // Ensure bearer authentication and impersonation resolve before account gates.
        $middleware->appendToPriorityList(\Illuminate\Auth\Middleware\Authenticate::class, ImpersonationMiddleware::class);
        $middleware->appendToPriorityList(ImpersonationMiddleware::class, EnsureAuthenticatedUserIsActive::class);
        $middleware->appendToPriorityList(EnsureAuthenticatedUserIsActive::class, \App\Http\Middleware\EnforceEmailVerificationPilot::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'impersonate' => ImpersonationMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'external_api_key' => ValidateExternalApiKey::class,
            'telnyx.toolbridge' => TelnyxToolBridgeAuth::class,
        ]);
    })
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withExceptions(function (Exceptions $exceptions) {
        // API diagnostics are deliberately redacted by the response/telemetry services.
        $exceptions->report(function (\Throwable $exception) {
            if (request()->is('api/*')) {
                return false;
            }
        });

        // Add CORS headers to all error responses
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // Only handle API routes
            if ($request->is('api/*')) {
                $allowedOrigins = config('cors.allowed_origins', []);
                $originHeader = $request->headers->get('Origin');
                $origin = '*';

                if ($originHeader && in_array($originHeader, $allowedOrigins, true)) {
                    $origin = $originHeader;
                } elseif (! empty($allowedOrigins)) {
                    $origin = $allowedOrigins[0];
                } elseif (config('app.frontend_url')) {
                    $origin = config('app.frontend_url');
                }

                $responder = app(\App\Services\ApiErrorResponder::class);
                $status = $responder->status($e);
                if ($request->user()) {
                    try {
                        app(SystemOverviewTelemetryService::class)->recordException($request, $e, $status);
                    } catch (\Throwable $telemetryError) {
                        // Telemetry must not replace the actual error response.
                    }
                }

                return $responder->render($e, $request)
                    ->header('Access-Control-Allow-Origin', $origin)
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-Impersonate-User-Id, X-Trace-Id, X-System-Session-Id, X-System-Current-Route, Idempotency-Key, Content-Range, X-Chunk-SHA256')
                    ->header('Access-Control-Allow-Credentials', 'true');
            }
        });
    })->create();
