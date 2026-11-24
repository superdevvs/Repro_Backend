<?php

namespace App\Models\Crm\Scopes;

use App\Models\Crm\CrmContact;
use App\Services\Crm\CrmAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Records that hang off a contact (quotes) are visible exactly when the contact is. */
class OwnedThroughContactScope implements Scope
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
        $builder->whereIn($model->qualifyColumn('contact_id'), CrmContact::query()->select('id'));
    }
}
