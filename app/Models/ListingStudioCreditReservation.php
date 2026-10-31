<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingStudioCreditReservation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['allocations'];

    protected $casts = [
        'allocations' => 'array', 'amount_cents' => 'integer',
        'period_start' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
        'captured_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ListingStudioOrder::class, 'order_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ListingStudioSubscription::class, 'subscription_id');
    }
}
