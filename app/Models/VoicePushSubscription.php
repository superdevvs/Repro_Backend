<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoicePushSubscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['endpoint', 'public_key', 'auth_token', 'endpoint_hash', 'scope', 'revoke_hash'];

    protected $casts = ['endpoint' => 'encrypted', 'public_key' => 'encrypted', 'auth_token' => 'encrypted', 'revoked_at' => 'datetime', 'last_success_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
