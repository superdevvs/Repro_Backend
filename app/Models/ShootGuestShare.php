<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShootGuestShare extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['token_hash', 'token_encrypted', 'code_hash'];
    protected $casts = [
        'token_encrypted' => 'encrypted',
        'invitation_attempted_at' => 'datetime', 'invitation_sent_at' => 'datetime',
        'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'code_expires_at' => 'datetime',
        'code_sent_at' => 'datetime', 'last_verified_at' => 'datetime', 'code_attempts' => 'integer',
    ];

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
