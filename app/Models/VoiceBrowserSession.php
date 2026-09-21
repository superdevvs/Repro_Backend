<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoiceBrowserSession extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['credential_id', 'sip_username'];

    protected $casts = ['registered' => 'boolean', 'expires_at' => 'datetime', 'heartbeat_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function legs()
    {
        return $this->hasMany(VoiceBrowserLeg::class, 'session_id');
    }
}
