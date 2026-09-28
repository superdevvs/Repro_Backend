<?php

namespace App\Services;

use App\Models\ListingStudioRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Listing Studio enrollment is separate from access to the production media Studio. */
class ListingStudioAccess
{
    public function role(User $user): string
    {
        $roles = array_map(
            static fn ($role) => strtolower(str_replace(['_', '-'], '', (string) $role)),
            array_merge([$user->role], $user->secondary_roles ?? []),
        );
        if (in_array('rep', $roles, true)) {
            $roles[] = 'salesrep';
        }

        foreach (['superadmin', 'admin', 'salesrep', 'client'] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return '';
    }

    public function isAdmin(User $user): bool
    {
        return in_array($this->role($user), ['admin', 'superadmin'], true);
    }

    public function clients(User $viewer): Builder
    {
        $query = User::query()->where('role', 'client');

        if ($this->isAdmin($viewer)) {
            return $query;
        }

        if ($this->role($viewer) === 'client') {
            return $query->whereKey($viewer->id);
        }

        if ($this->role($viewer) !== 'salesrep') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($viewer) {
            $scope->where('created_by_id', $viewer->id)
                ->orWhereHas('shoots', fn (Builder $shoots) => $shoots->where('rep_id', $viewer->id));
            foreach (['accountRepId', 'account_rep_id', 'repId', 'rep_id'] as $key) {
                $scope->orWhere('metadata->'.$key, (string) $viewer->id)
                    ->orWhere('metadata->'.$key, (int) $viewer->id);
            }
        });
    }

    public function requests(User $viewer): Builder
    {
        $query = ListingStudioRequest::query();
        if ($this->isAdmin($viewer)) {
            return $query;
        }

        if ($this->role($viewer) === 'client') {
            return $query->where('client_id', $viewer->id);
        }

        if ($this->role($viewer) === 'salesrep') {
            return $query->where(function (Builder $scope) use ($viewer) {
                $scope->whereIn('client_id', $this->clients($viewer)->select('users.id'))
                    ->orWhere(function (Builder $custom) use ($viewer) {
                        $custom->whereNull('client_id')->where('submitted_by_id', $viewer->id);
                    });
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function notifications(User $viewer): \Illuminate\Support\Collection
    {
        $query = $this->requests($viewer);
        $this->isAdmin($viewer)
            ? $query->where('status', 'pending')
            : $query->whereNotNull('reviewed_at');

        return $query->latest('updated_at')->limit(20)->get()->map(function (ListingStudioRequest $item) {
            $label = match ($item->type) {
                'signup' => 'signup', 'change' => 'change request', default => 'call request',
            };

            return [
                'id' => 'listing-studio-'.$item->id.'-'.$item->status,
                'message' => 'Listing Studio '.$label.' for '.($item->contact['name'] ?? 'a client').': '.($item->status === 'pending' ? 'awaiting review' : $item->status).'.',
                'action' => 'listing_studio_request', 'type' => 'system',
                'timestamp' => ($item->reviewed_at ?? $item->created_at)?->toIso8601String(),
                'actionUrl' => '/dashboard?listingStudio=1&listingStudioTab=requests', 'actionLabel' => 'View Listing Studio',
                'shootId' => null,
            ];
        });
    }
}
