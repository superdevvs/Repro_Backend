<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiListingVideoJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_STITCHING = 'stitching';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'shoot_id',
        'user_id',
        'provider',
        'selected_file_ids',
        'target_seconds',
        'status',
        'total_clips',
        'completed_clips',
        'outputs',
        'provider_request_ids',
        'estimated_cost',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'selected_file_ids' => 'array',
        'outputs' => 'array',
        'provider_request_ids' => 'array',
        'target_seconds' => 'integer',
        'total_clips' => 'integer',
        'completed_clips' => 'integer',
        'estimated_cost' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
            self::STATUS_STITCHING,
        ], true);
    }

    public function markAsProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'started_at' => $this->started_at ?? now(),
            'error_message' => null,
        ])->save();
    }

    public function markAsStitching(): void
    {
        $this->forceFill(['status' => self::STATUS_STITCHING])->save();
    }

    public function markAsCompleted(array $outputs): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'outputs' => $outputs,
            'completed_at' => now(),
        ])->save();
    }

    public function markAsFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();
    }

    public function markAsCancelled(): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'completed_at' => now(),
        ])->save();
    }
}
