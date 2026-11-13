<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabCase extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'width' => 'integer', 'height' => 'integer'];
}
