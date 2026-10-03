<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AryeoJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['lease_hash', 'claim_id'];

    protected $casts = ['snapshot' => 'array', 'steps' => 'array', 'receipt' => 'array', 'progress' => 'array', 'lease_expires_at' => 'datetime'];
}
