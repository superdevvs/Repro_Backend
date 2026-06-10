<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticatedUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return $next($request);
        }

        if ($user->isAccountEligibleForAuthentication()) {
            return $next($request);
        }

        $user->currentAccessToken()?->delete();
        Auth::guard('web')->logout();

        return response()->json([
            'message' => 'This account is no longer active.',
        ], 401);
    }
}
