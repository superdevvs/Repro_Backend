<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AryeoApiCase extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['resolution' => 'array', 'escalate_at' => 'datetime', 'resolved_at' => 'datetime'];
}
