<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** A delivery, open, click, bounce, complaint or reply on a CRM email. */
class CrmEmailEvent extends Model
{
    protected $table = 'crm_email_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }
}
