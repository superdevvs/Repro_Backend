<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabSection extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['view' => 'array', 'data' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
}
