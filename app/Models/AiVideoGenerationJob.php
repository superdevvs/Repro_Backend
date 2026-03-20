<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiVideoGenerationJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'user_id',
        'preset_id',
        'start_frame_file_id',
        'end_frame_file_id',
        'preset_prompt',
        'aspect_ratio',
        'status',
        'higgsfield_video_request_id',
        'original_start_frame_url',
        'original_end_frame_url',
        'selected_start_frame_url',
        'selected_end_frame_url',
        'video_url',
        'video_thumbnail_url',
        'error_message',
        'retry_count',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_CONVERTING_ASPECT = 'converting_aspect';
    const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    const STATUS_GENERATING = 'generating';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    // Aspect ratio constants
    const ASPECT_HORIZONTAL = 'horizontal';
    const ASPECT_VERTICAL = 'vertical';

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiVideoPreset::class, 'preset_id');
    }

    public function startFrameFile(): BelongsTo
    {
        return $this->belongsTo(ShootFile::class, 'start_frame_file_id');
    }

    public function endFrameFile(): BelongsTo
    {
        return $this->belongsTo(ShootFile::class, 'end_frame_file_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AiVideoVerticalVariant::class, 'video_generation_job_id');
    }

    public function startFrameVariants(): HasMany
    {
        return $this->hasMany(AiVideoVerticalVariant::class, 'video_generation_job_id')
            ->where('frame_type', 'start');
    }

    public function endFrameVariants(): HasMany
    {
        return $this->hasMany(AiVideoVerticalVariant::class, 'video_generation_job_id')
            ->where('frame_type', 'end');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isConvertingAspect(): bool
    {
        return $this->status === self::STATUS_CONVERTING_ASPECT;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === self::STATUS_AWAITING_APPROVAL;
    }

    public function isGenerating(): bool
    {
        return $this->status === self::STATUS_GENERATING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_CONVERTING_ASPECT,
            self::STATUS_AWAITING_APPROVAL,
            self::STATUS_GENERATING,
        ]);
    }

    public function markAsConvertingAspect(): void
    {
        $this->status = self::STATUS_CONVERTING_ASPECT;
        $this->started_at = now();
        $this->save();
    }

    public function markAsAwaitingApproval(): void
    {
        $this->status = self::STATUS_AWAITING_APPROVAL;
        $this->save();
    }

    public function markAsGenerating(): void
    {
        $this->status = self::STATUS_GENERATING;
        $this->save();
    }

    public function markAsCompleted(string $videoUrl, ?string $thumbnailUrl = null): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->video_url = $videoUrl;
        $this->video_thumbnail_url = $thumbnailUrl;
        $this->completed_at = now();
        $this->save();
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        $this->completed_at = now();
        $this->save();
    }

    public function incrementRetry(): void
    {
        $this->retry_count++;
        $this->save();
    }
}
