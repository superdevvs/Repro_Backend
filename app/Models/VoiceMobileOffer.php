<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoiceMobileOffer extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    protected $hidden = ['client_state', 'call_control_id'];

    public function session()
    {
        return $this->belongsTo(VoiceMobileSession::class);
    }

    public function incomingOffer()
    {
        return $this->belongsTo(VoiceIncomingOffer::class);
    }
}
