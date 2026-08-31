<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IguideOfflineUploadSession extends Model
{
    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_ASSEMBLING = 'assembling';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'shoot_id',
        'user_id',
        'idempotency_key',
        'original_filename',
        'size_bytes',
        'expected_sha256',
        'chunk_size_bytes',
        'total_chunks',
        'received_bytes',
        'status',
        'error',
        'retryable',
        'shoot_file_id',
        'last_activity_at',
        'expires_at',
        'processing_started_at',
        'assembly_token',
        'assembly_lease_expires_at',
        'completed_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'chunk_size_bytes' => 'integer',
        'total_chunks' => 'integer',
        'received_bytes' => 'integer',
        'retryable' => 'boolean',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'assembly_lease_expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shootFile()
    {
        return $this->belongsTo(ShootFile::class);
    }

    public function chunks()
    {
        return $this->hasMany(IguideOfflineUploadChunk::class, 'upload_session_id');
    }
}
