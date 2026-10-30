<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoiceMobileTicket extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime'];

    protected $hidden = ['destination', 'call_control_id'];

    public function session()
    {
        return $this->belongsTo(VoiceMobileSession::class);
    }

    public function voiceCall()
    {
        return $this->belongsTo(VoiceCall::class);
    }
}
