<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioQuote extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['source_snapshot', 'request_hash'];

    protected $casts = [
        'source_snapshot' => 'array', 'options' => 'array', 'pricing_snapshot' => 'array',
        'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime',
    ];
}
