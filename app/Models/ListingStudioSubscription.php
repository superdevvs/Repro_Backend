<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ListingStudioSubscription extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'livemode' => 'boolean', 'cancel_at_period_end' => 'boolean', 'account_created' => 'boolean',
        'current_period_start' => 'datetime', 'current_period_end' => 'datetime',
        'canceled_at' => 'datetime', 'last_paid_at' => 'datetime',
        'amount_cents' => 'integer', 'billing_interval_count' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function accountSetup(): HasOne
    {
        return $this->hasOne(ListingStudioAccountSetup::class, 'client_id', 'client_id');
    }
}
