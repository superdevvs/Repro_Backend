<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ListingStudioOrder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['payment_reference', 'payment_evidence', 'idempotency_key', 'request_hash'];

    protected $casts = [
        'pricing_snapshot' => 'array', 'payment_evidence' => 'array', 'total_cents' => 'integer',
        'paid_amount_cents' => 'integer', 'refunded_amount_cents' => 'integer',
    ];

    public function item(): HasOne
    {
        return $this->hasOne(ListingStudioOrderItem::class, 'order_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ListingStudioQuote::class, 'quote_id');
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(ListingStudioCreditReservation::class, 'order_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
