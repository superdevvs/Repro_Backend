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
}