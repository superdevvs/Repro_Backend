<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class ShootUploadAttempt extends Model
{
    use MassPrunable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'shoot_id',
        'actor_id',
        'idempotency_key',
        'request_fingerprint',
        'upload_type',
        'upload_batch_id',
        'upload_batch_index',
        'upload_batch_total',
        'shoot_service_id',
        'status',
        'http_status',
        'result_file_ids',
        'result_errors',
        'result_payload',
        'correlation_id',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'upload_batch_index' => 'integer',
        'upload_batch_total' => 'integer',
        'http_status' => 'integer',
        'result_file_ids' => 'array',
        'result_errors' => 'array',
        'result_payload' => 'array',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function prunable()
    {
        return static::query()
            ->where('status', '!=', self::STATUS_PENDING)
            ->where('updated_at', '<', now()->subDays(30));
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function serviceItem()
    {
        return $this->belongsTo(ShootService::class, 'shoot_service_id');
    }
}
