<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** One email that left (or tried to leave) the CRM, with the exact content sent. */
class CrmEmailSend extends Model
{
    protected $table = 'crm_email_sends';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'first_opened_at' => 'datetime',
            'bounced_at' => 'datetime', 'complained_at' => 'datetime', 'replied_at' => 'datetime',
        ];
    }
}
