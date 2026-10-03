<?php

namespace App\Http\Middleware;

use App\Models\AryeoConnection;
use Closure;
use Illuminate\Http\Request;

class AuthenticateAryeoWorker
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32, 401);
        $connection = AryeoConnection::where('token_hash', hash('sha256', $token))->where('enabled', true)->first();
        abort_unless($connection, 401);
        $request->attributes->set('aryeo_connection', $connection);

        return $next($request);
    }
}
