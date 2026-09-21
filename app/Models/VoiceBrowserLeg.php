<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoiceBrowserLeg extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['client_state', 'destination'];

    protected $casts = ['muted' => 'boolean', 'held' => 'boolean', 'answered_at' => 'datetime', 'joined_at' => 'datetime', 'ended_at' => 'datetime'];

    public function browserCall()
    {
        return $this->belongsTo(VoiceBrowserCall::class, 'browser_call_id');
    }

    public function session()
    {
        return $this->belongsTo(VoiceBrowserSession::class, 'session_id');
    }
}
