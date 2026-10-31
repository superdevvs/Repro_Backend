<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioSubscriptionAction extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['account_id', 'customer_id', 'stripe_subscription_id', 'item_id', 'source_price_id',
        'target_price_id', 'snapshot', 'preview', 'steps', 'confirmation_token_hash', 'invoice_id', 'schedule_id'];

    protected $casts = ['livemode' => 'boolean', 'snapshot' => 'array', 'preview' => 'array', 'steps' => 'array',
        'review_expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'submitted_at' => 'datetime',
        'effective_at' => 'datetime', 'completed_at' => 'datetime'];
}
