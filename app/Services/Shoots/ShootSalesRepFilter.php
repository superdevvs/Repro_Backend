<?php

namespace App\Services\Shoots;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ShootSalesRepFilter
{
    // An explicit shoot assignment takes precedence over the client's current account assignment.
    private const EFFECTIVE_REP = "COALESCE(shoots.rep_id, (SELECT COALESCE(NULLIF(json_extract(users.metadata, '$.accountRepId'), ''), NULLIF(json_extract(users.metadata, '$.account_rep_id'), ''), NULLIF(json_extract(users.metadata, '$.repId'), ''), NULLIF(json_extract(users.metadata, '$.rep_id'), ''), users.created_by_id) FROM users WHERE users.id = shoots.client_id))";

    public function apply(Builder $query, array $ids): void
    {
        if ($ids !== []) {
            $query->whereIn(\Illuminate\Support\Facades\DB::raw("CAST(".self::EFFECTIVE_REP." AS TEXT)"), array_map('strval', $ids));
        }
    }

    public function options(Builder $accessible): array
    {
        return User::query()->whereIn('id', (clone $accessible)->selectRaw(self::EFFECTIVE_REP))
            ->orderBy('name')->get(['id', 'name', 'role', 'secondary_roles'])
            ->filter(fn (User $user) => app(ShootAuthorizationSupport::class)->hasRole($user, ['salesRep']))
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()->all();
    }
}
