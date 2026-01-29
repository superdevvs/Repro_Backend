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
        $authUser = $request->user();
        
        \Log::debug('ImpersonationMiddleware check', [
            'has_header' => !empty($impersonateUserId),
            'header_value' => $impersonateUserId,
            'has_auth_user' => !empty($authUser),
            'auth_user_id' => $authUser?->id,
            'auth_user_role' => $authUser?->role,
            'request_path' => $request->path(),
        ]);

        if ($impersonateUserId && $authUser) {
            $isAdmin = in_array($authUser->role, ['admin', 'superadmin']);

            if ($isAdmin) {
                $impersonatedUser = User::find($impersonateUserId);
                
                if ($impersonatedUser) {
                    // Store original admin for audit purposes
                    $request->attributes->set('original_admin_user', $authUser);
                    $request->attributes->set('is_impersonating', true);
                    
                    // Swap the authenticated user to the impersonated user
                    auth()->setUser($impersonatedUser);
                    
                    \Log::debug('Impersonation active', [
                        'admin_id' => $authUser->id,
                        'admin_name' => $authUser->name,
                        'impersonated_id' => $impersonatedUser->id,
                        'impersonated_name' => $impersonatedUser->name,
                        'impersonated_role' => $impersonatedUser->role,
                    ]);
                }
            }
        }

        return $next($request);
    }
}
