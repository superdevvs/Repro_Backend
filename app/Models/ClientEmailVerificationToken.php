<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientEmailVerificationToken extends Model
{
    use HasFactory;

    public ?string $plain_token = null;

    protected $fillable = [
        'user_id',
        'email_snapshot',
        'email_hash',
        'token_hash',
        'expires_at',
        'used_at',
        'superseded_at',
        'issued_by',
        'issued_context',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'superseded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function isActive(): bool
    {
        return !$this->isExpired() && !$this->isUsed() && !$this->isSuperseded();
    }
}
