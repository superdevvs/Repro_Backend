<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** A notice for one user: a duplicate attempt, a drip that stopped, a lead assigned to them. */
class CrmNotice extends Model
{
    protected $table = 'crm_notices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }
}
