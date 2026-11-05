<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\RolePermissionService;

final class ClientBrandingAccess
{
    public function __construct(private RolePermissionService $permissions) {}

    public function canView(?User $actor, User $client): bool
    {
        if (! $actor || $client->role !== 'client') {
            return false;
        }

        if ($actor->id === $client->id || $this->hasRole($actor, 'superadmin')) {
            return true;
        }

        return $this->hasRole($actor, 'admin', 'salesrep', 'editing_manager')
            && $this->permissions->userCan($actor, 'clients', 'view');
    }

    public function canManage(?User $actor, User $client): bool
    {
        if (! $actor || $client->role !== 'client') {
            return false;
        }

        if ($actor->id === $client->id || $this->hasRole($actor, 'superadmin')) {
            return true;
        }

        if (! $this->canView($actor, $client) || ! $this->permissions->userCan($actor, 'clients', 'update')) {
            return false;
        }

        if ($this->hasRole($actor, 'admin')) {
            return true;
        }

        // Match the existing account-rep metadata precedence, including legacy creators.
        // A shoot assignment alone does not grant control over the client's branding.
        return $this->hasRole($actor, 'salesrep')
            && app(EmailHealthService::class)->extractSalesRepId($client) === (int) $actor->id;
    }

    private function hasRole(User $user, string ...$roles): bool
    {
        $userRoles = array_map(
            fn ($role) => strtolower(str_replace('_', '', (string) $role)),
            array_merge([$user->role], (array) $user->secondary_roles),
        );

        return count(array_intersect($userRoles, array_map(fn ($role) => str_replace('_', '', $role), $roles))) > 0;
    }
}
