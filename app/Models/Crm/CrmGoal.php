<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/** A monthly target for one person: new contacts, booked, first shoots or follow-ups done. */
class CrmGoal extends Model
{
    protected $table = 'crm_goals';

    protected $guarded = ['id'];

    public const METRICS = ['new_contacts', 'booked', 'first_shoots', 'follow_ups_done'];

    public const METRIC_LABELS = ['new_contacts' => 'New contacts', 'booked' => 'Booked', 'first_shoots' => 'First shoots', 'follow_ups_done' => 'Follow-ups done'];
}
