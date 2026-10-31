<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioBillingRequest extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['request_key', 'request_hash', 'checkout_key', 'preview'];

    protected $casts = ['client_id' => 'integer', 'actor_id' => 'integer', 'subscription_id' => 'integer',
        'amount_cents' => 'integer', 'preview' => 'array', 'expires_at' => 'immutable_datetime',
        'confirmed_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
}
