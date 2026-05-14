<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory;
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PAID = 'paid';

    public const ROLE_CLIENT = 'client';
    public const ROLE_PHOTOGRAPHER = 'photographer';
    public const ROLE_SALES_REP = 'salesRep';

    // Approval status constants
    public const APPROVAL_STATUS_PENDING = 'pending';
    public const APPROVAL_STATUS_APPROVED = 'accounts_approved';
    public const APPROVAL_STATUS_LEGACY_APPROVED = 'approved';
    public const APPROVAL_STATUS_REJECTED = 'rejected';
    public const APPROVAL_STATUS_PENDING_APPROVAL = 'pending_approval';

    protected $fillable = [
        'user_id',
        'role',
        'period_start',
        'period_end',
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
        'payment_method',
        'payment_details',
        'approval_status',
        'rejection_reason',
        'rejected_by',
        'rejected_at',
        'approved_by',
        'approved_at',
        'modified_by',
        'modified_at',
        'modification_notes',
        'approval_snapshot',
        'unresolved_warnings',
        'warning_override_reason',
        'warning_override_by',
        'warning_override_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'issue_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'is_sent' => 'boolean',
        'is_paid' => 'boolean',
        'paid_at' => 'datetime',
        'payment_details' => 'array',
        'approval_snapshot' => 'array',
        'unresolved_warnings' => 'array',
        'rejected_at' => 'datetime',
        'approved_at' => 'datetime',
        'modified_at' => 'datetime',
        'warning_override_at' => 'datetime',
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

    public function warningOverrideBy()
    {
        return $this->belongsTo(User::class, 'warning_override_by');
    }

    public function auditEvents()
    {
        return $this->hasMany(InvoiceAuditEvent::class)->latest();
    }

    /**
     * Check if invoice can be modified by photographer.
     *
     * Approval status is the source of truth for the photographer payout lifecycle:
     * a pending or rejected weekly invoice may still be edited by the photographer
     * even if the underlying record happens to be marked sent (e.g. legacy weekly
     * invoices). We only block once the invoice has actually been paid out, or once
     * accounts has approved/locked it via approval_status.
     */
    public function canBeModifiedByPhotographer(): bool
    {
        return in_array($this->approval_status, [
            self::APPROVAL_STATUS_PENDING,
            self::APPROVAL_STATUS_REJECTED,
        ]) && $this->status !== self::STATUS_PAID;
    }

    /**
     * Check if invoice requires admin approval
     */
    public function requiresApproval(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_PENDING_APPROVAL;
    }

    public function isAccountsApproved(): bool
    {
        return in_array($this->approval_status, [
            self::APPROVAL_STATUS_APPROVED,
            self::APPROVAL_STATUS_LEGACY_APPROVED,
        ], true);
    }

    public function hasBlockingWarnings(): bool
    {
        return !empty($this->unresolved_warnings);
    }

    public function recordAuditEvent(string $event, ?User $actor = null, ?string $summary = null, array $metadata = []): InvoiceAuditEvent
    {
        return $this->auditEvents()->create([
            'actor_id' => $actor?->id,
            'event' => $event,
            'summary' => $summary,
            'metadata' => $metadata ?: null,
        ]);
    }

    public function buildApprovalSnapshot(): array
    {
        $this->loadMissing(['items', 'shoots']);

        $items = $this->items->map(fn (InvoiceItem $item) => [
            'id' => $item->id,
            'shoot_id' => $item->shoot_id,
            'type' => $item->type,
            'description' => $item->description,
            'quantity' => (int) $item->quantity,
            'unit_amount' => round((float) $item->unit_amount, 2),
            'total_amount' => round((float) $item->total_amount, 2),
            'meta' => $item->meta,
        ])->values()->all();

        $commissionableGross = collect($items)->sum(fn (array $item) => (float) data_get($item, 'meta.commissionable_gross', 0));
        $excludedFees = collect($items)->sum(fn (array $item) => (float) data_get($item, 'meta.excluded_fees_total', 0));
        $commissionRate = collect($items)
            ->map(fn (array $item) => data_get($item, 'meta.commission_rate'))
            ->filter(fn ($value) => $value !== null)
            ->first();

        return [
            'invoice_id' => $this->id,
            'role' => $this->role,
            'billing_period_start' => optional($this->billing_period_start)->toDateString(),
            'billing_period_end' => optional($this->billing_period_end)->toDateString(),
            'total_amount' => round((float) $this->total_amount, 2),
            'amount_paid' => round((float) $this->amount_paid, 2),
            'commissionable_gross' => round($commissionableGross, 2),
            'excluded_fees_total' => round($excludedFees, 2),
            'commission_rate' => $commissionRate !== null ? (float) $commissionRate : null,
            'shoot_ids' => $this->shoots->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'items' => $items,
            'warnings' => $this->unresolved_warnings ?? [],
            'snapshot_at' => now()->toISOString(),
        ];
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function latestCompletedPayment(): ?Payment
    {
        return $this->relatedCompletedPayments()
            ->sortByDesc(fn (Payment $payment) => optional($payment->processed_at)->timestamp ?? 0)
            ->first();
    }

    public function resolvePaymentMetadata(): array
    {
        $method = $this->payment_method;
        $details = is_array($this->payment_details) ? $this->payment_details : [];
        $paidAt = $this->paid_at;
        $completedPayments = $this->relatedCompletedPayments();
        $latestPayment = $completedPayments
            ->sortByDesc(fn (Payment $payment) => optional($payment->processed_at)->timestamp ?? 0)
            ->first();
        $paymentBreakdown = $this->buildPaymentBreakdown($completedPayments);

        if ($paymentBreakdown !== []) {
            $details['payment_breakdown'] = $paymentBreakdown;
        }

        $resolvedMethod = $method ?: $latestPayment?->payment_method;
        if (count($paymentBreakdown) > 1) {
            $resolvedMethod = 'mixed';
        } elseif (count($paymentBreakdown) === 1) {
            $resolvedMethod = $paymentBreakdown[0]['method'] ?? $resolvedMethod;
        }

        return [
            'payment_method' => $resolvedMethod,
            'payment_details' => $details !== [] ? $details : ($latestPayment?->payment_details ?? null),
            'paid_at' => $paidAt ?: $latestPayment?->processed_at,
        ];
    }

    public function applyResolvedPaymentMetadata(): static
    {
        $resolved = $this->resolvePaymentMetadata();

        if (!empty($resolved['payment_method'])) {
            $this->setAttribute('payment_method', $resolved['payment_method']);
        }

        if (array_key_exists('payment_details', $resolved) && $resolved['payment_details'] !== null) {
            $this->setAttribute('payment_details', $resolved['payment_details']);
        }

        if (!empty($resolved['paid_at'])) {
            $this->setAttribute('paid_at', $resolved['paid_at']);
        }

        return $this;
    }

    public function totalPaid(): float
    {
        if ($this->getAttribute('total_paid_amount') !== null) {
            return (float) $this->getAttribute('total_paid_amount');
        }

        return (float) $this->relatedCompletedPayments()
            ->sum(fn (Payment $payment) => (float) $payment->amount);
    }

    public function balanceDue(): float
    {
        $total = $this->total ?? $this->total_amount ?? $this->charges_total ?? 0;
        $paid = $this->totalPaid();

        if ($paid <= 0 && $this->getAttribute('amount_paid') !== null) {
            $paid = (float) $this->getAttribute('amount_paid');
        }

        if ($paid <= 0 && $this->getAttribute('payments_total') !== null) {
            $paid = (float) $this->getAttribute('payments_total');
        }

        return max((float) $total - (float) $paid, 0);
    }

    public function isPastDue(): bool
    {
        $dueDate = $this->due_date instanceof Carbon ? $this->due_date : ($this->due_date ? Carbon::parse($this->due_date) : null);

        return $dueDate !== null
            && $dueDate->isPast()
            && $this->balanceDue() > 0;
    }

    private function relatedCompletedPayments(): Collection
    {
        $payments = collect();

        if ($this->relationLoaded('payments')) {
            $payments = $payments->merge($this->payments);
        } else {
            $payments = $payments->merge(
                $this->payments()
                    ->where('status', Payment::STATUS_COMPLETED)
                    ->get()
            );
        }

        $shoots = collect();

        if ($this->relationLoaded('shoot') && $this->shoot) {
            $shoots->push($this->shoot);
        } elseif ($this->shoot_id) {
            $shoot = $this->shoot()->first();
            if ($shoot) {
                $shoots->push($shoot);
            }
        }

        if ($this->relationLoaded('shoots')) {
            $shoots = $shoots->merge($this->shoots);
        } elseif ($this->exists) {
            $shoots = $shoots->merge($this->shoots()->get());
        }

        $shootPayments = $shoots
            ->filter()
            ->flatMap(function ($shoot) {
                if ($shoot->relationLoaded('payments')) {
                    return $shoot->payments;
                }

                return $shoot->payments()
                    ->where('status', Payment::STATUS_COMPLETED)
                    ->get();
            });

        return $payments
            ->merge($shootPayments)
            ->filter(fn ($payment) => $payment instanceof Payment && $payment->status === Payment::STATUS_COMPLETED)
            ->unique(fn (Payment $payment) => $this->paymentDeduplicationKey($payment))
            ->values();
    }

    private function buildPaymentBreakdown(Collection $payments): array
    {
        return $payments
            ->groupBy(fn (Payment $payment) => $this->normalizePaymentMethodKey($payment->payment_method))
            ->map(function (Collection $groupedPayments, string $method) {
                $latestPayment = $groupedPayments
                    ->sortByDesc(fn (Payment $payment) => optional($payment->processed_at)->timestamp ?? 0)
                    ->first();

                return [
                    'method' => $method,
                    'amount' => round((float) $groupedPayments->sum('amount'), 2),
                    'details' => is_array($latestPayment?->payment_details) ? $latestPayment->payment_details : null,
                    'paid_at' => $latestPayment?->processed_at instanceof Carbon
                        ? $latestPayment->processed_at->toIso8601String()
                        : null,
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    private function normalizePaymentMethodKey(?string $method): string
    {
        $normalized = Str::of((string) $method)
            ->trim()
            ->lower()
            ->replace('-', '_')
            ->replace(' ', '_')
            ->value();

        return match ($normalized) {
            'manual' => 'other',
            'bank_transfer' => 'ach',
            'cheque' => 'check',
            '', null => 'other',
            default => $normalized,
        };
    }

    private function paymentDeduplicationKey(Payment $payment): string
    {
        if (!empty($payment->stripe_session_id)) {
            return 'stripe_session:' . $payment->stripe_session_id;
        }

        if (!empty($payment->stripe_payment_id)) {
            return 'stripe_payment:' . $payment->stripe_payment_id;
        }

        if (!empty($payment->square_payment_id)) {
            return 'square_payment:' . $payment->square_payment_id;
        }

        return 'payment:' . (string) ($payment->id ?? spl_object_id($payment));
    }
}
