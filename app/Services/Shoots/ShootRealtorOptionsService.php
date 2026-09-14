<?php

namespace App\Services\Shoots;

use App\Models\AccountLink;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may be shown as the realtor on a shoot's branded tour, and who may choose.
 *
 * Staff (admin, superadmin, editing manager) and sales reps can put any client's
 * branding on any shoot they can edit. A client can do it for their own shoot
 * too, but only from within their own circle: themselves, or an account linked
 * to them in either direction — the agents a team lead has linked, or the team
 * lead an agent belongs to. A client never sees or selects an unrelated client.
 */
class ShootRealtorOptionsService
{
    private const STAFF_ROLES = ['admin', 'superadmin', 'super_admin', 'editing_manager', 'salesrep', 'sales_rep'];

    public function canChooseRealtor(User $user): bool
    {
        return $this->isStaff($user) || $this->isClient($user);
    }

    /**
     * Whether $user may set this client id as the shoot's realtor. Null clears
     * the assignment and is always allowed for anyone who can choose at all.
     */
    public function canAssignRealtor(User $user, ?int $clientId): bool
    {
        if (! $this->canChooseRealtor($user)) {
            return false;
        }

        if ($clientId === null) {
            return true;
        }

        if ($this->isStaff($user)) {
            return User::query()->whereKey($clientId)->where('role', 'client')->exists();
        }

        return in_array($clientId, $this->permittedClientIdsForClient($user), true);
    }

    /**
     * The selectable realtors for $user on $shoot, ready for a picker.
     *
     * @return array<int, array{id:string,name:string,email:string,company:string}>
     */
    public function optionsFor(User $user, Shoot $shoot): array
    {
        if (! $this->canChooseRealtor($user)) {
            return [];
        }

        $query = User::query()->where('role', 'client');

        if (! $this->isStaff($user)) {
            $query->whereIn('id', $this->permittedClientIdsForClient($user));
        }

        $options = $query->orderBy('name')->get(['id', 'name', 'email', 'company_name']);

        // The currently assigned realtor is always listed, even when it is outside
        // the chooser's circle, so the picker can show what is set rather than an
        // empty field that looks like nothing was ever chosen.
        $current = $this->currentRealtor($shoot);
        if ($current && ! $options->contains(fn (User $option) => (int) $option->id === (int) $current->id)) {
            $options->prepend($current);
        }

        return $options
            ->map(fn (User $option) => [
                'id' => (string) $option->id,
                'name' => (string) ($option->name ?: 'Client'),
                'email' => (string) ($option->email ?? ''),
                'company' => (string) ($option->company_name ?? ''),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    private function permittedClientIdsForClient(User $user): array
    {
        return Collection::make(AccountLink::getLinkedAccountIds((int) $user->id))
            ->map(fn ($id) => (int) $id)
            ->push((int) $user->id)
            ->unique()
            ->values()
            ->all();
    }

    private function currentRealtor(Shoot $shoot): ?User
    {
        $tourLinks = is_array($shoot->tour_links) ? $shoot->tour_links : [];
        $id = $tourLinks['realtor_client_id'] ?? $tourLinks['realtorClientId'] ?? null;

        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }

        return User::query()->whereKey((int) $id)->where('role', 'client')->first(['id', 'name', 'email', 'company_name']);
    }

    private function isStaff(User $user): bool
    {
        return in_array(strtolower((string) $user->role), self::STAFF_ROLES, true);
    }

    private function isClient(User $user): bool
    {
        return strtolower((string) $user->role) === 'client';
    }
}
