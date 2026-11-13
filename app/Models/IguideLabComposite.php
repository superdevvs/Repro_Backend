<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabComposite extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $casts = ['manifest' => 'array'];
}
