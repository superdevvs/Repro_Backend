<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'trigger_type',
        'is_active',
        'scope',
        'owner_id',
        'template_id',
        'channel_id',
        'condition_json',
        'schedule_json',
        'recipients_json',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'condition_json' => 'array',
        'schedule_json' => 'array',
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTrigger($query, string $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }
}

