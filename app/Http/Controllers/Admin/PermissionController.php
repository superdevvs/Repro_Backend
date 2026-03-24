<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RolePermissionService;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct(
        private readonly RolePermissionService $permissions,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!in_array($user->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->permissions->adminPayload());
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!in_array($user->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $input = $request->input('permissions');
        if (!is_array($input)) {
            return response()->json([
                'message' => 'The permissions payload must be an object keyed by role.',
            ], 422);
        }

        try {
            $validated = $this->permissions->validateUpdatePayload($input);
            $saved = $this->permissions->updatePermissions($validated);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Permissions updated successfully.',
            'permissions' => $saved,
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($this->permissions->effectivePayloadForUser($user));
    }
}
