<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioUpload extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['path', 'preview_path', 'request_key', 'request_hash'];

    protected $casts = ['client_id' => 'integer', 'width' => 'integer', 'height' => 'integer', 'byte_size' => 'integer',
        'expires_at' => 'datetime', 'pinned_at' => 'datetime', 'media_purged_at' => 'datetime'];
}
