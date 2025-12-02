<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Invoice extends Model
{
    use HasFactory;
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PAID = 'paid';

    public const ROLE_CLIENT = 'client';
    public const ROLE_PHOTOGRAPHER = 'photographer';

    // Approval status constants
    public const APPROVAL_STATUS_PENDING = 'pending';
    public const APPROVAL_STATUS_APPROVED = 'approved';
    public const APPROVAL_STATUS_REJECTED = 'rejected';
    public const APPROVAL_STATUS_PENDING_APPROVAL = 'pending_approval';

    protected $fillable = [
        'photographer_id',
        'sales_rep_id',
        'billing_period_start',
        'billing_period_end',
        'total_amount',
        'amount_paid',
        'is_sent',
        'is_paid',
        'shoot_id',
        'client_id',
        'invoice_number',
        'issue_date',
        'due_date',
        'subtotal',
        'tax',
        'total',
        'status',
        'notes',
        'paid_at',
        'approval_status',
        'rejection_reason',
        'rejected_by',
        'rejected_at',
        'approved_by',
        'approved_at',
        'modified_by',
        'modified_at',
        'modification_notes',
    ];

    protected $casts = [
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'is_sent' => 'boolean',
        'is_paid' => 'boolean',
        'paid_at' => 'datetime',
        'rejected_at' => 'datetime',
        'approved_at' => 'datetime',
        'modified_at' => 'datetime',
    ];

    protected $attributes = [
        'approval_status' => 'pending',
        'status' => 'draft',
    ];

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function shoots(): BelongsToMany
    {
        return $this->belongsToMany(Shoot::class, 'invoice_shoot')->withTimestamps();
    }

    public function markAsPaid(?string $paidAt = null, ?float $amountPaid = null): void
    {
        $this->forceFill([
            'is_paid' => true,
            'paid_at' => $paidAt ? Carbon::parse($paidAt) : now(),
            'amount_paid' => $amountPaid ?? $this->total_amount,
        ])->save();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function scopeForRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function refreshTotals(): void
    {
        $items = $this->items()->get();

        $charges = $items->where('type', InvoiceItem::TYPE_CHARGE)->sum('total_amount');
        $expenses = $items->where('type', InvoiceItem::TYPE_EXPENSE)->sum('total_amount');
        $payments = $items->where('type', InvoiceItem::TYPE_PAYMENT)->sum('total_amount');

        $totalCharges = $charges + $expenses;

        // Update fields if they exist in the schema
        if ($this->getConnection()->getSchemaBuilder()->hasColumn($this->getTable(), 'charges_total')) {
            $this->charges_total = $totalCharges;
        }
        if ($this->getConnection()->getSchemaBuilder()->hasColumn($this->getTable(), 'payments_total')) {
            $this->payments_total = $payments;
        }
        if ($this->getConnection()->getSchemaBuilder()->hasColumn($this->getTable(), 'balance_due')) {
            $this->balance_due = $totalCharges - $payments;
        }

        // Update total_amount if it exists
        if ($this->getConnection()->getSchemaBuilder()->hasColumn($this->getTable(), 'total_amount')) {
            $this->total_amount = $totalCharges;
        }
        if ($this->getConnection()->getSchemaBuilder()->hasColumn($this->getTable(), 'subtotal')) {
            $this->subtotal = $totalCharges;
        }
        if ($this->getConnection()->getSchemaBuilder()->hasColumn($this->getTable(), 'total')) {
            $this->total = $totalCharges + ($this->tax ?? 0);
        }

        $this->save();
    }

    public function markSent(?Carbon $sentAt = null): void
    {
        $this->status = self::STATUS_SENT;
        $this->sent_at = $sentAt ?? now();
        $this->save();
    }

    public function markPaid(?Carbon $paidAt = null): void
    {
        $this->status = self::STATUS_PAID;
        $this->paid_at = $paidAt ?? now();
        $this->balance_due = 0;
        $this->save();
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function modifiedBy()
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    /**
     * Check if invoice can be modified by photographer
     */
    public function canBeModifiedByPhotographer(): bool
    {
        return in_array($this->approval_status, [
            self::APPROVAL_STATUS_PENDING,
            self::APPROVAL_STATUS_REJECTED,
        ]) && $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if invoice requires admin approval
     */
    public function requiresApproval(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_PENDING_APPROVAL;
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function totalPaid(): float
    {
        if ($this->getAttribute('total_paid_amount') !== null) {
            return (float) $this->getAttribute('total_paid_amount');
        }

        if ($this->relationLoaded('payments')) {
            return (float) $this->payments
                ->where('status', Payment::STATUS_COMPLETED)
                ->sum('amount');
        }

        return (float) $this->payments()
            ->where('status', Payment::STATUS_COMPLETED)
            ->sum('amount');
    }

    public function balanceDue(): float
    {
        return max((float) $this->total - $this->totalPaid(), 0);
    }

    public function isPastDue(): bool
    {
        $dueDate = $this->due_date instanceof Carbon ? $this->due_date : ($this->due_date ? Carbon::parse($this->due_date) : null);

        return $dueDate !== null
            && $dueDate->isPast()
            && $this->balanceDue() > 0;
    }
}
