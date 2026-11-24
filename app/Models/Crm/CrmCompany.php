<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A brokerage or business. Shared across reps; each contact at it keeps its own owner. */
class CrmCompany extends Model
{
    protected $table = 'crm_companies';

    protected $guarded = ['id'];

    public function contacts(): HasMany
    {
        return $this->hasMany(CrmContact::class, 'company_id');
    }
}
