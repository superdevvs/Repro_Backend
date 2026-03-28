<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemOverviewRouteEvent extends Model
{
    protected $fillable = [
        'system_overview_session_id',
        'session_key',
        'user_id',
        'event_type',
        'route_path',
        'page_key',
        'component_name',
        'action_name',
        'severity',
        'blocker_state',
        'payload_summary',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SystemOverviewSession::class, 'system_overview_session_id');
    }
}
