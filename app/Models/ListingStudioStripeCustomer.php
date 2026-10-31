<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioStripeCustomer extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['account_id', 'customer_id', 'create_parameters'];

    protected $casts = ['livemode' => 'boolean', 'create_parameters' => 'array', 'submission_started_at' => 'immutable_datetime'];
}
