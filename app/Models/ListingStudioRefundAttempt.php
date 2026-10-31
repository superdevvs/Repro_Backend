<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioRefundAttempt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['account_id', 'payment_intent_id', 'refund_id', 'parameters'];

    protected $casts = ['livemode' => 'boolean', 'amount_cents' => 'integer', 'parameters' => 'array', 'submission_started_at' => 'immutable_datetime'];
}
