<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\RolePermissionService;

class ImpersonationAccess
{
    public const REP_ID_KEYS = ['accountRepId', 'account_rep_id', 'repId', 'rep_id'];

    public const REP_METADATA_KEYS = [...self::REP_ID_KEYS, 'accountRep', 'account_rep', 'rep'];

    public function canImpersonate(User $actor, User $target): bool
    {
        if (in_array($actor->role, ['admin', 'superadmin'], true)) {
            return true;
        }

        $roles = app(RolePermissionService::class)->normalizedUserRoles($actor);
        if (!$roles->contains('salesRep') || $target->role !== 'client') {
            return false;
        }

        // A client record with staff roles must not grant a rep those privileges.
        if (app(RolePermissionService::class)->normalizedUserRoles($target)->diff(['client'])->isNotEmpty()) {
            return false;
        }

        $metadata = is_array($target->metadata) ? $target->metadata : [];
        foreach (self::REP_ID_KEYS as $key) {
            if (array_key_exists($key, $metadata)) {
                // An explicit assignment (including a clear) overrides the creator.
                return $this->matchesActor($metadata[$key], $actor);
            }
        }

        // Legacy creator assignments are valid only without explicit rep metadata.
        // Names and a past shoot's rep are not proof of account assignment.
        if (array_intersect(self::REP_METADATA_KEYS, array_keys($metadata)) !== []) {
            return false;
        }

        return $this->matchesActor($target->created_by_id, $actor);
    }

    private function matchesActor(mixed $id, User $actor): bool
    {
        return (is_int($id) || is_string($id))
            && ctype_digit((string) $id)
            && (int) $id > 0
            && (string) (int) $id === (string) $actor->id;
    }
}
