<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AryeoRequest extends Model
{
    protected $guarded = [];

    protected $casts = ['discovery' => 'array', 'inventory' => 'array', 'inventory_checked_at' => 'datetime'];
}
