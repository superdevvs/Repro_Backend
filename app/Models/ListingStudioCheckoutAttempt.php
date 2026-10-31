<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioCheckoutAttempt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['account_id', 'customer_id', 'session_id', 'payment_intent_id', 'subscription_id', 'idempotency_key', 'request_hash', 'parameters', 'checkout_url'];

    protected $casts = [
        'actor_id' => 'integer', 'livemode' => 'boolean', 'amount_cents' => 'integer', 'parameters' => 'array',
        'submission_started_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
    ];
}
