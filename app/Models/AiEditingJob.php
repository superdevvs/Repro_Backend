<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEditingJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'shoot_file_id',
        'user_id',
        'fotello_job_id',
        'status',
        'editing_type',
        'editing_params',
        'original_image_url',
        'edited_image_url',
        'error_message',
        'retry_count',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'editing_params' => 'array',
        'ai_editing_metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    // Editing type constants (will be expanded based on Fotello API)
    const TYPE_ENHANCE = 'enhance';
    const TYPE_SKY_REPLACE = 'sky_replace';
    const TYPE_REMOVE_OBJECT = 'remove_object';
    const TYPE_COLOR_CORRECTION = 'color_correction';
    const TYPE_EXPOSURE_FIX = 'exposure_fix';
    const TYPE_WHITE_BALANCE = 'white_balance';

    /**
     * Get the shoot that owns this editing job
     */
    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    /**
     * Get the shoot file associated with this job
     */
    public function shootFile(): BelongsTo
    {
        return $this->belongsTo(ShootFile::class);
    }

    /**
     * Get the user who created this job
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if job is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if job is processing
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if job is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if job failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark job as processing
     */
    public function markAsProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->started_at = now();
        $this->save();
    }

    /**
     * Mark job as completed
     */
    public function markAsCompleted(string $editedImageUrl): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->edited_image_url = $editedImageUrl;
        $this->completed_at = now();
        $this->save();

        // Update associated shoot file if exists
        if ($this->shoot_file_id) {
            $shootFile = $this->shootFile;
            if ($shootFile) {
                $shootFile->is_ai_edited = true;
                $shootFile->ai_editing_job_id = $this->id;
                $shootFile->ai_editing_metadata = [
                    'editing_type' => $this->editing_type,
                    'completed_at' => $this->completed_at->toIso8601String(),
                ];
                $shootFile->save();
            }
        }
    }

    /**
     * Mark job as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        $this->completed_at = now();
        $this->save();
    }

    /**
     * Increment retry count
     */
    public function incrementRetry(): void
    {
        $this->retry_count++;
        $this->save();
    }
}

