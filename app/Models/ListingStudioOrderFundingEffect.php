<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Economic settlement dedupe, separate from Stripe delivery events/attempts. */
class ListingStudioOrderFundingEffect extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['external_key', 'evidence'];

    protected $casts = ['evidence' => 'array', 'amount_cents' => 'integer', 'occurred_at' => 'immutable_datetime'];
}
