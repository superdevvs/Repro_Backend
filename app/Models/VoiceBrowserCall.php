<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoiceBrowserCall extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array'];

    public function voiceCall()
    {
        return $this->belongsTo(VoiceCall::class);
    }

    public function session()
    {
        return $this->belongsTo(VoiceBrowserSession::class, 'session_id');
    }

    public function legs()
    {
        return $this->hasMany(VoiceBrowserLeg::class, 'browser_call_id');
    }
}
