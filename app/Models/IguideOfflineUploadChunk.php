<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IguideOfflineUploadChunk extends Model
{
    protected $fillable = [
        'upload_session_id',
        'chunk_index',
        'offset_bytes',
        'size_bytes',
        'sha256',
        'storage_path',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'offset_bytes' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function uploadSession()
    {
        return $this->belongsTo(IguideOfflineUploadSession::class, 'upload_session_id');
    }
}
