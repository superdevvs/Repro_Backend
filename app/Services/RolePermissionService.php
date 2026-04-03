<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

class RolePermissionService
{
    private const SETTINGS_KEY = 'permissions.role_map.v1';

    public function adminPayload(): array
    {
        return [
            'roles' => $this->roles(),
            'catalog' => $this->catalog(),
            'permissions' => $this->storedPermissions(),
            'defaults' => $this->defaultPermissionsByRole(),
        ];
    }

    public function effectivePayloadForUser(User $user): array
    {
        $permissionIds = $this->effectivePermissionIdsForUser($user);

        return [
            'role' => $this->normalizeRole($user->role),
            'secondaryRoles' => $this->normalizedUserRoles($user)->slice(1)->values()->all(),
            'permissionIds' => $permissionIds,
            'permissions' => array_values(array_filter(array_map(
                fn (string $permissionId) => $this->permissionRuleForId($permissionId),
                $permissionIds,
            ))),
        ];
    }

    public function userCan(User $user, string $resource, string $action): bool
    {
        return in_array(
            $this->permissionId($resource, $action),
            $this->effectivePermissionIdsForUser($user),
            true,
        );
    }

    public function updatePermissions(array $rolePermissions): array
    {
        $catalogIds = $this->catalogPermissionIds();
        $roles = $this->roleIds();
        $normalized = [];

        foreach ($roles as $roleId) {
            if ($roleId === 'superadmin') {
                $normalized[$roleId] = $catalogIds;
                continue;
            }

            $incomingIds = $rolePermissions[$roleId] ?? [];
            $normalized[$roleId] = $this->normalizePermissionIds($incomingIds, $catalogIds);
        }

        $this->savePermissions($normalized);

        return $normalized;
    }

    public function defaultPermissionsByRole(): array
    {
        $defaults = array_fill_keys($this->roleIds(), []);

        foreach ($this->catalog() as $group) {
            foreach ($group['permissions'] as $permission) {
                foreach ($permission['defaultRoles'] as $roleId) {
                    if (!isset($defaults[$roleId])) {
                        continue;
                    }

                    $defaults[$roleId][] = $permission['id'];
                }
            }
        }

        $catalogIds = $this->catalogPermissionIds();

        foreach ($defaults as $roleId => $permissionIds) {
            $defaults[$roleId] = $roleId === 'superadmin'
                ? $catalogIds
                : $this->normalizePermissionIds($permissionIds, $catalogIds);
        }

        return $defaults;
    }

    public function roleIds(): array
    {
        return array_values(array_map(
            fn (array $role) => $role['id'],
            $this->roles(),
        ));
    }

    public function roles(): array
    {
        return config('permissions.roles', []);
    }

    public function catalog(): array
    {
        return array_values(array_map(function (array $group) {
            return [
                'id' => $group['id'],
                'label' => $group['label'],
                'description' => $group['description'],
                'permissions' => array_values(array_map(function (array $item) {
                    return [
                        'id' => $this->permissionId($item['resource'], $item['action']),
                        'resource' => $item['resource'],
                        'action' => $item['action'],
                        'label' => $item['label'],
                        'description' => $item['description'],
                        'defaultRoles' => array_values(array_map(
                            fn (string $role) => $this->normalizeRole($role),
                            $item['default_roles'] ?? [],
                        )),
                    ];
                }, $group['items'] ?? [])),
            ];
        }, config('permissions.groups', [])));
    }

    public function validateUpdatePayload(array $payload): array
    {
        $roleIds = $this->roleIds();
        $catalogIds = $this->catalogPermissionIds();
        $normalized = [];

        foreach ($payload as $roleId => $permissionIds) {
            $normalizedRoleId = $this->normalizeRole((string) $roleId);
            if (!in_array($normalizedRoleId, $roleIds, true)) {
                throw new \InvalidArgumentException("Unknown role [{$roleId}]");
            }

            if (!is_array($permissionIds)) {
                throw new \InvalidArgumentException("Permissions for [{$roleId}] must be an array");
            }

            foreach ($permissionIds as $permissionId) {
                if (!is_string($permissionId) || !in_array($permissionId, $catalogIds, true)) {
                    throw new \InvalidArgumentException("Unknown permission id [{$permissionId}]");
                }
            }

            $normalized[$normalizedRoleId] = $permissionIds;
        }

        return $normalized;
    }

    public function normalizedUserRoles(User $user)
    {
        $roles = collect([$user->role])
            ->merge(is_array($user->secondary_roles) ? $user->secondary_roles : [])
            ->map(fn ($role) => $this->normalizeRole(is_string($role) ? $role : null))
            ->filter()
            ->unique()
            ->values();

        if ($roles->isEmpty()) {
            $roles->push('client');
        }

        return $roles;
    }

    private function storedPermissions(): array
    {
        $defaults = $this->defaultPermissionsByRole();
        $setting = Setting::query()->where('key', self::SETTINGS_KEY)->first();

        if (!$setting) {
            $this->savePermissions($defaults);
            return $defaults;
        }

        $decoded = json_decode((string) $setting->value, true);
        if (!is_array($decoded)) {
            $this->savePermissions($defaults);
            return $defaults;
        }

        $storedRoles = is_array($decoded['roles'] ?? null) ? $decoded['roles'] : $decoded;
        $catalogIds = $this->catalogPermissionIds();
        $normalized = [];

        foreach ($this->roleIds() as $roleId) {
            if ($roleId === 'superadmin') {
                $normalized[$roleId] = $catalogIds;
                continue;
            }

            $normalized[$roleId] = $this->normalizePermissionIds(
                is_array($storedRoles[$roleId] ?? null) ? $storedRoles[$roleId] : ($defaults[$roleId] ?? []),
                $catalogIds,
            );
        }

        $normalized = $this->applyPermissionMigrations($normalized, $catalogIds);

        if (($decoded['roles'] ?? null) !== $normalized) {
            $this->savePermissions($normalized);
        }

        return $normalized;
    }

    private function savePermissions(array $permissions): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            [
                'value' => json_encode([
                    'version' => 1,
                    'roles' => $permissions,
                ], JSON_PRETTY_PRINT),
                'type' => 'json',
                'description' => 'Role permissions map for dashboard and route access.',
            ],
        );
    }

    private function applyPermissionMigrations(array $permissions, array $catalogIds): array
    {
        // Preserve the historic expectation that sales reps can access Robbie
        // even if the stored permissions map was created before that default existed.
        if (in_array('robbie-view', $catalogIds, true)) {
            $existing = $permissions['salesRep'] ?? [];
            if (!in_array('robbie-view', $existing, true)) {
                $existing[] = 'robbie-view';
                $permissions['salesRep'] = $this->normalizePermissionIds($existing, $catalogIds);
            }
        }

        return $permissions;
    }

    private function catalogPermissionIds(): array
    {
        $ids = [];
        foreach ($this->catalog() as $group) {
            foreach ($group['permissions'] as $permission) {
                $ids[] = $permission['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function effectivePermissionIdsForUser(User $user): array
    {
        $roles = $this->normalizedUserRoles($user);
        $catalogIds = $this->catalogPermissionIds();

        if ($roles->contains('superadmin')) {
            return $catalogIds;
        }

        $stored = $this->storedPermissions();
        $collected = [];

        foreach ($roles as $roleId) {
            $collected = array_merge($collected, $stored[$roleId] ?? []);
        }

        return $this->normalizePermissionIds($collected, $catalogIds);
    }

    private function permissionRuleForId(string $permissionId): ?array
    {
        foreach ($this->catalog() as $group) {
            foreach ($group['permissions'] as $permission) {
                if ($permission['id'] !== $permissionId) {
                    continue;
                }

                return [
                    'id' => $permission['id'],
                    'resource' => $permission['resource'],
                    'action' => $permission['action'],
                ];
            }
        }

        return null;
    }

    private function normalizePermissionIds(array $permissionIds, array $allowedIds): array
    {
        return collect($permissionIds)
            ->filter(fn ($permissionId) => is_string($permissionId) && in_array($permissionId, $allowedIds, true))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function permissionId(string $resource, string $action): string
    {
        return "{$resource}-{$action}";
    }

    private function normalizeRole(?string $role): string
    {
        if ($role === null || trim($role) === '') {
            return 'client';
        }

        $normalized = strtolower(str_replace(['_', '-'], '', trim($role)));

        return match ($normalized) {
            'salesrep' => 'salesRep',
            'editingmanager' => 'editing_manager',
            'superadmin' => 'superadmin',
            default => $normalized,
        };
    }
}
