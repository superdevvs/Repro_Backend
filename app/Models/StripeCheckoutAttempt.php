<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeCheckoutAttempt extends Model
{
    public const STATUS_CREATING = 'creating';

    public const STATUS_OPEN = 'open';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'client_id',
        'scope',
        'ui_mode',
        'expected_amount_cents',
        'currency',
        'status',
        'request_fingerprint',
        'idempotency_key',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'expires_at',
        'completed_at',
        'failure_message',
    ];

    protected $casts = [
        'expected_amount_cents' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(StripeCheckoutAttemptItem::class)->orderBy('position');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
