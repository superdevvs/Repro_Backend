<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoiceIncomingOffer extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['eligible_user_ids' => 'array', 'metadata' => 'array', 'expires_at' => 'datetime', 'claimed_at' => 'datetime'];

    public function voiceCall()
    {
        return $this->belongsTo(VoiceCall::class);
    }

    public function claimedBy()
    {
        return $this->belongsTo(User::class, 'claimed_by_id');
    }

    public function phoneOffers()
    {
        return $this->hasMany(VoicePhoneOffer::class, 'incoming_offer_id');
    }
}
