<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** One change of the rep who owns a contact, with who made it and when. */
class CrmAssignmentChange extends Model
{
    protected $table = 'crm_assignment_changes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
