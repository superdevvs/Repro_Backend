<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\ServerMonitor\Access;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ServerMonitorController extends Controller
{
    public function session(Request $request, Access $access)
    {
        abort_if($request->attributes->get('is_impersonating') || $request->hasHeader('X-Impersonate-User-Id'), 403);
        abort_unless($access->eligible($request->user()), 403);
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403, 'Sign in with a dashboard access token.');
        return response()->json(['version' => 1, 'token' => $access->issue($request->user(), $token),
            'expiresAt' => now()->addSeconds(60)->toIso8601ZuluString(), 'gatewayPath' => config('server-monitor.gateway_path')])->header('Cache-Control', 'no-store');
    }

    public function validateSession(Request $request, Access $access)
    {
        abort_if($request->hasHeader('X-Impersonate-User-Id'), 403);
        return response()->json($access->validate($request->bearerToken() ?? ''))->header('Cache-Control', 'no-store');
    }
}
