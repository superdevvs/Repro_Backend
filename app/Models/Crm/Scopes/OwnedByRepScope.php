<?php

namespace App\Models\Crm\Scopes;

use App\Services\Crm\CrmAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Sales reps see only the contacts assigned to them, everywhere: board, lists,
 * search, reports, Robbie and the API. Users with crm-contacts view-all (admins,
 * the super admin) see everyone. With nobody signed in (jobs, commands,
 * webhooks) the scope does not restrict.
 *
 * If a signed-in user lacks the CRM entirely, the scope matches nothing rather
 * than everything.
 */
class OwnedByRepScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $restriction = app(CrmAccess::class)->currentRestriction();

        if ($restriction === null) {
            return;
        }

        if ($restriction === CrmAccess::NO_ACCESS) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('assigned_rep_id'), $restriction);
    }
}
