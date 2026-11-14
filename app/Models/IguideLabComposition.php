<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabComposition extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['data' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
}
