<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;

/** Resolve an explicitly assigned sales rep without guessing or broadcasting to all reps. */
class ShootSalesRepResolver
{
    public function resolve(Shoot $shoot): ?User
    {
        $shoot->loadMissing(['rep', 'client']);
        if ($this->isSalesRep($shoot->rep)) {
            return $shoot->rep;
        }

        $metadata = $shoot->client?->metadata;
        $metadata = is_array($metadata) ? $metadata : [];
        foreach (['accountRepId', 'account_rep_id', 'repId', 'rep_id'] as $key) {
            $id = $metadata[$key] ?? null;
            if (! is_numeric($id) || (int) $id <= 0) {
                continue;
            }
            $rep = User::find((int) $id);
            if ($this->isSalesRep($rep)) {
                return $rep;
            }
        }

        return null;
    }

    private function isSalesRep(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $roles = array_merge([$user->role], (array) $user->secondary_roles);

        return collect($roles)->contains(fn ($role) => in_array(strtolower((string) $role), [
            'salesrep', 'sales_rep', 'rep', 'representative',
        ], true));
    }
}
