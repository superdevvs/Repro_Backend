<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootCompensation extends Model
{
    use HasFactory;

    protected $table = 'shoot_compensations';

    public const RECIPIENT_PHOTOGRAPHER = 'photographer';

    public const RECIPIENT_SALES_REP = 'sales_rep';

    public const RECIPIENT_TYPES = [
        self::RECIPIENT_PHOTOGRAPHER,
        self::RECIPIENT_SALES_REP,
    ];

    public const MODE_NONE = 'none';

    public const MODE_STANDARD = 'standard';

    public const MODE_CUSTOM = 'custom';

    public const MODES = [
        self::MODE_NONE,
        self::MODE_STANDARD,
        self::MODE_CUSTOM,
    ];

    public const CALCULATION_FIXED = 'fixed';

    public const CALCULATION_PERCENTAGE = 'percentage';

    public const LINE_TYPE_BASE = 'base';

    public const LINE_TYPE_ADJUSTMENT = 'adjustment';

    public const LINE_TYPE_REVERSAL = 'reversal';

    public const LINE_TYPES = [
        self::LINE_TYPE_BASE,
        self::LINE_TYPE_ADJUSTMENT,
        self::LINE_TYPE_REVERSAL,
    ];

    public const STATUS_VOIDED = 'voided';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_INVOICED = 'invoiced';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'shoot_id',
        'shoot_service_id',
        'scope_key',
        'line_type',
        'adjusts_compensation_id',
        'recipient_type',
        'recipient_user_id',
        'mode',
        'suggested_mode',
        'calculation_method',
        'standard_calculation_method',
        'quantity_snapshot',
        'basis_amount_snapshot',
        'rate_snapshot',
        'standard_rate_snapshot',
        'amount',
        'suggested_amount',
        'standard_amount_snapshot',
        'currency',
        'reason_code',
        'policy_version',
        'metadata',
        'earned_at',
        'locked_at',
        'voided_at',
        'voided_by',
        'void_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity_snapshot' => 'integer',
        'basis_amount_snapshot' => 'decimal:2',
        'rate_snapshot' => 'decimal:4',
        'standard_rate_snapshot' => 'decimal:4',
        'amount' => 'decimal:2',
        'suggested_amount' => 'decimal:2',
        'standard_amount_snapshot' => 'decimal:2',
        'metadata' => 'array',
        'earned_at' => 'datetime',
        'locked_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public static function serviceScopeKey(int $shootServiceId): string
    {
        return 'service:'.$shootServiceId;
    }

    public static function shootScopeKey(): string
    {
        return 'shoot';
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function serviceItem()
    {
        return $this->belongsTo(ShootService::class, 'shoot_service_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function invoiceItem()
    {
        return $this->hasOne(InvoiceItem::class);
    }

    public function adjustedCompensation()
    {
        return $this->belongsTo(self::class, 'adjusts_compensation_id');
    }

    public function adjustmentLines()
    {
        return $this->hasMany(self::class, 'adjusts_compensation_id');
    }

    public function getPayoutStatusAttribute(): string
    {
        if ($this->voided_at) {
            return self::STATUS_VOIDED;
        }

        if ($this->mode === self::MODE_NONE) {
            return self::STATUS_NOT_APPLICABLE;
        }

        if (! $this->earned_at) {
            return self::STATUS_PENDING;
        }

        $item = $this->relationLoaded('invoiceItem')
            ? $this->invoiceItem
            : $this->invoiceItem()->with('invoice')->first();

        if (! $item) {
            return self::STATUS_ELIGIBLE;
        }

        $invoice = $item->relationLoaded('invoice') ? $item->invoice : $item->invoice()->first();
        if (! $invoice) {
            return self::STATUS_ELIGIBLE;
        }

        if ($invoice->status === Invoice::STATUS_PAID || $invoice->paid_at) {
            return self::STATUS_PAID;
        }

        if ($invoice->isAccountsApproved()) {
            return self::STATUS_APPROVED;
        }

        if ($invoice->approval_status === Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
            return self::STATUS_IN_REVIEW;
        }

        if ($invoice->approval_status === Invoice::APPROVAL_STATUS_REJECTED) {
            return self::STATUS_RETURNED;
        }

        return self::STATUS_INVOICED;
    }

    public function isSettlementLocked(): bool
    {
        $item = $this->relationLoaded('invoiceItem')
            ? $this->invoiceItem
            : $this->invoiceItem()->with('invoice')->first();
        $invoice = $item?->relationLoaded('invoice') ? $item->invoice : $item?->invoice()->first();

        return $invoice !== null
            && ($invoice->isAccountsApproved() || $invoice->status === Invoice::STATUS_PAID || $invoice->paid_at);
    }
}
