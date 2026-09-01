<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootService extends Model
{
    use HasFactory;

    protected $table = 'shoot_service';

    public const WORKFLOW_PENDING = 'pending';
    public const WORKFLOW_SCHEDULED = 'scheduled';
    public const WORKFLOW_IN_PROGRESS = 'in_progress';
    public const WORKFLOW_READY = 'ready';
    public const WORKFLOW_DELIVERED = 'delivered';
    public const WORKFLOW_CANCELLED = 'cancelled';

    public const DELIVERY_NOT_STARTED = 'not_started';
    public const DELIVERY_READY = 'ready';
    public const DELIVERY_DELIVERED = 'delivered';
    public const DELIVERY_CANCELLED = 'cancelled';

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PARTIALLY_PAID = 'partially_paid';
    public const PAYMENT_PAID = 'paid';

    protected $fillable = [
        'shoot_id',
        'service_id',
        'photographer_id',
        'bracket_mode',
        'editor_id',
        'price',
        'nominal_value_snapshot',
        'quantity',
        'photographer_pay',
        'editing_completed_at',
        'scheduled_at',
        'workflow_status',
        'delivery_status',
        'ready_at',
        'delivered_at',
        'cancelled_at',
        'is_deliverable',
        'force_unlock_delivery',
        'unlock_reason',
        'unlocked_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'nominal_value_snapshot' => 'decimal:2',
        'quantity' => 'integer',
        'bracket_mode' => 'integer',
        'photographer_pay' => 'decimal:2',
        'editing_completed_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'ready_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'is_deliverable' => 'boolean',
        'force_unlock_delivery' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ShootService $serviceItem) {
            $shoot = $serviceItem->relationLoaded('shoot')
                ? $serviceItem->shoot
                : $serviceItem->shoot()->first();

            if ($shoot?->isComplimentaryReshoot()) {
                $serviceItem->price = 0;
            }
        });
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function photographer()
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function unlockedBy()
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    public function paymentAllocations()
    {
        return $this->hasMany(PaymentServiceAllocation::class, 'shoot_service_id');
    }

    public function compReshootItem()
    {
        return $this->hasOne(CompReshootItem::class, 'shoot_service_id');
    }

    public function sourceForCompReshootItems()
    {
        return $this->hasMany(CompReshootItem::class, 'source_shoot_service_id');
    }

    public function compensations()
    {
        return $this->hasMany(ShootCompensation::class, 'shoot_service_id');
    }

    public function completedPaymentAllocations()
    {
        return $this->paymentAllocations()
            ->whereHas('payment', fn ($query) => $query->where('status', Payment::STATUS_COMPLETED));
    }

    public function files()
    {
        return $this->hasMany(ShootFile::class, 'shoot_service_id');
    }

    public function getSubtotalAttribute(): float
    {
        return round((float) ($this->price ?? 0) * max((int) ($this->quantity ?? 1), 1), 2);
    }

    public function getPaidAmountAttribute(): float
    {
        return round((float) $this->completedPaymentAllocations()->sum('amount'), 2);
    }

    public function getBalanceDueAttribute(): float
    {
        return max(round($this->subtotal - $this->paid_amount, 2), 0);
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->paid_amount <= 0) {
            return self::PAYMENT_UNPAID;
        }

        return $this->paid_amount + 0.01 >= $this->subtotal
            ? self::PAYMENT_PAID
            : self::PAYMENT_PARTIALLY_PAID;
    }

    public function getIsUnlockedForDeliveryAttribute(): bool
    {
        if ($this->force_unlock_delivery) {
            return true;
        }

        $shoot = $this->relationLoaded('shoot') ? $this->shoot : $this->shoot()->first();

        if ($shoot?->bypass_paywall || $shoot?->payment_status === 'paid') {
            return true;
        }

        return $this->payment_status === self::PAYMENT_PAID;
    }
}
