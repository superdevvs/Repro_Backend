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
        // This middleware runs in the api middleware group which executes
        // BEFORE route-level auth:sanctum middleware, so $request->user()
        // would return null at this point. By querying the sanctum guard
        // explicitly we can resolve the user from the Bearer token.
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
            $impersonatedUser = User::find($impersonateUserId);

            if ($impersonatedUser) {
                // Store original admin for audit purposes
                $request->attributes->set('original_admin_user', $authUser);
                $request->attributes->set('is_impersonating', true);

                // Swap the authenticated user on both the sanctum guard
                // (used by auth:sanctum routes) and the default guard,
                // so all downstream code sees the impersonated user.
                auth('sanctum')->setUser($impersonatedUser);
                auth()->setUser($impersonatedUser);

                // Update the request's user resolver so $request->user()
                // returns the impersonated user even before Authenticate
                // middleware runs.
                $request->setUserResolver(function ($guard = null) use ($impersonatedUser) {
                    return $impersonatedUser;
                });

                \Log::debug('Impersonation active', [
                    'admin_id' => $authUser->id,
                    'admin_name' => $authUser->name,
                    'impersonated_id' => $impersonatedUser->id,
                    'impersonated_name' => $impersonatedUser->name,
                    'impersonated_role' => $impersonatedUser->role,
                ]);
            }
        }

        return $next($request);
    }
}
