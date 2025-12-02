<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'album_id',
        'filename',
        'stored_filename',
        'path',
        'storage_path',
        'watermarked_storage_path',
        'file_type',
        'mime_type',
        'media_type',
        'file_size',
        'uploaded_by',
        'uploaded_at',
        'workflow_stage',
        'dropbox_path',
        'dropbox_file_id',
        'moved_to_completed_at',
        'verified_at',
        'verified_by',
        'verification_notes',
        'is_cover',
        'is_favorite',
        'bracket_group',
        'sequence',
        'flag_reason',
        'metadata'
    ];

    protected $casts = [
        'moved_to_completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'is_cover' => 'boolean',
        'is_favorite' => 'boolean',
        'bracket_group' => 'integer',
        'sequence' => 'integer',
        'metadata' => 'array',
    ];

    // Workflow stage constants
    const STAGE_TODO = 'todo';
    const STAGE_COMPLETED = 'completed';
    const STAGE_VERIFIED = 'verified';
    const STAGE_ARCHIVED = 'archived';
    const STAGE_FLAGGED = 'flagged';

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function album()
    {
        return $this->belongsTo(ShootMediaAlbum::class, 'album_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function canMoveToCompleted()
    {
        return $this->workflow_stage === self::STAGE_TODO;
    }

    public function canVerify()
    {
        return $this->workflow_stage === self::STAGE_COMPLETED;
    }

    public function moveToCompleted($userId = null)
    {
        $this->workflow_stage = self::STAGE_COMPLETED;
        $this->moved_to_completed_at = now();
        $this->save();

        // Log the action
        $this->shoot->workflowLogs()->create([
            'user_id' => $userId ?? auth()->id(),
            'action' => 'file_moved_to_completed',
            'details' => "File '{$this->filename}' moved to completed folder",
            'metadata' => [
                'file_id' => $this->id,
                'filename' => $this->filename,
                'dropbox_path' => $this->dropbox_path
            ]
        ]);
    }

    public function verify($userId, $notes = null)
    {
        $this->workflow_stage = self::STAGE_VERIFIED;
        $this->verified_at = now();
        $this->verified_by = $userId;
        $this->verification_notes = $notes;
        $this->save();

        // Log the action
        $this->shoot->workflowLogs()->create([
            'user_id' => $userId,
            'action' => 'file_verified',
            'details' => "File '{$this->filename}' verified by admin",
            'metadata' => [
                'file_id' => $this->id,
                'filename' => $this->filename,
                'verification_notes' => $notes
            ]
        ]);
    }

    /**
     * Get public URL for media file based on paywall status
     */
    public function getPublicUrl(): ?string
    {
        $shoot = $this->shoot;

        // If bypass_paywall is true OR payment_status is paid, return original
        if ($shoot->bypass_paywall || $shoot->payment_status === 'paid') {
            return $this->storage_path ?? $this->dropbox_path;
        }

        // Otherwise return watermarked version if available
        if ($this->watermarked_storage_path) {
            return $this->watermarked_storage_path;
        }

        // If no watermarked version yet, return original but mark as restricted
        // Frontend should handle this appropriately
        return $this->storage_path ?? $this->dropbox_path;
    }

    /**
     * Check if file should be watermarked
     */
    public function shouldBeWatermarked(): bool
    {
        $shoot = $this->shoot;
        return !$shoot->bypass_paywall && $shoot->payment_status !== 'paid';
    }
}