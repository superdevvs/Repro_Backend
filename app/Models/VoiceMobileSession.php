<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoiceMobileSession extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['push_token' => 'encrypted', 'expires_at' => 'datetime', 'registration_expires_at' => 'datetime', 'revoked_at' => 'datetime', 'provision_attempted_at' => 'datetime', 'credential_attempted_at' => 'datetime'];

    protected $hidden = ['push_token', 'push_token_hash', 'credential_id', 'credential_rotation_from', 'credential_cleanup_id', 'connection_id', 'connection_username', 'sip_username', 'personal_access_token_id'];

    public function personalAccessToken()
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
