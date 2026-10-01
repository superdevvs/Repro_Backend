<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceStaffPhone extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['verification_hash'];

    protected $casts = ['available' => 'boolean', 'phone_enabled' => 'boolean', 'verified_at' => 'datetime', 'verification_expires_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
