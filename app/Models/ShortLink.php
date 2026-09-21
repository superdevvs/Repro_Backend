<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShortLink extends Model
{
    public const TYPE_IGUIDE_OFFLINE_VIEWER = 'iguide_offline_viewer';

    public const TYPE_SHARE_DOWNLOAD = 'share_download';

    public const TYPE_MEDIA_ZIP = 'media_zip';

    public const TYPE_PAYMENT = 'payment';

    public const TARGET_SHOOT = 'shoot';

    public const TARGET_SHARE_LINK = 'share_link';

    protected $fillable = [
        'code',
        'type',
        'target_type',
        'target_id',
        'target_key',
        'destination_url',
        'expires_at',
        'revoked_at',
        'created_by',
        'hit_count',
        'last_accessed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'hit_count' => 'integer',
        'target_id' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
