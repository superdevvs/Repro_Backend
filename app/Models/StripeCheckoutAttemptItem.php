<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeCheckoutAttemptItem extends Model
{
    protected $fillable = [
        'stripe_checkout_attempt_id',
        'shoot_id',
        'position',
        'expected_amount_cents',
        'allocation_payload',
    ];

    protected $casts = [
        'position' => 'integer',
        'expected_amount_cents' => 'integer',
        'allocation_payload' => 'array',
    ];

    public function attempt()
    {
        return $this->belongsTo(StripeCheckoutAttempt::class, 'stripe_checkout_attempt_id');
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }
}
