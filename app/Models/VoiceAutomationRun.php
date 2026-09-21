<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VoiceAutomationRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['rule_snapshot' => 'array', 'context' => 'array', 'scheduled_at' => 'datetime'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(VoiceAutomationRule::class, 'voice_automation_rule_id')->withTrashed();
    }

    public function scheduledCall(): BelongsTo
    {
        return $this->belongsTo(ScheduledVoiceCall::class, 'scheduled_voice_call_id');
    }

    public function task(): HasOne
    {
        return $this->hasOne(VoiceFollowUpTask::class, 'automation_run_id');
    }
}
