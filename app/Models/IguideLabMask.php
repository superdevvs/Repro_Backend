<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabMask extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['statistics' => 'array', 'width' => 'integer', 'height' => 'integer', 'reviewed_at' => 'datetime'];
}
