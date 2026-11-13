<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MessageMedia extends Model
{
    use HasUuids;
    protected $table = 'message_media';
    protected $guarded = [];
    protected $hidden = ['source_url', 'source_key'];
    protected $casts = ['source_url' => 'encrypted', 'expires_at' => 'datetime', 'share_expires_at' => 'datetime', 'size' => 'integer', 'received_bytes' => 'integer'];
    public function directory(): string { return $this->id; }
    public function path(): string { return Storage::disk('message_media')->path($this->directory().'/content'); }
}
