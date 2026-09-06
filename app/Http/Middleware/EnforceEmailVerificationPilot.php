<?php

namespace App\Http\Middleware;

use App\Services\Users\EmailVerificationPilot;
use Closure;
use Illuminate\Http\Request;

class EnforceEmailVerificationPilot
{
    public function handle(Request $request, Closure $next)
    {
        $requiresAuthentication = collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(fn ($middleware) => is_string($middleware) && (str_starts_with($middleware, 'auth:') || $middleware === 'auth'));
        if (!$requiresAuthentication) {
            return $next($request);
        }
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }
        $status = app(EmailVerificationPilot::class)->status($user);
        $allowed = $request->is('api/user', 'api/logout', 'api/profile/email-verification/*', 'api/profile/security', 'api/profile/security/*', 'api/password/*', 'api/email/verify/*');
        if ($status['required'] && !$allowed) {
            return response()->json([
                'message' => 'Verify your current email address to continue.',
                'code' => 'email_verification_required',
                'email_verification' => $status,
            ], 403);
        }
        return $next($request);
    }
}
