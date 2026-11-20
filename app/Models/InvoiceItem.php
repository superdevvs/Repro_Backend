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
        'shoot_compensation_id',
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

    public function shootCompensation()
    {
        return $this->belongsTo(ShootCompensation::class);
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

    /**
     * Client name looked up from the line's shoot for lines generated before
     * payout lines stored meta.client_name. Never persisted.
     */
    protected ?string $derivedClientName = null;

    public function setDerivedClientName(?string $name): self
    {
        $name = trim((string) $name);
        $this->derivedClientName = $name !== '' ? $name : null;

        return $this;
    }

    /**
     * The shoot's client for a payout line: the name stored when the line was
     * generated, otherwise the shoot's current client.
     */
    public function getClientNameAttribute(): ?string
    {
        $stored = trim((string) data_get($this->meta, 'client_name', ''));

        return $stored !== '' ? $stored : $this->derivedClientName;
    }
}
