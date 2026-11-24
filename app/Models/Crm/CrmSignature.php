<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/**
 * One version of a user's email signature. Built from a structured form, never
 * free HTML. Pending until the super admin approves it; the last approved
 * version stays in use meanwhile.
 */
class CrmSignature extends Model
{
    protected $table = 'crm_signatures';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'reviewed_at' => 'datetime', 'is_current_approved' => 'boolean'];
    }
}
