<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'role',
        'onboarding_key',
        'version',
        'event_type',
        'step_index',
        'step_target',
        'session_uuid',
        'source',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'step_index' => 'integer',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
