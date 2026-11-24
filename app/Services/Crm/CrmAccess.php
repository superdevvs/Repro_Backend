<?php

namespace App\Services\Crm;

use App\Models\User;
use App\Services\RolePermissionService;
use Illuminate\Support\Facades\Auth;

/**
 * Answers "what may the signed-in user do in the CRM", once per request.
 *
 * Bound as a scoped singleton, so answers are cached for one request (or one
 * test) and never leak to the next.
 */
class CrmAccess
{
    /** Signed in, but without any CRM access: queries must match nothing. */
    public const NO_ACCESS = -1;

    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(private readonly RolePermissionService $permissions) {}

    public function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function can(?User $user, string $resource, string $action): bool
    {
        if (! $user) {
            return false;
        }

        $key = $user->id.'|'.$resource.'|'.$action;

        return $this->memo[$key] ??= $this->permissions->userCan($user, $resource, $action);
    }

    public function canViewAll(?User $user): bool
    {
        return $this->can($user, 'crm-contacts', 'view-all');
    }

    public function canAssign(?User $user): bool
    {
        return $this->can($user, 'crm-contacts', 'assign');
    }

    public function isSuperAdmin(?User $user): bool
    {
        return $user !== null && $this->permissions->normalizedUserRoles($user)->contains('superadmin');
    }

    /**
     * null: no restriction. NO_ACCESS: match nothing. Otherwise the rep's user id.
     */
    public function currentRestriction(): ?int
    {
        $user = $this->user();
        if (! $user) {
            return null;
        }

        if ($this->canViewAll($user)) {
            return null;
        }

        if (! $this->can($user, 'crm-contacts', 'view')) {
            return self::NO_ACCESS;
        }

        return (int) $user->id;
    }

    /** Clear cached answers, for example after permissions change mid-request. */
    public function forget(): void
    {
        $this->memo = [];
    }
}
