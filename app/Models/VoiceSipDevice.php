<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoiceSipDevice extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['sip_password', 'sip_username', 'connection_id'];

    protected $casts = ['sip_password' => 'encrypted', 'enabled' => 'boolean', 'registered' => 'boolean',
        'registration_checked_at' => 'datetime', 'provision_attempted_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
