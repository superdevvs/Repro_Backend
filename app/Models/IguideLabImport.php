<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabImport extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['private_input', 'request_hash'];
    protected $casts = ['private_input' => 'encrypted:array', 'audit' => 'array', 'sources' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
}
