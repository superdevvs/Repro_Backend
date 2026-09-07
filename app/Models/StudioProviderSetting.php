<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioProviderSetting extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array'];
}
