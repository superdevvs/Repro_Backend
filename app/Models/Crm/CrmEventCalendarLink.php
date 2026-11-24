<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** Where a follow-up lives in its owner's Google Calendar. */
class CrmEventCalendarLink extends Model
{
    protected $table = 'crm_event_calendar_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }
}
