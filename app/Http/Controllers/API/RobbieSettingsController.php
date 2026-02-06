<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\RobbieConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class RobbieSettingsController extends Controller
{
    public function index(Request $request, RobbieConfigService $configService)
    {
        $roles = $configService->getKnownRoles();
        $roleConfigs = $configService->getRoleConfigs();
        $mergedConfigs = [];

        foreach ($roles as $role) {
            $mergedConfigs[$role] = $configService->getMergedConfigForRole($role);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'defaults' => $configService->getDefaultConfig(),
                'global' => $configService->getGlobalConfig(),
                'role_configs' => $roleConfigs,
                'merged_configs' => $mergedConfigs,
                'roles' => $roles,
                'keys' => [
                    'global' => RobbieConfigService::GLOBAL_KEY,
                    'roles' => $configService->getRoleKeys(),
                ],
            ],
        ]);
    }

    public function store(Request $request, RobbieConfigService $configService)
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', Rule::in(['global', 'role'])],
            'role' => ['nullable', 'string'],
            'config' => ['required', 'array'],
            'description' => ['nullable', 'string'],
        ]);

        $scope = $validated['scope'];
        $role = $validated['role'] ?? null;

        if ($scope === 'role') {
            $role = $role ? $configService->resolveRole($role) : null;
            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid role provided for Robbie settings.',
                ], 422);
            }
        }

        $before = $scope === 'global'
            ? $configService->getGlobalConfig()
            : $configService->getRoleConfig($role);

        if ($scope === 'global') {
            $configService->saveGlobalConfig($validated['config'], $validated['description'] ?? null);
        } else {
            $configService->saveRoleConfig($role, $validated['config'], $validated['description'] ?? null);
        }

        Log::info('Robbie settings updated', [
            'user_id' => $request->user()?->id,
            'scope' => $scope,
            'role' => $role,
            'before' => $before,
            'after' => $validated['config'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Robbie settings saved successfully.',
            'data' => [
                'scope' => $scope,
                'role' => $role,
                'config' => $validated['config'],
            ],
        ]);
    }
}
