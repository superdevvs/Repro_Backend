<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemOverviewErrorEvent extends Model
{
    protected $fillable = [
        'system_overview_session_id',
        'session_key',
        'user_id',
        'trace_id',
        'source',
        'severity',
        'route_path',
        'component_name',
        'blocker_type',
        'error_class',
        'message',
        'context_summary',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context_summary' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SystemOverviewSession::class, 'system_overview_session_id');
    }
}
