<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingStudioAccountSetup extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['notification_state' => 'encrypted:array', 'sent_at' => 'datetime'];
}
