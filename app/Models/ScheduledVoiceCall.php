<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledVoiceCall extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_DEFERRED = 'deferred';
    public const STATUS_DIALING = 'dialing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'status',
        'automation_type',
        'reason',
        'target_phone',
        'from_phone',
        'caller_user_id',
        'caller_contact_id',
        'related_shoot_id',
        'related_invoice_id',
        'original_voice_call_id',
        'result_voice_call_id',
        'created_by_user_id',
        'scheduled_at',
        'next_attempt_at',
        'last_attempt_at',
        'completed_at',
        'attempts',
        'max_attempts',
        'quiet_hours',
        'summary',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'quiet_hours' => 'array',
        'metadata' => 'array',
    ];

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        if (array_key_exists('last_error', $attributes)) {
            $attributes['last_error'] = \App\Services\ApiErrorResponder::storedFailure(
                $attributes['last_error'],
                'The scheduled call could not be completed. Please retry or contact support.',
            );
        }

        return $attributes;
    }

    public function callerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_user_id');
    }

    public function callerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'caller_contact_id');
    }

    public function relatedShoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class, 'related_shoot_id');
    }

    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    public function originalVoiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class, 'original_voice_call_id');
    }

    public function resultVoiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class, 'result_voice_call_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
