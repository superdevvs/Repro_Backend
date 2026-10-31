<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioOutbox extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'listing_studio_outbox';

    protected $hidden = ['payload', 'lease_token', 'idempotency_key'];

    protected $casts = [
        'payload' => 'array', 'attempts' => 'integer', 'available_at' => 'immutable_datetime',
        'reserved_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime',
    ];
}
