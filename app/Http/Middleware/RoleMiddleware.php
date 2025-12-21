<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // Allow OPTIONS requests to pass through (CORS preflight)
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user) {
            $origin = $request->headers->get('Origin', '*');
            return response()->json([
                'message' => 'Unauthorized. Authentication required.',
            ], 401)
            ->header('Access-Control-Allow-Origin', $origin)
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->header('Access-Control-Allow-Credentials', 'true');
        }

        $normalize = static function (?string $role): string {
            if ($role === null) return '';
            return strtolower(str_replace(['_', '-'], '', $role));
        };

        $normalizedUserRole = $normalize($user->role);
        $normalizedAllowedRoles = array_map($normalize, $roles);

        if (!in_array($normalizedUserRole, $normalizedAllowedRoles, true)) {
            $origin = $request->headers->get('Origin', '*');
            return response()->json([
                'message' => 'Unauthorized. Access restricted to specific roles.',
                'your_role' => $user->role,
                'required_roles' => $roles,
            ], 403)
            ->header('Access-Control-Allow-Origin', $origin)
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->header('Access-Control-Allow-Credentials', 'true');
        }

        return $next($request);
    }
}
