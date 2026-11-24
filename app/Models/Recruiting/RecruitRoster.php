<?php

namespace App\Models\Recruiting;

use Illuminate\Database\Eloquent\Model;

/** A hired photographer or rep, with markets, gear and the expiry dates that need reminders. */
class RecruitRoster extends Model
{
    protected $table = 'recruit_roster';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'states' => 'array', 'gear' => 'array', 'active' => 'boolean',
            'part107_expiry' => 'date', 'insurance_expiry' => 'date', 'part107_reminded_for' => 'date', 'insurance_reminded_for' => 'date',
        ];
    }
}
