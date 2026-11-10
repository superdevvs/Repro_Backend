<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AryeoApiInstallation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected $casts = ['credentials' => 'encrypted:array', 'paused' => 'boolean', 'enrolled' => 'boolean', 'paused_workflows' => 'array', 'allowed_client_ids' => 'array', 'allowed_shoot_ids' => 'array', 'requirements' => 'array', 'capabilities' => 'array', 'token_expires_at' => 'datetime', 'refresh_started_at' => 'datetime', 'verified_at' => 'datetime'];
}
