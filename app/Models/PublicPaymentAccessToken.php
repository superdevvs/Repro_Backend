<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PublicPaymentAccessToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'token',
        'created_by',
        'expires_at',
        'revoked_at',
        'last_accessed_at',
        'first_accessed_at',
        'access_count',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'first_accessed_at' => 'datetime',
        'access_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            if (!is_string($token->token) || trim($token->token) === '') {
                $token->token = Str::random(64);
            }

            if ($token->expires_at === null) {
                $token->expires_at = now()->addDays(30);
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
        return !$this->isRevoked() && !$this->isExpired();
    }

    public function markAccessed(): void
    {
        $now = now();

        $this->forceFill([
            'first_accessed_at' => $this->first_accessed_at ?? $now,
            'last_accessed_at' => $now,
            'access_count' => (int) $this->access_count + 1,
        ])->save();
    }

    public function revoke(): void
    {
        if ($this->revoked_at !== null) {
            return;
        }

        $this->forceFill([
            'revoked_at' => now(),
        ])->save();
    }
}
