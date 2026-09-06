<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserTaxDocument extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'path', 'original_name', 'mime_type', 'size', 'sha256', 'notes', 'legacy_public_path', 'submitted_at'];
    protected $hidden = ['path', 'sha256', 'notes', 'legacy_public_path', 'user_id'];
    protected $casts = ['size' => 'integer', 'submitted_at' => 'datetime', 'notes' => 'encrypted'];
}
