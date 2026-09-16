<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
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
                'message' => \App\Services\ApiErrorResponder::publicMessage($exception),
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

    public function users(Request $request)
    {
        if ($denied = $this->denyUnlessCan($request, 'view')) {
            return $denied;
        }

        return response()->json($this->permissions->usersOverviewPayload());
    }

    public function showUser(Request $request, User $user)
    {
        if ($denied = $this->denyUnlessCan($request, 'view')) {
            return $denied;
        }

        return response()->json($this->permissions->userOverridesPayload($user));
    }

    public function updateUser(Request $request, User $user)
    {
        if ($denied = $this->denyUnlessCan($request, 'update')) {
            return $denied;
        }

        $input = $request->input('overrides');
        if (!is_array($input)) {
            return response()->json([
                'message' => 'The overrides payload must contain allow and deny lists.',
            ], 422);
        }

        try {
            $validated = $this->permissions->validateOverridesPayload($input);
            $payload = $this->permissions->updateUserOverrides($user, $validated);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => \App\Services\ApiErrorResponder::publicMessage($exception),
            ], 422);
        }

        return response()->json([
            'message' => 'User permissions updated successfully.',
            ...$payload,
        ]);
    }

    private function denyUnlessCan(Request $request, string $action)
    {
        $viewer = $request->user();
        if (!$viewer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (
            !in_array($viewer->role, ['admin', 'superadmin'], true)
            || !$this->permissions->userCan($viewer, 'permissions-manager', $action)
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }
}
