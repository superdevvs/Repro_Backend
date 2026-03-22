<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AutomationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'trigger_type',
        'editor_mode',
        'engine_version',
        'is_active',
        'scope',
        'owner_id',
        'template_id',
        'channel_id',
        'condition_json',
        'schedule_json',
        'workflow_definition_json',
        'entry_trigger_json',
        'is_system_locked',
        'recipients_json',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'engine_version' => 'integer',
        'is_active' => 'bool',
        'condition_json' => 'array',
        'schedule_json' => 'array',
        'workflow_definition_json' => 'array',
        'entry_trigger_json' => 'array',
        'is_system_locked' => 'bool',
        'recipients_json' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(MessageChannel::class, 'channel_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function latestDispatch(): HasOne
    {
        return $this->hasOne(AutomationDispatch::class, 'automation_rule_id')->latestOfMany('scheduled_for');
    }

    public function recentRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'automation_rule_id')->latest();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTrigger($query, string $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }
}

