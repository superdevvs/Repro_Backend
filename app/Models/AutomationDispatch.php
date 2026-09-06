<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationDispatch extends Model
{
    use HasFactory;
    use \App\Models\Concerns\HasSafeAutomationError;

    // Console output belongs to restricted operator diagnostics.
    protected $hidden = ['output'];

    protected $fillable = [
        'automation_rule_id',
        'trigger_type',
        'period_key',
        'scheduled_for',
        'command',
        'status',
        'output',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
