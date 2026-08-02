<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'invoice_id',
        'amount',
        'currency',
        'payment_method',
        'payment_details',
        'square_payment_id',
        'square_order_id',
        'stripe_payment_id',
        'stripe_session_id',
        'status',
        'processed_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'payment_details' => 'array',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function serviceAllocations()
    {
        return $this->hasMany(PaymentServiceAllocation::class);
    }

    public function refunds()
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * Total refunded against this payment.
     *
     * Uses the loaded relation when available so callers that eager-load
     * `payments.refunds` do not trigger a query per payment.
     */
    public function refundedAmount(): float
    {
        $refunds = $this->relationLoaded('refunds')
            ? $this->refunds
            : $this->refunds()->get();

        return round((float) $refunds->sum(fn (PaymentRefund $refund) => (float) $refund->amount), 2);
    }

    /**
     * What this payment still contributes to the amount paid.
     *
     * Floored at zero: a data error that over-refunds must not produce a
     * negative contribution that silently reduces other payments.
     */
    public function netAmount(): float
    {
        return round(max((float) $this->amount - $this->refundedAmount(), 0), 2);
    }

    /** The most that may still be refunded against this payment. */
    public function refundableRemainder(): float
    {
        return $this->netAmount();
    }

    /** True once the whole payment has been returned. */
    public function isFullyRefunded(): bool
    {
        return $this->refundedAmount() + 0.01 >= (float) $this->amount;
    }
}
