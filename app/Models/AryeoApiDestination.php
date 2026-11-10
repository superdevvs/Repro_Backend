<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AryeoApiDestination extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['executor_token'];

    protected $casts = ['identity' => 'array', 'recipients' => 'array', 'address' => 'array', 'requirements' => 'array', 'consent' => 'array', 'on_hold' => 'boolean', 'executor_expires_at' => 'datetime', 'verified_at' => 'datetime'];

    public function installation()
    {
        return $this->belongsTo(AryeoApiInstallation::class, 'installation_id');
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }
}
