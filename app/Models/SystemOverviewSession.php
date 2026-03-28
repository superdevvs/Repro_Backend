<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemOverviewSession extends Model
{
    protected $fillable = [
        'session_key',
        'user_id',
        'user_name',
        'user_role',
        'is_authenticated',
        'is_active',
        'current_route',
        'current_page',
        'current_action',
        'component_stack',
        'blocker_state',
        'blocker_message',
        'last_api_path',
        'last_trace_id',
        'metadata',
        'started_at',
        'last_activity_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'is_authenticated' => 'boolean',
            'is_active' => 'boolean',
            'component_stack' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function routeEvents(): HasMany
    {
        return $this->hasMany(SystemOverviewRouteEvent::class);
    }

    public function requestTraces(): HasMany
    {
        return $this->hasMany(SystemOverviewRequestTrace::class);
    }

    public function errorEvents(): HasMany
    {
        return $this->hasMany(SystemOverviewErrorEvent::class);
    }
}
