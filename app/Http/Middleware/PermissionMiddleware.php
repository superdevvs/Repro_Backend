<?php

namespace App\Http\Middleware;

use App\Services\RolePermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $resource, string $action = 'view'): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Authentication required.'], 401);
        }

        if (!app(RolePermissionService::class)->userCan($user, $resource, $action)) {
            return response()->json([
                'message' => 'You do not have permission to access this resource.',
                'resource' => $resource,
                'action' => $action,
            ], 403);
        }

        return $next($request);
    }
}
