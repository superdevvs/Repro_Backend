<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileNotificationDevice extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['token', 'token_hash', 'personal_access_token_id'];
    protected $casts = ['token' => 'encrypted', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function activeOwner(): ?User
    {
        if (! $this->token || $this->revoked_at || $this->expires_at->isPast()) {
            return null;
        }
        $token = PersonalAccessToken::find($this->personal_access_token_id);
        $expiry = config('sanctum.expiration');
        if (! $token || ($token->expires_at && $token->expires_at->isPast())
            || ($expiry && $token->created_at->lte(now()->subMinutes($expiry)))) {
            return null;
        }
        $user = $token->tokenable;
        return $user instanceof User && (int) $user->id === (int) $this->user_id
            && $user->isAccountEligibleForAuthentication() ? $user : null;
    }
}
