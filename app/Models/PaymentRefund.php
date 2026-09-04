<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single refund against a payment.
 *
 * Amounts are always positive; the sign is implied by the table. A payment may
 * have several refunds, and their sum must never exceed the payment amount —
 * enforced by the refund endpoints, which cap at the unrefunded remainder.
 */
class PaymentRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'shoot_id',
        'amount',
        'provider',
        'provider_refund_id',
        'operation_key',
        'status',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
