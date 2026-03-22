<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'automation_rule_id',
        'trigger_type',
        'status',
        'context_json',
        'related_shoot_id',
        'related_account_id',
        'related_invoice_id',
        'scheduled_for',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'context_json' => 'array',
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class)->orderBy('id');
    }
}
