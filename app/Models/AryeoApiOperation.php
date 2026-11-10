<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AryeoApiOperation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['request', 'baseline', 'result'];

    protected $casts = ['request' => 'encrypted:array', 'baseline' => 'encrypted:array', 'result' => 'encrypted:array', 'sent_at' => 'datetime', 'resolved_at' => 'datetime'];
}
