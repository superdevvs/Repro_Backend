<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabAnalysis extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['settings' => 'array', 'data' => 'array', 'proposal' => 'array', 'provider_attempts' => 'array', 'usage' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
}
