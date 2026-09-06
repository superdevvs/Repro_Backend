<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;

class ImpersonationMiddleware
{
    /**
     * Handle an incoming request.
     * 
     * When an admin sends the X-Impersonate-User-Id header, swap the authenticated
     * user to the impersonated user so all downstream code uses the client's context.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Allow OPTIONS requests to pass through (CORS preflight)
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $impersonateUserId = $request->header('X-Impersonate-User-Id');

        // Skip early if no impersonation header is present
        if (!$impersonateUserId) {
            return $next($request);
        }

        // Resolve the authenticated user directly via the sanctum guard.
        // The explicit middleware priority resolves bearer authentication first.
        // Resolve the original actor before any target account is substituted.
        $authUser = auth('sanctum')->user();

        \Log::debug('ImpersonationMiddleware check', [
            'has_header' => true,
            'header_value' => $impersonateUserId,
            'has_auth_user' => !empty($authUser),
            'auth_user_id' => $authUser?->id,
            'auth_user_role' => $authUser?->role,
            'request_path' => $request->path(),
        ]);

        if ($authUser && in_array($authUser->role, ['admin', 'superadmin'])) {
            $request->setUserResolver(fn ($guard = null) => $authUser);
            // Apply the same gates to the original actor before the swap. The
            // normal downstream middleware then checks the target account too.
            return app(EnsureAuthenticatedUserIsActive::class)->handle($request, function (Request $request) use ($authUser, $impersonateUserId, $next): Response {
                return app(EnforceEmailVerificationPilot::class)->handle($request, function (Request $request) use ($authUser, $impersonateUserId, $next): Response {
                    return $this->continueAsTarget($request, $authUser, $impersonateUserId, $next);
                });
            });
        }

        return $next($request);
    }

    private function continueAsTarget(Request $request, User $authUser, string $impersonateUserId, Closure $next): Response
    {
        $impersonatedUser = User::find($impersonateUserId);

        if ($impersonatedUser) {
            $request->attributes->set('original_admin_user', $authUser);
            $request->attributes->set('is_impersonating', true);

            // Both guards and the request resolver use the target downstream.
            auth('sanctum')->setUser($impersonatedUser);
            auth()->setUser($impersonatedUser);
            $request->setUserResolver(fn ($guard = null) => $impersonatedUser);

            \Log::debug('Impersonation active', [
                'admin_id' => $authUser->id,
                'admin_name' => $authUser->name,
                'impersonated_id' => $impersonatedUser->id,
                'impersonated_name' => $impersonatedUser->name,
                'impersonated_role' => $impersonatedUser->role,
            ]);
        }

        return $next($request);
    }
}
