<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IguideLabImportUpload extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['request_hash'];
    protected $casts = ['chunks' => 'array', 'size_bytes' => 'integer', 'expires_at' => 'datetime'];
}
