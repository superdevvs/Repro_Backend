<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShootAdditionalFile extends Model
{
    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'file_size' => 'integer'];
}
