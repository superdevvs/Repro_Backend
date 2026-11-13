<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabPipeline extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['data' => 'array', 'support_metadata' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
}
