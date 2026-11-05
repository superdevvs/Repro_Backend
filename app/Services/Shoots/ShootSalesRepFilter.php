<?php

namespace App\Services\Shoots;

use App\Models\User;
use App\Support\DatabaseSql;
use Illuminate\Database\Eloquent\Builder;

class ShootSalesRepFilter
{
    // An explicit shoot assignment takes precedence over the client's current account assignment.
    private function effectiveRep(Builder $query): string
    {
        $fields = array_map(fn (string $key) => 'NULLIF('.DatabaseSql::jsonScalar('users.metadata', '$.'.$key, $query->getConnection()).", '')", ['accountRepId', 'account_rep_id', 'repId', 'rep_id']);

        return 'COALESCE(shoots.rep_id, (SELECT COALESCE('.implode(', ', $fields).', users.created_by_id) FROM users WHERE users.id = shoots.client_id))';
    }

    public function apply(Builder $query, array $ids): void
    {
        if ($ids !== []) {
            $query->whereIn(\Illuminate\Support\Facades\DB::raw(DatabaseSql::textCast($this->effectiveRep($query), $query->getConnection())), array_map('strval', $ids));
        }
    }

    public function options(Builder $accessible): array
    {
        return User::query()->whereIn('id', (clone $accessible)->selectRaw($this->effectiveRep($accessible)))
            ->orderBy('name')->get(['id', 'name', 'role', 'secondary_roles'])
            ->filter(fn (User $user) => app(ShootAuthorizationSupport::class)->hasRole($user, ['salesRep']))
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()->all();
    }
}
