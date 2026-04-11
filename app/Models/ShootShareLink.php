<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShootShareLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'created_by',
        'share_url',
        'media_stage',
        'public_token',
        'dropbox_path',
        'download_count',
        'last_accessed_at',
        'expires_at',
        'is_revoked',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'is_revoked' => 'boolean',
        'download_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            if (!is_string($link->public_token) || trim($link->public_token) === '') {
                $link->public_token = Str::random(64);
            }

            if ($link->expires_at === null) {
                $link->expires_at = now()->addDays(7);
            }
        });
    }

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return !$this->is_revoked && !$this->isExpired();
    }

    public function incrementDownloadCount(): void
    {
        $this->forceFill([
            'last_accessed_at' => now(),
            'download_count' => (int) $this->download_count + 1,
        ])->save();
    }

    public function revoke(int $userId): void
    {
        $this->update([
            'is_revoked' => true,
            'revoked_at' => now(),
            'revoked_by' => $userId,
        ]);
    }

    public function scopeForMediaStage($query, string $mediaStage)
    {
        return $query->where('media_stage', strtolower($mediaStage));
    }
}
