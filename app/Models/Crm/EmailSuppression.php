<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** An address that must not receive campaign email: unsubscribed, bounced, complained, or added by an admin. */
class EmailSuppression extends Model
{
    protected $table = 'email_suppressions';

    protected $guarded = ['id'];
}
