<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    public const TYPE_CHARGE = 'charge';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_EXPENSE = 'expense';

    protected $fillable = [
        'invoice_id',
        'shoot_id',
        'type',
        'description',
        'quantity',
        'unit_amount',
        'total_amount',
        'recorded_at',
        'meta',
    ];

    protected $casts = [
        'unit_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'recorded_at' => 'datetime',
        'meta' => 'array',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function isCharge(): bool
    {
        return $this->type === self::TYPE_CHARGE;
    }

    public function isPayment(): bool
    {
        return $this->type === self::TYPE_PAYMENT;
    }

    public function isExpense(): bool
    {
        return $this->type === self::TYPE_EXPENSE;
    }
}
