<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AryeoConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = ['client_ids' => 'array', 'delivery_shoot_ids' => 'array', 'capabilities' => 'array', 'enabled' => 'boolean', 'processing_enabled' => 'boolean', 'last_seen_at' => 'datetime'];
}
