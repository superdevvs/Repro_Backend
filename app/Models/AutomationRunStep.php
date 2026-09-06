<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRunStep extends Model
{
    use HasFactory;
    use \App\Models\Concerns\HasSafeAutomationError {
        attributesToArray as private safeErrorAttributesToArray;
    }

    protected $hidden = ['input_json'];

    public function attributesToArray(): array
    {
        $attributes = $this->safeErrorAttributesToArray();
        if (array_key_exists('output_json', $attributes)) {
            $output = is_array($attributes['output_json']) ? $attributes['output_json'] : [];
            $safe = [];
            foreach (['resumed_immediately', 'ended', 'passthrough', 'skipped', 'protected'] as $key) {
                if (is_bool($output[$key] ?? null)) $safe[$key] = $output[$key];
            }
            if (in_array($output['branch'] ?? null, ['true', 'false'], true)) $safe['branch'] = $output['branch'];
            if (in_array($output['channel'] ?? null, ['email', 'sms', 'internal'], true)) $safe['channel'] = $output['channel'];
            foreach (['sent_to', 'delivered_to'] as $key) {
                if (is_array($output[$key] ?? null)) {
                    $safe[$key] = array_values(array_filter(array_slice($output[$key], 0, 100), fn ($value) => is_string($value) && strlen($value) <= 254
                        && (filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/\A\+?[0-9]{7,15}\z/', $value))));
                }
            }
            $attributes['output_json'] = $safe;
        }
        return $attributes;
    }

    protected $fillable = [
        'automation_run_id',
        'automation_rule_id',
        'node_id',
        'node_type',
        'status',
        'attempt_count',
        'scheduled_for',
        'started_at',
        'completed_at',
        'input_json',
        'output_json',
        'error_message',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'input_json' => 'array',
        'output_json' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
