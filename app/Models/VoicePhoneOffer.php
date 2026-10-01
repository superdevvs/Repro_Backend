<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoicePhoneOffer extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['destination', 'client_state'];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    public function incomingOffer()
    {
        return $this->belongsTo(VoiceIncomingOffer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
