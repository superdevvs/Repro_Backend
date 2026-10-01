<?php

namespace App\Services\Messaging;

use App\Models\User;
use App\Services\RolePermissionService;

class DashboardMessagingPolicy
{
    public static function staffRole(?string $role): bool
    {
        return in_array(strtolower(str_replace(['_', '-', ' '], '', trim((string) $role))), ['admin', 'superadmin', 'editingmanager'], true);
    }

    public function canEmail(User $user, bool $compose = false): bool
    {
        return $user->isAccountEligibleForAuthentication()
            && self::staffRole($user->role)
            && app(RolePermissionService::class)->userCan($user, 'messaging-email', 'view')
            && (! $compose || app(RolePermissionService::class)->userCan($user, 'messaging-compose', 'create'));
    }

    public function authorizeEmail(User $user, bool $compose = false): void
    {
        abort_unless($this->canEmail($user, $compose), 403, 'Use Messaging Support for dashboard assistance. Email tools are restricted to authorized administrators and editing managers.');
    }
}
