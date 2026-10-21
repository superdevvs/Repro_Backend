<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AryeoRequest extends Model
{
    protected $guarded = [];

    protected $hidden = ['match_verification'];

    protected $casts = ['discovery' => 'array', 'inventory' => 'array', 'inventory_checked_at' => 'datetime', 'match_verification' => 'array'];
}
