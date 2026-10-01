<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoiceTranscriptRecovery extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function voiceCall()
    {
        return $this->belongsTo(VoiceCall::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
