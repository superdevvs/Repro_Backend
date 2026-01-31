<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Broadcasting;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\ImpersonationMiddleware;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Append impersonation middleware to run after auth
        $middleware->api(append: [
            ImpersonationMiddleware::class,
        ]);
        
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'impersonate' => ImpersonationMiddleware::class,
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
                $origin = $request->headers->get('Origin', '*');
                if (!in_array($origin, ['http://localhost:5173', 'http://localhost:5174', 'http://127.0.0.1:5173'])) {
                    $origin = '*';
                }
                
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                
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
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
                ->header('Access-Control-Allow-Credentials', 'true');
            }
        });
    })->create();
