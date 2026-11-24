<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** Call, Email, Check-in call, Check-in email, Meeting, Shoot, Other, plus types admins add. */
class CrmEventType extends Model
{
    protected $table = 'crm_event_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean', 'counts_as_contact' => 'boolean'];
    }
}
