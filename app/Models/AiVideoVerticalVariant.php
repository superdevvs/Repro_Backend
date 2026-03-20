<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiVideoVerticalVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_generation_job_id',
        'frame_type',
        'variant_index',
        'higgsfield_request_id',
        'status',
        'image_url',
        'is_selected',
    ];

    protected $casts = [
        'is_selected' => 'boolean',
        'variant_index' => 'integer',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function videoGenerationJob(): BelongsTo
    {
        return $this->belongsTo(AiVideoGenerationJob::class, 'video_generation_job_id');
    }

    public function markAsCompleted(string $imageUrl): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->image_url = $imageUrl;
        $this->save();
    }

    public function markAsFailed(): void
    {
        $this->status = self::STATUS_FAILED;
        $this->save();
    }

    public function select(): void
    {
        $this->is_selected = true;
        $this->save();
    }

    public function deselect(): void
    {
        $this->is_selected = false;
        $this->save();
    }
}
