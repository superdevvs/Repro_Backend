<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabRun extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $casts = ['options' => 'array', 'usage' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'output_width' => 'integer', 'output_height' => 'integer', 'duration_ms' => 'integer'];
}
