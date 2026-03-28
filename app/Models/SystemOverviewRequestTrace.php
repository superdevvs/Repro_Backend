<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemOverviewRequestTrace extends Model
{
    protected $fillable = [
        'system_overview_session_id',
        'session_key',
        'user_id',
        'trace_id',
        'domain',
        'route_name',
        'method',
        'path',
        'current_route',
        'controller_action',
        'status_code',
        'duration_ms',
        'request_bytes',
        'response_bytes',
        'blocker_type',
        'blocker_message',
        'error_class',
        'request_payload_summary',
        'response_payload_summary',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload_summary' => 'array',
            'response_payload_summary' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SystemOverviewSession::class, 'system_overview_session_id');
    }
}
