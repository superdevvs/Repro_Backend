<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\User;
use App\Services\Invoices\InvoiceAdjustmentService;
use App\Services\Messaging\AutomationService;
use App\Support\ReportingWeek;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class InvoiceService
{
    private const SALES_REP_ROLES = ['salesRep', 'sales_rep', 'salesrep'];

    private const DEFAULT_SALES_COMMISSION_RATE = 15.0;

    protected $mailService;

    public function __construct(?MailService $mailService = null)
    {
        $this->mailService = $mailService ?? app(MailService::class);
    }

    /**
     * Generate invoices for the provided billing period.
     *
     * REFACTORED: Now uses SERVICE-LEVEL grouping for multi-photographer support
     * Fallback: shoot_service.photographer_id ?? shoot.photographer_id
     */
    public function generateForPeriod(Carbon $start, Carbon $end, bool $sendEmails = false): Collection
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        $shoots = Shoot::with([
            'photographer',
            'service',
            'services' => function ($q) {
                $q->withPivot(['photographer_id', 'photographer_pay', 'quantity']);
            },
        ])
            ->where(function ($query) {
                $query->whereNull('shoot_type')
                    ->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT);
            })
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('completed_at', [$start, $end])
                    ->orWhere(function ($innerQuery) use ($start, $end) {
                        $innerQuery->whereNull('completed_at')
                            ->whereBetween('admin_verified_at', [$start, $end]);
                    });
            })
            ->whereIn('workflow_status', [
                Shoot::WORKFLOW_COMPLETED,
                Shoot::WORKFLOW_ADMIN_VERIFIED,
            ])
            ->get();

        // Flatten to service-level rows with resolved photographer
        $serviceRows = collect();
        foreach ($shoots as $shoot) {
            $fallbackId = $shoot->photographer_id;
            $services = $shoot->services;

            if ($services->isEmpty() && $shoot->service) {
                $propertySqft = $this->extractShootSqft($shoot);
                $defaultPay = method_exists($shoot->service, 'getPhotographerPayForSqft')
                    ? $shoot->service->getPhotographerPayForSqft($propertySqft)
                    : null;

                $serviceRows->push([
                    'shoot_id' => $shoot->id,
                    'shoot' => $shoot,
                    'service_id' => $shoot->service->id,
                    'service_name' => $shoot->service->name ?? 'Service',
                    'resolved_photographer_id' => $fallbackId,
                    'photographer_pay' => (float) ($defaultPay ?? $shoot->service->photographer_pay ?? 0),
                    'scheduled_date' => $shoot->scheduled_date,
                    'address' => $shoot->address ?? 'Location TBD',
                ]);

                continue;
            }

            foreach ($services as $service) {
                $resolvedId = $service->pivot->photographer_id ?? $fallbackId;
                if (! $resolvedId) {
                    \Log::warning('Unresolved photographer for invoice', [
                        'shoot_id' => $shoot->id,
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                    ]);

                    continue;
                }

                // Precedence: an explicit per-shoot override always wins, then the
                // service's own configuration. Going through
                // getPhotographerPayForSqft() (rather than reading
                // photographer_pay directly) is what lets a service configured as
                // a percentage of its price resolve correctly here.
                $pivotPay = $service->pivot->photographer_pay ?? null;
                if ($pivotPay !== null && $pivotPay !== '') {
                    $pay = (float) $pivotPay;
                } else {
                    $resolvedPay = method_exists($service, 'getPhotographerPayForSqft')
                        ? $service->getPhotographerPayForSqft($this->extractShootSqft($shoot))
                        : null;
                    $pay = (float) ($resolvedPay ?? $service->photographer_pay ?? 0);
                }
                $qty = (int) ($service->pivot->quantity ?? 1);

                $serviceRows->push([
                    'shoot_id' => $shoot->id,
                    'shoot' => $shoot,
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'resolved_photographer_id' => $resolvedId,
                    'photographer_pay' => $pay * $qty,
                    'scheduled_date' => $shoot->scheduled_date,
                    'address' => $shoot->address ?? 'Location TBD',
                ]);
            }
        }

        // An explicit zero on a standard service line means the photographer is
        // not being paid for that line (for example, a paid return visit where
        // Admin left Photographer off). Do not create empty payout invoices or
        // $0 line items for those decisions.
        $serviceRows = $serviceRows
            ->filter(fn (array $row) => abs((float) ($row['photographer_pay'] ?? 0)) >= 0.005)
            ->values();

        return DB::transaction(function () use ($serviceRows, $start, $end, $sendEmails) {
            $this->acquirePayoutGenerationLock(Invoice::ROLE_PHOTOGRAPHER, $start, $end);

            // Eligible compensation is selected under the same transaction and
            // deterministic period lock that creates invoice items. A second
            // worker therefore replays against the committed linkage instead of
            // consuming a stale pre-lock collection.
            $compensations = ShootCompensation::query()
                ->with([
                    'shoot',
                    'serviceItem.service',
                    'serviceItem.compReshootItem',
                    'invoiceItem.invoice',
                ])
                ->where('recipient_type', ShootCompensation::RECIPIENT_PHOTOGRAPHER)
                ->where('mode', '!=', ShootCompensation::MODE_NONE)
                ->whereNull('voided_at')
                ->whereNotNull('recipient_user_id')
                ->where('amount', '!=', 0)
                ->whereBetween('earned_at', [$start, $end])
                ->lockForUpdate()
                ->get()
                ->filter(fn (ShootCompensation $compensation) => $this->compensationCanBeGeneratedForPeriod(
                    $compensation,
                    Invoice::ROLE_PHOTOGRAPHER,
                    $start,
                    $end,
                ));

            foreach ($compensations as $compensation) {
                $shoot = $compensation->shoot;
                if (! $shoot || ! $shoot->isComplimentaryReshoot()) {
                    continue;
                }

                $serviceItem = $compensation->serviceItem;
                $reshootItem = $serviceItem?->compReshootItem;
                $serviceName = $reshootItem?->service_name_snapshot
                    ?? $serviceItem?->service?->name
                    ?? 'Service';

                $serviceRows->push([
                    'shoot_id' => $shoot->id,
                    'shoot' => $shoot,
                    'service_id' => $reshootItem?->service_id_snapshot ?? $serviceItem?->service_id,
                    'service_name' => $serviceName,
                    'resolved_photographer_id' => $compensation->recipient_user_id,
                    'photographer_pay' => (float) $compensation->amount,
                    'scheduled_date' => $compensation->earned_at,
                    'address' => $shoot->address ?? 'Location TBD',
                    'shoot_compensation_id' => $compensation->id,
                    'compensation_mode' => $compensation->mode,
                    'compensation_line_type' => $compensation->line_type,
                    'adjusts_compensation_id' => $compensation->adjusts_compensation_id,
                    'payout_invoice_id' => $compensation->invoiceItem?->invoice_id,
                    'basis_amount_snapshot' => $compensation->basis_amount_snapshot,
                    'rate_snapshot' => $compensation->rate_snapshot,
                ]);
            }

            if ($serviceRows->isEmpty()) {
                return collect();
            }

            // Group by resolved photographer after locking/re-reading all
            // compensation rows so recipients cannot change underneath us.
            $grouped = $serviceRows->groupBy('resolved_photographer_id');
            $invoices = collect();

            foreach ($grouped as $photographerId => $photographerServices) {
                $periodInvoices = Invoice::where('photographer_id', $photographerId)
                    ->where('role', Invoice::ROLE_PHOTOGRAPHER)
                    ->whereDate('billing_period_start', $start->toDateString())
                    ->whereDate('billing_period_end', $end->toDateString())
                    ->lockForUpdate()
                    ->get();
                $existingInvoice = $periodInvoices->first(
                    fn (Invoice $invoice) => ! $invoice->isAccountsApproved()
                        && $invoice->status !== Invoice::STATUS_PAID
                        && ! $invoice->paid_at
                );
                $hasSettledInvoice = $periodInvoices->contains(
                    fn (Invoice $invoice) => $invoice->isAccountsApproved()
                        || $invoice->status === Invoice::STATUS_PAID
                        || $invoice->paid_at
                );

                if ($hasSettledInvoice) {
                    // Never rebuild work already frozen on an approved/paid
                    // invoice. A newly earned correction/reversal becomes a
                    // supplemental draft for the same earning period.
                    $photographerServices = $photographerServices
                        ->filter(function (array $row) use ($existingInvoice) {
                            $compensationId = $row['shoot_compensation_id'] ?? null;
                            if (! $compensationId) {
                                return false;
                            }

                            $invoiceId = $row['payout_invoice_id'] ?? null;

                            return $invoiceId === null
                                || ($existingInvoice && (int) $invoiceId === (int) $existingInvoice->id);
                        })
                        ->values();

                    if ($photographerServices->isEmpty()) {
                        $invoices->push($periodInvoices->sortByDesc('id')->first());

                        continue;
                    }
                }

                $shootIds = $photographerServices->pluck('shoot_id')->unique();
                $totalAmount = $photographerServices->sum('photographer_pay');
                // Client Payment rows belong to the client receivables ledger.
                // A payout invoice starts unpaid and is settled only through
                // the staff payout approval/payment lifecycle.
                $amountPaid = 0.0;

                if ($existingInvoice) {
                    $before = [
                        'total_amount' => round((float) $existingInvoice->total_amount, 2),
                        'unresolved_warnings' => $existingInvoice->unresolved_warnings ?? [],
                    ];
                    $existingInvoice->items()
                        ->where('type', InvoiceItem::TYPE_CHARGE)
                        ->delete();

                    foreach ($photographerServices as $serviceRow) {
                        $existingInvoice->items()->create(
                            $this->photographerPayoutItemPayload($serviceRow)
                        );
                    }

                    $existingInvoice->update([
                        'total_amount' => $totalAmount,
                        'amount_paid' => $amountPaid,
                        'is_paid' => $totalAmount > 0 ? $amountPaid >= $totalAmount : false,
                        'warning_override_reason' => null,
                        'warning_override_by' => null,
                        'warning_override_at' => null,
                    ]);
                    $existingInvoice->shoots()->sync($shootIds->all());
                    $existingInvoice->refreshTotals();
                    $existingInvoice->recordAuditEvent('recalculated', null, 'Photographer invoice recalculated.', [
                        'before' => $before,
                        'after' => [
                            'total_amount' => round((float) $existingInvoice->total_amount, 2),
                            'service_count' => $photographerServices->count(),
                        ],
                    ]);
                    $invoices->push($existingInvoice->fresh(['photographer', 'items', 'shoots']));

                    continue;
                }

                // Create invoice
                $invoice = Invoice::create([
                    'user_id' => $photographerId,
                    'role' => Invoice::ROLE_PHOTOGRAPHER,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'photographer_id' => $photographerId,
                    'billing_period_start' => $start->toDateString(),
                    'billing_period_end' => $end->toDateString(),
                    'status' => Invoice::STATUS_DRAFT,
                    'approval_status' => Invoice::APPROVAL_STATUS_PENDING ?? 'pending',
                ]);

                // Create invoice items for each SERVICE (not shoot)
                // IDEMPOTENCY: Check for existing items to prevent duplicates on regeneration
                foreach ($photographerServices as $serviceRow) {
                    // Check if this service item already exists on the invoice
                    $existingItemQuery = $invoice->items()
                        ->where('shoot_id', $serviceRow['shoot_id']);
                    if (! empty($serviceRow['shoot_compensation_id'])) {
                        $existingItemQuery->where('shoot_compensation_id', $serviceRow['shoot_compensation_id']);
                    } else {
                        $existingItemQuery->whereJsonContains('meta->service_id', $serviceRow['service_id']);
                    }
                    $existingItem = $existingItemQuery->first();

                    if ($existingItem) {
                        // Update existing item instead of creating duplicate
                        $existingItem->update([
                            'unit_amount' => $serviceRow['photographer_pay'],
                            'total_amount' => $serviceRow['photographer_pay'],
                        ]);

                        continue;
                    }

                    $invoice->items()->create(
                        $this->photographerPayoutItemPayload($serviceRow)
                    );
                }

                $invoice->update([
                    'total_amount' => $totalAmount,
                    'amount_paid' => $amountPaid,
                    'is_paid' => $totalAmount > 0 ? $amountPaid >= $totalAmount : false,
                ]);

                // Sync shoots (use unique shoot IDs from service rows)
                $invoice->shoots()->sync($shootIds->all());

                // Refresh totals
                $invoice->refreshTotals();
                $invoice->recordAuditEvent('generated', null, 'Photographer invoice generated.', [
                    'total_amount' => round((float) $invoice->total_amount, 2),
                    'service_count' => $photographerServices->count(),
                ]);

                $invoice = $invoice->fresh(['photographer', 'items', 'shoots']);

                // Send email notification if requested
                if ($sendEmails && $invoice->photographer) {
                    try {
                        $this->mailService->sendInvoiceGeneratedEmail($invoice);

                        $invoice->loadMissing(['photographer', 'client']);
                        $context = [
                            'invoice' => $invoice,
                            'invoice_id' => $invoice->id,
                            'photographer' => $invoice->photographer,
                            'account_id' => $invoice->photographer_id,
                        ];
                        app(AutomationService::class)->handleEvent('WEEKLY_PHOTOGRAPHER_INVOICE', $context);
                    } catch (\Exception $e) {
                        Log::error('Failed to send invoice email', [
                            'invoice_id' => $invoice->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $invoices->push($invoice);
            }

            return $invoices;
        });
    }

    private function photographerPayoutItemPayload(array $serviceRow): array
    {
        $compensationId = $serviceRow['shoot_compensation_id'] ?? null;
        $isCompensation = $compensationId !== null;
        $amount = round((float) $serviceRow['photographer_pay'], 2);

        return [
            'shoot_id' => $serviceRow['shoot_id'],
            'shoot_compensation_id' => $compensationId,
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => sprintf(
                $isCompensation
                    ? 'Complimentary reshoot #%d - %s - %s'
                    : 'Shoot #%d - %s - %s',
                $serviceRow['shoot_id'],
                $serviceRow['address'],
                $serviceRow['service_name']
            ),
            'quantity' => 1,
            'unit_amount' => $amount,
            'total_amount' => $amount,
            'recorded_at' => $serviceRow['scheduled_date'],
            'meta' => array_filter([
                'service_id' => $serviceRow['service_id'],
                'service_name' => $serviceRow['service_name'],
                'payout_kind' => $isCompensation ? 'complimentary_reshoot_compensation' : 'service_pay',
                'compensation_amount' => $isCompensation ? $amount : null,
                'compensation_mode' => $serviceRow['compensation_mode'] ?? null,
                'compensation_line_type' => $serviceRow['compensation_line_type'] ?? null,
                'adjusts_compensation_id' => $serviceRow['adjusts_compensation_id'] ?? null,
                'basis_amount_snapshot' => $serviceRow['basis_amount_snapshot'] ?? null,
                'rate_snapshot' => $serviceRow['rate_snapshot'] ?? null,
            ], fn ($value) => $value !== null),
        ];
    }

    private function compensationCanBeGeneratedForPeriod(
        ShootCompensation $compensation,
        string $role,
        Carbon $start,
        Carbon $end,
    ): bool {
        $item = $compensation->invoiceItem;
        if (! $item) {
            return true;
        }

        $invoice = $item->invoice;
        if (! $invoice || $invoice->role !== $role) {
            return false;
        }

        $periodStart = $invoice->billing_period_start ?? $invoice->period_start;
        $periodEnd = $invoice->billing_period_end ?? $invoice->period_end;

        return $periodStart && $periodEnd
            && Carbon::parse($periodStart)->isSameDay($start)
            && Carbon::parse($periodEnd)->isSameDay($end);
    }

    /**
     * Serialize payout generation for one recipient role and earning period.
     * The row is deliberately durable: insert-or-ignore handles first use and
     * lockForUpdate makes concurrent workers wait for the active transaction.
     */
    private function acquirePayoutGenerationLock(string $role, Carbon $start, Carbon $end): void
    {
        $lockKey = implode(':', [
            'payout',
            strtolower($role),
            $start->toDateString(),
            $end->toDateString(),
        ]);
        $now = now();

        DB::table('payout_generation_locks')->insertOrIgnore([
            'lock_key' => $lockKey,
            'recipient_role' => $role,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('payout_generation_locks')
            ->where('lock_key', $lockKey)
            ->lockForUpdate()
            ->first();
    }

    private function extractShootSqft(Shoot $shoot): ?int
    {
        $propertyDetails = $shoot->property_details;

        if (is_string($propertyDetails)) {
            $propertyDetails = json_decode($propertyDetails, true);
        }

        if (! is_array($propertyDetails)) {
            return null;
        }

        $sqft = $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? null;

        return is_numeric($sqft) ? (int) $sqft : null;
    }

    public function generateInvoice(User $user, string $role, Carbon $start, Carbon $end): Invoice
    {
        $normalizedRole = strtolower($role);

        return match ($normalizedRole) {
            Invoice::ROLE_CLIENT => $this->generateClientInvoice($user, $start, $end),
            Invoice::ROLE_PHOTOGRAPHER => $this->generatePhotographerInvoice($user, $start, $end),
            default => throw new \InvalidArgumentException("Unsupported invoice role [{$role}]"),
        };
    }

    protected function generateClientInvoice(User $user, Carbon $start, Carbon $end): Invoice
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        $shoots = Shoot::with([
            'payments' => function ($query) {
                $query->where('status', Payment::STATUS_COMPLETED)->with('refunds');
            },
            'services',
        ])
            ->where('client_id', $user->id)
            ->where(function ($query) {
                $query->whereNull('shoot_type')
                    ->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT);
            })
            ->whereBetween('scheduled_date', [
                $start->copy()->startOfDay()->toDateTimeString(),
                $end->copy()->endOfDay()->toDateTimeString(),
            ])
            ->get();

        if ($shoots->isEmpty()) {
            $complimentaryReceipt = Invoice::query()
                ->where('user_id', $user->id)
                ->where('role', Invoice::ROLE_CLIENT)
                ->where('document_type', Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT)
                ->whereHas('shoot', function ($query) use ($start, $end) {
                    $query->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)
                        ->whereBetween('scheduled_date', [
                            $start->toDateTimeString(),
                            $end->toDateTimeString(),
                        ]);
                })
                ->latest('id')
                ->first();

            // A period containing only comp work already has its direct $0
            // receipt. Reuse it instead of manufacturing an empty aggregate
            // invoice that could be mistaken for another client document.
            if ($complimentaryReceipt) {
                return $complimentaryReceipt->load(['items', 'user', 'client', 'shoots']);
            }
        }

        return DB::transaction(function () use ($user, $start, $end, $shoots) {
            $invoice = Invoice::query()
                ->where('user_id', $user->id)
                ->where('role', Invoice::ROLE_CLIENT)
                ->whereNull('shoot_id')
                ->where(function ($query) {
                    $query->whereNull('document_type')
                        ->orWhere('document_type', '!=', Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT);
                })
                ->whereDate('period_start', $start->toDateString())
                ->whereDate('period_end', $end->toDateString())
                ->lockForUpdate()
                ->first();

            $invoice ??= new Invoice([
                'user_id' => $user->id,
                'role' => Invoice::ROLE_CLIENT,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ]);

            $invoiceData = [
                'issue_date' => now(),
                'due_date' => $end->copy()->addDays(30),
                'is_sent' => true,
                'status' => Invoice::STATUS_SENT,
                // Period charge rows are pre-tax. Store the period's tax once
                // on the invoice so refreshTotals includes it exactly once.
                'tax' => round((float) $shoots->sum(fn (Shoot $shoot) => (float) ($shoot->tax_amount ?? 0)), 2),
            ];

            if ($this->invoiceTableHasColumn('client_id')) {
                $invoiceData['client_id'] = $user->id;
            }

            if ($this->invoiceTableHasColumn('billing_period_start')) {
                $invoiceData['billing_period_start'] = $start->toDateString();
            }

            if ($this->invoiceTableHasColumn('billing_period_end')) {
                $invoiceData['billing_period_end'] = $end->toDateString();
            }

            if (! $invoice->exists && $this->invoiceTableHasColumn('invoice_number')) {
                $invoiceData['invoice_number'] = $this->generateNextInvoiceNumber();
            }

            $invoice->fill($invoiceData);
            $invoice->save();

            // Rebuild every generated row. Only explicit admin_misc expenses are
            // authored manual adjustments and survive regeneration; preserving
            // arbitrary expense rows kept stale generated data indefinitely.
            $itemIdsToDelete = $invoice->items()->get()
                ->reject(function (InvoiceItem $item) {
                    $meta = is_array($item->meta) ? $item->meta : [];

                    return $item->type === InvoiceItem::TYPE_EXPENSE
                        && ($meta['source'] ?? null) === 'admin_misc';
                })
                ->pluck('id');

            if ($itemIdsToDelete->isNotEmpty()) {
                $invoice->items()->whereKey($itemIdsToDelete->all())->delete();
            }

            // Only adjustments actually retained on this period invoice may be
            // split out of its generated shoot charges. An adjustment living
            // on a separate direct invoice is already reflected in the shoot's
            // total_quote but is not a row here, so subtracting it would
            // understate this period invoice.
            $periodBillableAdjustments = $invoice->items()
                ->where('type', InvoiceItem::TYPE_EXPENSE)
                ->get()
                ->filter(function (InvoiceItem $item) {
                    $meta = is_array($item->meta) ? $item->meta : [];

                    return ($meta['source'] ?? null) === 'admin_misc'
                        && (bool) ($meta['bills_client'] ?? false);
                });
            $legacyAdjustmentTarget = app(InvoiceAdjustmentService::class)
                ->resolveTargetShoot($invoice, null, false);
            $unattributedAdjustmentRemaining = (float) $periodBillableAdjustments
                ->whereNull('shoot_id')
                ->when(
                    $legacyAdjustmentTarget,
                    fn (Collection $items) => $items->reject(
                        fn (InvoiceItem $item) => $item->shoot_id === null
                    )
                )
                ->sum('total_amount');

            foreach ($shoots->values() as $shoot) {
                $serviceNames = $shoot->services->pluck('name')->filter()->implode(', ');
                $description = trim(sprintf(
                    'Shoot #%d%s%s',
                    $shoot->id,
                    $shoot->address ? ' - '.$shoot->address : '',
                    $serviceNames !== '' ? ' - '.$serviceNames : ''
                ));

                $billableAdjustments = (float) $periodBillableAdjustments
                    ->filter(function (InvoiceItem $item) use ($shoot, $legacyAdjustmentTarget) {
                        if ($item->shoot_id !== null) {
                            return (int) $item->shoot_id === (int) $shoot->id;
                        }

                        return $legacyAdjustmentTarget
                            && (int) $legacyAdjustmentTarget->id === (int) $shoot->id;
                    })
                    ->sum('total_amount');
                $preTaxPayable = max(
                    (float) ($shoot->total_quote ?? 0) - (float) ($shoot->tax_amount ?? 0),
                    0
                );
                $shootBasePayable = max($preTaxPayable - $billableAdjustments, 0);

                if ($unattributedAdjustmentRemaining > 0) {
                    $unattributedApplied = min($shootBasePayable, $unattributedAdjustmentRemaining);
                    $shootBasePayable -= $unattributedApplied;
                    $unattributedAdjustmentRemaining -= $unattributedApplied;
                }
                $shootBasePayable = round($shootBasePayable, 2);

                $invoice->items()->create([
                    'shoot_id' => $shoot->id,
                    'type' => InvoiceItem::TYPE_CHARGE,
                    'description' => $description,
                    'quantity' => 1,
                    'unit_amount' => $shootBasePayable,
                    'total_amount' => $shootBasePayable,
                    'recorded_at' => $shoot->scheduled_at ?? $shoot->scheduled_date,
                    'meta' => [
                        'shoot_id' => $shoot->id,
                    ],
                ]);

                foreach ($shoot->payments as $payment) {
                    $netAmount = $payment->netAmount();
                    if ($netAmount <= 0) {
                        continue;
                    }

                    $invoice->items()->create([
                        'shoot_id' => $shoot->id,
                        'type' => InvoiceItem::TYPE_PAYMENT,
                        'description' => 'Payment received',
                        'quantity' => 1,
                        'unit_amount' => $netAmount,
                        'total_amount' => $netAmount,
                        'recorded_at' => $payment->processed_at ?? $payment->created_at,
                        'meta' => [
                            'payment_id' => $payment->id,
                            'payment_method' => $payment->payment_method,
                        ],
                    ]);
                }
            }

            $invoice->shoots()->sync($shoots->pluck('id')->all());
            $invoice->refreshTotals();

            $invoice->refresh();
            $chargesTotal = (float) ($invoice->charges_total ?? $invoice->subtotal ?? 0);
            $invoiceTotal = (float) ($invoice->total ?? $invoice->total_amount ?? $chargesTotal);
            $paymentsTotal = (float) ($invoice->payments_total ?? 0);
            $balanceDue = max($invoiceTotal - $paymentsTotal, 0);

            $invoiceIsPaid = $invoiceTotal <= 0.01 || $balanceDue <= 0.01;
            $normalizedTotals = [
                'amount_paid' => $paymentsTotal,
                'is_paid' => $invoiceIsPaid,
                'status' => $invoiceIsPaid
                    ? Invoice::STATUS_PAID
                    : Invoice::STATUS_SENT,
                'paid_at' => $invoiceIsPaid && $paymentsTotal > 0
                    ? $invoice->latestEffectivePaymentAt()
                    : null,
            ];

            if ($this->invoiceTableHasColumn('charges_total')) {
                $normalizedTotals['charges_total'] = number_format($chargesTotal, 2, '.', '');
            }

            if ($this->invoiceTableHasColumn('payments_total')) {
                $normalizedTotals['payments_total'] = number_format($paymentsTotal, 2, '.', '');
            }

            if ($this->invoiceTableHasColumn('balance_due')) {
                $normalizedTotals['balance_due'] = number_format($balanceDue, 2, '.', '');
            }

            $invoice->forceFill($normalizedTotals)->save();

            return $invoice->fresh(['items', 'user', 'client', 'shoots']);
        });
    }

    protected function generatePhotographerInvoice(User $user, Carbon $start, Carbon $end): Invoice
    {
        $invoices = $this->generateForPeriod($start, $end, false);
        $invoice = $invoices->first(fn (Invoice $candidate) => (int) $candidate->photographer_id === (int) $user->id);

        if ($invoice instanceof Invoice) {
            return $invoice;
        }

        return Invoice::firstOrCreate(
            [
                'user_id' => $user->id,
                'role' => Invoice::ROLE_PHOTOGRAPHER,
                'period_start' => $start->copy()->startOfDay()->toDateString(),
                'period_end' => $end->copy()->endOfDay()->toDateString(),
            ],
            [
                'photographer_id' => $user->id,
                'billing_period_start' => $start->copy()->startOfDay()->toDateString(),
                'billing_period_end' => $end->copy()->endOfDay()->toDateString(),
                'status' => Invoice::STATUS_DRAFT,
            ]
        );
    }

    /**
     * Generate sales rep invoices for the provided billing period.
     */
    public function generateSalesRepInvoicesForPeriod(Carbon $start, Carbon $end, bool $sendEmails = false): Collection
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        $shoots = Shoot::with([
            'service.sqftRanges',
            'services' => fn ($query) => $query->withPivot(['price', 'quantity', 'photographer_pay', 'photographer_id']),
            'rep:id,name,email,role,secondary_roles,metadata,account_status',
        ])
            ->where(function ($query) {
                $query->whereNull('shoot_type')
                    ->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT);
            })
            ->whereBetween('scheduled_date', [
                $start->copy()->startOfDay()->toDateTimeString(),
                $end->copy()->endOfDay()->toDateTimeString(),
            ])
            ->where('sales_rep_pay_enabled', true)
            ->whereNotNull('rep_id')
            ->whereNotIn('workflow_status', [
                Shoot::STATUS_ON_HOLD,
                Shoot::STATUS_CANCELLED,
                Shoot::STATUS_DECLINED,
            ])
            ->get();

        return DB::transaction(function () use ($shoots, $start, $end, $sendEmails) {
            $this->acquirePayoutGenerationLock(Invoice::ROLE_SALES_REP, $start, $end);

            $compensations = ShootCompensation::query()
                ->with(['shoot', 'recipient', 'invoiceItem.invoice'])
                ->where('recipient_type', ShootCompensation::RECIPIENT_SALES_REP)
                ->where('mode', '!=', ShootCompensation::MODE_NONE)
                ->whereNull('voided_at')
                ->whereNotNull('recipient_user_id')
                ->where('amount', '!=', 0)
                ->whereBetween('earned_at', [$start, $end])
                ->lockForUpdate()
                ->get()
                ->filter(fn (ShootCompensation $compensation) => $this->compensationCanBeGeneratedForPeriod(
                    $compensation,
                    Invoice::ROLE_SALES_REP,
                    $start,
                    $end,
                ));

            if ($shoots->isEmpty() && $compensations->isEmpty()) {
                return collect();
            }

            $repIds = $shoots->pluck('rep_id')
                ->merge($compensations->pluck('recipient_user_id'))
                ->filter()
                ->unique()
                ->values();
            $invoices = collect();

            foreach ($repIds as $repId) {
                $repShoots = $shoots->where('rep_id', $repId)->values();
                $repCompensations = $compensations->where('recipient_user_id', $repId)->values();
                $rep = $repShoots->first()?->rep
                    ?: $repCompensations->first()?->recipient
                    ?: User::find($repId);
                if (! $this->isActiveSalesRep($rep)) {
                    continue;
                }

                $periodInvoices = Invoice::where('sales_rep_id', $repId)
                    ->whereNull('photographer_id')
                    ->whereDate('billing_period_start', $start->toDateString())
                    ->whereDate('billing_period_end', $end->toDateString())
                    ->lockForUpdate()
                    ->get();
                $existingInvoice = $periodInvoices->first(
                    fn (Invoice $invoice) => ! $invoice->isAccountsApproved()
                        && $invoice->status !== Invoice::STATUS_PAID
                        && ! $invoice->paid_at
                );
                $hasSettledInvoice = $periodInvoices->contains(
                    fn (Invoice $invoice) => $invoice->isAccountsApproved()
                        || $invoice->status === Invoice::STATUS_PAID
                        || $invoice->paid_at
                );

                if ($hasSettledInvoice) {
                    $repShoots = collect();
                    $repCompensations = $repCompensations
                        ->filter(function (ShootCompensation $compensation) use ($existingInvoice) {
                            $invoiceId = $compensation->invoiceItem?->invoice_id;

                            return $invoiceId === null
                                || ($existingInvoice && (int) $invoiceId === (int) $existingInvoice->id);
                        })
                        ->values();

                    if ($repCompensations->isEmpty()) {
                        $invoices->push($periodInvoices->sortByDesc('id')->first());

                        continue;
                    }
                }

                $commissionRate = $this->resolveSalesCommissionRate($rep);
                $commissionRows = $repShoots
                    ->map(fn (Shoot $shoot) => $this->buildSalesRepCommissionRow($shoot, $commissionRate))
                    ->values();
                $compensationRows = $repCompensations
                    ->map(fn (ShootCompensation $compensation) => $this->buildSalesRepCompensationRow($compensation))
                    ->filter()
                    ->values();
                $grossTotal = round((float) $commissionRows->sum('commissionable_gross'), 2);
                $excludedFeesTotal = round((float) $commissionRows->sum('excluded_fees_total'), 2);
                $commissionTotal = round((float) $commissionRows->sum('commission_amount'), 2);
                $compensationTotal = round((float) $compensationRows->sum('compensation_amount'), 2);
                $invoiceNotes = sprintf(
                    'Commission rate: %s%% on $%s commissionable gross. Excluded fees: $%s. Complimentary compensation: $%s.',
                    $commissionRate,
                    number_format($grossTotal, 2),
                    number_format($excludedFeesTotal, 2),
                    number_format($compensationTotal, 2),
                );
                $shootIds = $repShoots->pluck('id')
                    ->merge($repCompensations->pluck('shoot_id'))
                    ->unique()
                    ->values()
                    ->all();
                $warnings = $commissionRows
                    ->flatMap(fn (array $row) => $row['warnings'])
                    ->values()
                    ->all();

                if ($existingInvoice) {
                    $before = [
                        'total_amount' => round((float) $existingInvoice->total_amount, 2),
                        'unresolved_warnings' => $existingInvoice->unresolved_warnings ?? [],
                    ];
                    $existingInvoice->items()
                        ->where('type', InvoiceItem::TYPE_CHARGE)
                        ->delete();

                    foreach ($commissionRows as $row) {
                        $this->createSalesRepCommissionItem($existingInvoice, $row, $commissionRate);
                    }
                    foreach ($compensationRows as $row) {
                        $this->createSalesRepCompensationItem($existingInvoice, $row);
                    }

                    $invoiceUpdateData = [
                        'amount_paid' => 0,
                        'is_paid' => false,
                        'paid_at' => null,
                        'unresolved_warnings' => $warnings,
                        'warning_override_reason' => null,
                        'warning_override_by' => null,
                        'warning_override_at' => null,
                    ];
                    if ($this->invoiceTableHasColumn('notes')) {
                        $invoiceUpdateData['notes'] = $invoiceNotes;
                    } elseif ($this->invoiceTableHasColumn('modification_notes')) {
                        $invoiceUpdateData['modification_notes'] = $invoiceNotes;
                    }

                    $existingInvoice->update($invoiceUpdateData);
                    $existingInvoice->shoots()->sync($shootIds);
                    $existingInvoice->refreshTotals();
                    $existingInvoice->recordAuditEvent('recalculated', null, 'Sales rep commission invoice recalculated.', [
                        'before' => $before,
                        'after' => [
                            'total_amount' => round((float) $existingInvoice->total_amount, 2),
                            'commissionable_gross' => $grossTotal,
                            'commission_total' => $commissionTotal,
                            'compensation_total' => $compensationTotal,
                            'unresolved_warnings' => $warnings,
                        ],
                    ]);
                    $invoices->push($existingInvoice->fresh(['salesRep', 'items', 'shoots']));

                    continue;
                }

                // Create invoice
                $invoiceData = [
                    'sales_rep_id' => $repId,
                    'user_id' => $repId,
                    'role' => 'salesRep',
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'billing_period_start' => $start->toDateString(),
                    'billing_period_end' => $end->toDateString(),
                    'status' => Invoice::STATUS_DRAFT,
                    'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
                    'amount_paid' => 0,
                    'is_paid' => false,
                    'unresolved_warnings' => $warnings,
                ];
                if ($this->invoiceTableHasColumn('notes')) {
                    $invoiceData['notes'] = $invoiceNotes;
                } elseif ($this->invoiceTableHasColumn('modification_notes')) {
                    $invoiceData['modification_notes'] = $invoiceNotes;
                }

                $invoice = Invoice::create($invoiceData);

                // Create invoice items for each shoot
                foreach ($commissionRows as $row) {
                    $this->createSalesRepCommissionItem($invoice, $row, $commissionRate);
                }
                foreach ($compensationRows as $row) {
                    $this->createSalesRepCompensationItem($invoice, $row);
                }

                // Sync shoots
                $invoice->shoots()->sync($shootIds);

                // Refresh totals
                $invoice->refreshTotals();
                $invoice->recordAuditEvent('generated', null, 'Sales rep commission invoice generated.', [
                    'commissionable_gross' => $grossTotal,
                    'excluded_fees_total' => $excludedFeesTotal,
                    'commission_rate' => $commissionRate,
                    'commission_total' => $commissionTotal,
                    'compensation_total' => $compensationTotal,
                    'warnings' => $warnings,
                ]);

                $invoice = $invoice->fresh(['salesRep', 'items', 'shoots']);

                // Send email notification if requested
                if ($sendEmails && $rep->email) {
                    try {
                        $this->mailService->sendInvoiceGeneratedEmail($invoice);

                        $invoice->loadMissing(['salesRep', 'client']);
                        $context = [
                            'invoice' => $invoice,
                            'invoice_id' => $invoice->id,
                            'rep' => $rep,
                            'account_id' => $repId,
                        ];
                        app(AutomationService::class)->handleEvent('WEEKLY_REP_INVOICE', $context);
                    } catch (\Exception $e) {
                        Log::error('Failed to send sales rep invoice email', [
                            'invoice_id' => $invoice->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $invoices->push($invoice);
            }

            return $invoices;
        });
    }

    /**
     * Generate invoices for the last completed calendar week.
     * Generates both photographer and sales rep invoices.
     */
    public function generateForLastCompletedWeek(bool $sendEmails = false): Collection
    {
        [$start, $end] = ReportingWeek::lastCompleted();

        $photographerInvoices = $this->generateForPeriod($start, $end, $sendEmails);
        $salesRepInvoices = $this->generateSalesRepInvoicesForPeriod($start, $end, $sendEmails);

        return $photographerInvoices->merge($salesRepInvoices);
    }

    private function resolveSalesCommissionRate(User $rep): float
    {
        $rate = data_get($rep->metadata, 'repDetails.commissionPercentage');

        if (is_numeric($rate) && (float) $rate > 0) {
            return round((float) $rate, 4);
        }

        return self::DEFAULT_SALES_COMMISSION_RATE;
    }

    private function buildSalesRepCommissionRow(Shoot $shoot, float $commissionRate): array
    {
        $warnings = [];
        $commissionableGross = 0.0;
        $excludedFeesTotal = 0.0;
        $serviceLines = [];
        $excludedLines = [];
        $services = $shoot->services;

        if ($services->isEmpty() && $shoot->service) {
            $services = collect([$shoot->service]);
        }

        if ($services->isEmpty()) {
            $warnings[] = $this->buildInvoiceWarning(
                'missing_service_lines',
                $shoot,
                'Shoot has no priced service line items.'
            );
        }

        foreach ($services as $service) {
            $quantity = (int) data_get($service, 'pivot.quantity', 1);
            $quantity = max($quantity, 1);
            $lineAmount = $this->resolveShootServicePrice($shoot, $service, $quantity);
            $line = [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'quantity' => $quantity,
                'amount' => $lineAmount,
                'excluded' => (bool) ($service->exclude_from_sales_commission ?? false),
            ];

            if ($lineAmount === null) {
                $warnings[] = $this->buildInvoiceWarning(
                    'missing_service_price',
                    $shoot,
                    sprintf('Service "%s" has no usable price for commission calculation.', $service->name ?? 'Unknown service'),
                    ['service_id' => $service->id]
                );

                continue;
            }

            if ($this->looksLikeExcludedFee($service->name ?? '') && ! $line['excluded']) {
                $warnings[] = $this->buildInvoiceWarning(
                    'ambiguous_exclusion',
                    $shoot,
                    sprintf('Service "%s" looks like travel/cancellation but is not marked excluded from commission.', $service->name),
                    ['service_id' => $service->id]
                );
            }

            if ($line['excluded']) {
                $excludedFeesTotal += $lineAmount;
                $excludedLines[] = $line;
            } else {
                $commissionableGross += $lineAmount;
                $serviceLines[] = $line;
            }
        }

        if ($commissionableGross <= 0 && empty($serviceLines)) {
            $warnings[] = $this->buildInvoiceWarning(
                'missing_commissionable_amount',
                $shoot,
                'Shoot has no commissionable priced service amount.'
            );
        }

        return [
            'shoot' => $shoot,
            'shoot_id' => $shoot->id,
            'address' => $shoot->address ?? 'Location TBD',
            'scheduled_date' => $shoot->scheduled_date,
            'workflow_status' => $shoot->workflow_status,
            'commissionable_gross' => round($commissionableGross, 2),
            'excluded_fees_total' => round($excludedFeesTotal, 2),
            'commission_amount' => round($commissionableGross * ($commissionRate / 100), 2),
            'service_lines' => $serviceLines,
            'excluded_lines' => $excludedLines,
            'warnings' => $warnings,
        ];
    }

    private function buildSalesRepCompensationRow(ShootCompensation $compensation): ?array
    {
        $shoot = $compensation->shoot;
        if (! $shoot || ! $shoot->isComplimentaryReshoot()) {
            return null;
        }

        return [
            'shoot_id' => $shoot->id,
            'address' => $shoot->address ?? 'Location TBD',
            'recorded_at' => $compensation->earned_at,
            'shoot_compensation_id' => $compensation->id,
            'compensation_amount' => round((float) $compensation->amount, 2),
            'compensation_mode' => $compensation->mode,
            'compensation_line_type' => $compensation->line_type,
            'adjusts_compensation_id' => $compensation->adjusts_compensation_id,
            'payout_invoice_id' => $compensation->invoiceItem?->invoice_id,
            'basis_amount_snapshot' => $compensation->basis_amount_snapshot,
            'rate_snapshot' => $compensation->rate_snapshot,
        ];
    }

    private function resolveShootServicePrice(Shoot $shoot, Service $service, int $quantity): ?float
    {
        $pivotPrice = data_get($service, 'pivot.price');
        if ($pivotPrice !== null && $pivotPrice !== '') {
            return round((float) $pivotPrice * $quantity, 2);
        }

        $propertySqft = $this->extractShootSqft($shoot);
        $price = method_exists($service, 'getPriceForSqft')
            ? $service->getPriceForSqft($propertySqft)
            : $service->price;

        if ($price === null || $price === '') {
            return null;
        }

        return round((float) $price * $quantity, 2);
    }

    private function createSalesRepCommissionItem(Invoice $invoice, array $row, float $commissionRate): void
    {
        $invoice->items()->create([
            'shoot_id' => $row['shoot_id'],
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => sprintf(
                'Shoot #%d - %s (Commission %s%% on $%s)',
                $row['shoot_id'],
                $row['address'],
                $commissionRate,
                number_format($row['commissionable_gross'], 2)
            ),
            'quantity' => 1,
            'unit_amount' => $row['commission_amount'],
            'total_amount' => $row['commission_amount'],
            'recorded_at' => $row['scheduled_date'],
            'meta' => [
                'workflow_status' => $row['workflow_status'],
                'commissionable_gross' => $row['commissionable_gross'],
                'excluded_fees_total' => $row['excluded_fees_total'],
                'commission_rate' => $commissionRate,
                'service_lines' => $row['service_lines'],
                'excluded_lines' => $row['excluded_lines'],
                'warnings' => $row['warnings'],
            ],
        ]);
    }

    private function createSalesRepCompensationItem(Invoice $invoice, array $row): void
    {
        $amount = round((float) $row['compensation_amount'], 2);

        $invoice->items()->create([
            'shoot_id' => $row['shoot_id'],
            'shoot_compensation_id' => $row['shoot_compensation_id'],
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => sprintf(
                'Complimentary reshoot #%d - %s (Rep compensation)',
                $row['shoot_id'],
                $row['address'],
            ),
            'quantity' => 1,
            'unit_amount' => $amount,
            'total_amount' => $amount,
            'recorded_at' => $row['recorded_at'],
            'meta' => array_filter([
                'payout_kind' => 'complimentary_reshoot_compensation',
                'commissionable_gross' => 0,
                'commission_rate' => 0,
                'commission_amount' => 0,
                'compensation_amount' => $amount,
                'compensation_mode' => $row['compensation_mode'],
                'compensation_line_type' => $row['compensation_line_type'] ?? null,
                'adjusts_compensation_id' => $row['adjusts_compensation_id'] ?? null,
                'basis_amount_snapshot' => $row['basis_amount_snapshot'],
                'rate_snapshot' => $row['rate_snapshot'],
            ], fn ($value) => $value !== null),
        ]);
    }

    private function looksLikeExcludedFee(string $name): bool
    {
        return preg_match('/travel|cancel|cancellation|reschedule/i', $name) === 1;
    }

    private function buildInvoiceWarning(string $code, Shoot $shoot, string $message, array $extra = []): array
    {
        return array_merge([
            'code' => $code,
            'shoot_id' => $shoot->id,
            'address' => $shoot->address,
            'message' => $message,
        ], $extra);
    }

    private function isActiveSalesRep(?User $user): bool
    {
        return $this->isSalesRep($user) && ($user->account_status ?? 'active') === 'active';
    }

    private function isSalesRep(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $normalizedRoles = array_map('strtolower', self::SALES_REP_ROLES);
        $primaryRole = strtolower((string) $user->role);

        if (in_array($primaryRole, $normalizedRoles, true)) {
            return true;
        }

        $secondaryRoles = is_array($user->secondary_roles) ? $user->secondary_roles : [];

        return collect($secondaryRoles)
            ->map(fn ($role) => strtolower((string) $role))
            ->intersect($normalizedRoles)
            ->isNotEmpty();
    }

    /**
     * Generate an individual invoice for a shoot (client-facing invoice)
     */
    public function refreshClientInvoicesForShoot(Shoot $shoot): Collection
    {
        $invoiceAdjustments = app(InvoiceAdjustmentService::class);
        $invoices = $invoiceAdjustments->clientInvoicesForShoot($shoot);

        if ($invoices->isEmpty()) {
            return collect();
        }

        $refreshed = collect();
        if ($invoices->contains(fn (Invoice $invoice) => (int) $invoice->shoot_id === (int) $shoot->id)) {
            $direct = $this->generateForShoot($shoot->fresh());
            if ($direct) {
                $refreshed->push($direct);
            }
        }

        $aggregateInvoices = $invoices->reject(
            fn (Invoice $invoice) => (int) $invoice->shoot_id === (int) $shoot->id
        );

        foreach ($aggregateInvoices as $invoice) {
            $client = User::find($invoice->user_id) ?: $shoot->client;
            if (! $client) {
                throw new \RuntimeException("Cannot refresh invoice {$invoice->id}: client is missing.");
            }

            $startValue = $invoice->period_start ?? $invoice->billing_period_start;
            $endValue = $invoice->period_end ?? $invoice->billing_period_end;
            if (! $startValue || ! $endValue) {
                throw new \RuntimeException("Cannot refresh invoice {$invoice->id}: billing period is missing.");
            }

            $refreshed->push($this->generateInvoice(
                $client,
                Invoice::ROLE_CLIENT,
                Carbon::parse($startValue),
                Carbon::parse($endValue)
            ));
        }

        return $refreshed->unique('id')->values();
    }

    public function generateForShoot(Shoot $shoot): ?Invoice
    {
        return DB::transaction(function () use ($shoot) {
            // Serialize invoice generation per shoot. This closes the former gap
            // where regenerating an existing invoice happened outside a
            // transaction and could expose a half-rebuilt set of line items.
            $shoot = Shoot::query()->lockForUpdate()->findOrFail($shoot->id);
            $shoot->load(['client', 'photographer', 'services', 'payments.refunds', 'compReshootItems']);
            $isComplimentaryReceipt = $shoot->isComplimentaryReshoot();

            $invoiceAdjustments = app(InvoiceAdjustmentService::class);
            $existingInvoice = Invoice::query()
                ->where('role', Invoice::ROLE_CLIENT)
                ->where('shoot_id', $shoot->id)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if (! $existingInvoice && ! $isComplimentaryReceipt) {
                // Period/aggregate invoices may be linked only through the pivot
                // or an attributed item. Reuse them instead of creating a second
                // client invoice for the same shoot. They must not be rebuilt as
                // single-shoot invoices because that would erase other shoots.
                $relatedInvoice = $invoiceAdjustments->preferredClientInvoiceForShoot($shoot);
                if ($relatedInvoice) {
                    $invoiceAdjustments->applyInvoiceTotalDelta($relatedInvoice, 0.0);

                    return $relatedInvoice->fresh(['shoot', 'client', 'photographer', 'items', 'shoots']);
                }
            }

            $isCancellationFeeOnly = $this->usesCancellationFeeOnlyInvoice($shoot);
            $shootAdjustmentTotal = $invoiceAdjustments
                ->billableItemsForShoot($shoot)
                ->sum(fn (InvoiceItem $item) => (float) $item->total_amount);
            $subtotal = $isComplimentaryReceipt
                ? 0.0
                : ($isCancellationFeeOnly
                    ? max((float) ($shoot->total_quote ?? 0) - $shootAdjustmentTotal, 0)
                    : (float) ($shoot->base_quote ?? 0));
            $taxAmount = ($isCancellationFeeOnly || $isComplimentaryReceipt)
                ? 0.0
                : (float) ($shoot->tax_amount ?? 0);
            $total = $isCancellationFeeOnly ? $subtotal : $subtotal + $taxAmount;
            $totalPaid = $isComplimentaryReceipt ? 0.0 : $shoot->calculateCanonicalTotalPaid();

            if ($existingInvoice) {
                $existingInvoice->items()
                    ->where('type', InvoiceItem::TYPE_CHARGE)
                    ->where('shoot_id', $shoot->id)
                    ->delete();

                $this->createShootChargeItems($existingInvoice, $shoot, $isCancellationFeeOnly);
                $existingInvoice->shoots()->syncWithoutDetaching([$shoot->id]);

                $adjustmentTotal = $existingInvoice->items()
                    ->where('type', InvoiceItem::TYPE_EXPENSE)
                    ->get()
                    ->filter(function (InvoiceItem $item) {
                        $meta = is_array($item->meta) ? $item->meta : [];

                        return ($meta['source'] ?? null) === 'admin_misc'
                            && (bool) ($meta['bills_client'] ?? false);
                    })
                    ->sum(fn (InvoiceItem $item) => (float) $item->total_amount);

                $isPaid = ! $isComplimentaryReceipt
                    && ($total <= 0.01 || $totalPaid >= ($total - 0.01));
                $existingInvoice->forceFill([
                    'document_type' => $isComplimentaryReceipt
                        ? Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT
                        : Invoice::DOCUMENT_TYPE_INVOICE,
                    'payment_required' => ! $isComplimentaryReceipt,
                    'subtotal' => $subtotal,
                    'tax' => $taxAmount,
                    'total' => $total,
                    'total_amount' => $total,
                    'amount_paid' => $totalPaid,
                    'is_paid' => $isPaid,
                    'status' => $isPaid
                        ? Invoice::STATUS_PAID
                        : Invoice::STATUS_SENT,
                    'paid_at' => $isPaid ? $existingInvoice->paid_at : null,
                    'due_date' => $isComplimentaryReceipt ? null : $existingInvoice->due_date,
                ])->save();
                $invoiceAdjustments->applyInvoiceTotalDelta($existingInvoice, $adjustmentTotal);

                return $existingInvoice->fresh(['shoot', 'client', 'photographer', 'items', 'shoots']);
            }

            // Generate invoice number (format: Invoice 02195)
            $lastInvoice = Invoice::whereNotNull('invoice_number')
                ->orderBy('id', 'desc')
                ->first();

            $invoiceNumber = ($isComplimentaryReceipt ? 'Receipt ' : 'Invoice ').str_pad(
                $lastInvoice ? ((int) preg_replace('/\D/', '', $lastInvoice->invoice_number)) + 1 : 1,
                5,
                '0',
                STR_PAD_LEFT
            );

            // Create invoice
            // Note: user_id, role, period_start, and period_end are required by the original schema
            // For shoot-based invoices, we use client_id as user_id and set appropriate period dates
            $shootDate = $shoot->scheduled_at ? Carbon::parse($shoot->scheduled_at) : now();
            $periodStart = $shootDate->copy()->startOfDay()->toDateString();
            $periodEnd = $shootDate->copy()->endOfDay()->toDateString();

            $userId = $this->determineInvoiceUserId($shoot);

            $isPaid = ! $isComplimentaryReceipt
                && ($total <= 0.01 || $totalPaid >= ($total - 0.01));
            $invoiceData = [
                'user_id' => $userId,
                'role' => Invoice::ROLE_CLIENT,
                'document_type' => $isComplimentaryReceipt
                    ? Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT
                    : Invoice::DOCUMENT_TYPE_INVOICE,
                'payment_required' => ! $isComplimentaryReceipt,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'invoice_number' => $invoiceNumber,
                'issue_date' => now(),
                'due_date' => $isComplimentaryReceipt
                    ? null
                    : ($shoot->scheduled_at ? Carbon::parse($shoot->scheduled_at)->addDays(30) : now()->addDays(30)),
                'subtotal' => $subtotal,
                'tax' => $taxAmount,
                'total' => $total,
                'total_amount' => $total,
                'amount_paid' => $totalPaid,
                'is_paid' => $isPaid,
                'is_sent' => true,
                'status' => $isPaid
                    ? Invoice::STATUS_PAID
                    : Invoice::STATUS_SENT,
                'paid_at' => null,
            ];

            $optionalColumns = [
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
                'shoot_id' => $shoot->id,
                'client_id' => $shoot->client_id,
                'photographer_id' => $shoot->photographer_id,
            ];

            foreach ($optionalColumns as $column => $value) {
                if ($this->invoiceTableHasColumn($column)) {
                    $invoiceData[$column] = $value;
                }
            }

            $invoice = Invoice::create($invoiceData);

            $this->createShootChargeItems($invoice, $shoot, $isCancellationFeeOnly);
            $invoice->shoots()->syncWithoutDetaching([$shoot->id]);
            $invoiceAdjustments->applyInvoiceTotalDelta($invoice, 0.0);

            return $invoice->fresh(['shoot', 'client', 'photographer', 'items', 'shoots']);
        });
    }

    /**
     * Generate a cancellation fee invoice for a shoot
     */
    public function generateCancellationFeeInvoice(Shoot $shoot, float $cancellationFee = 60.00): ?Invoice
    {
        return DB::transaction(function () use ($shoot, $cancellationFee) {
            $shoot->load(['client']);

            // Generate invoice number
            $lastInvoice = Invoice::whereNotNull('invoice_number')
                ->orderBy('id', 'desc')
                ->first();

            $invoiceNumber = 'Invoice '.str_pad(
                $lastInvoice ? ((int) preg_replace('/\D/', '', $lastInvoice->invoice_number)) + 1 : 1,
                5,
                '0',
                STR_PAD_LEFT
            );

            $userId = $this->determineInvoiceUserId($shoot);
            $now = now();

            $invoiceData = [
                'user_id' => $userId,
                'role' => Invoice::ROLE_CLIENT,
                'period_start' => $now->toDateString(),
                'period_end' => $now->toDateString(),
                'invoice_number' => $invoiceNumber,
                'issue_date' => $now,
                'due_date' => $now->copy()->addDays(7), // 7 days to pay cancellation fee
                'subtotal' => $cancellationFee,
                'tax' => 0, // Cancellation fee is not taxed
                'total' => $cancellationFee,
                'total_amount' => $cancellationFee,
                'amount_paid' => 0,
                'is_paid' => false,
                'is_sent' => true,
                'status' => Invoice::STATUS_SENT,
            ];

            $optionalColumns = [
                'billing_period_start' => $now->toDateString(),
                'billing_period_end' => $now->toDateString(),
                'shoot_id' => $shoot->id,
                'client_id' => $shoot->client_id,
            ];
            $note = 'Cancellation fee for shoot at '.$shoot->address;
            if ($this->invoiceTableHasColumn('notes')) {
                $optionalColumns['notes'] = $note;
            } elseif ($this->invoiceTableHasColumn('modification_notes')) {
                $optionalColumns['modification_notes'] = $note;
            }

            foreach ($optionalColumns as $column => $value) {
                if ($this->invoiceTableHasColumn($column)) {
                    $invoiceData[$column] = $value;
                }
            }

            $invoice = Invoice::create($invoiceData);

            // Create invoice item for cancellation fee
            $invoice->items()->create([
                'shoot_id' => $shoot->id,
                'type' => InvoiceItem::TYPE_CHARGE,
                'description' => 'Cancellation Fee - '.$shoot->address,
                'quantity' => 1,
                'unit_amount' => $cancellationFee,
                'total_amount' => $cancellationFee,
                'recorded_at' => $now,
                'meta' => [
                    'type' => 'cancellation_fee',
                    'shoot_id' => $shoot->id,
                    'shoot_address' => $shoot->address,
                ],
            ]);

            return $invoice->fresh(['shoot', 'client', 'items']);
        });
    }

    public function createCancellationPhotographerPayouts(Shoot $shoot, float $payoutTotal = 50.00): Collection
    {
        return DB::transaction(function () use ($shoot, $payoutTotal) {
            $photographerIds = $this->eligibleCancellationPayoutPhotographerIds($shoot);
            if ($photographerIds->isEmpty() || $payoutTotal <= 0) {
                return collect();
            }

            $shares = $this->splitAmountEqually($payoutTotal, $photographerIds->count());
            $scheduledAt = $this->resolveShootScheduledAt($shoot) ?? now();
            $periodStart = $scheduledAt->copy()->startOfDay()->toDateString();
            $periodEnd = $scheduledAt->copy()->endOfDay()->toDateString();
            $created = collect();

            foreach ($photographerIds->values() as $index => $photographerId) {
                $existingItem = InvoiceItem::query()
                    ->where('shoot_id', $shoot->id)
                    ->where('type', InvoiceItem::TYPE_CHARGE)
                    ->get()
                    ->first(function (InvoiceItem $item) use ($photographerId) {
                        $meta = is_array($item->meta) ? $item->meta : [];

                        return ($meta['type'] ?? null) === 'cancellation_photographer_payout'
                            && (int) ($meta['photographer_id'] ?? 0) === (int) $photographerId;
                    });

                if ($existingItem) {
                    $created->push($existingItem->invoice()->first());

                    continue;
                }

                $invoice = Invoice::firstOrCreate(
                    [
                        'user_id' => $photographerId,
                        'role' => Invoice::ROLE_PHOTOGRAPHER,
                        'photographer_id' => $photographerId,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                    ],
                    [
                        'billing_period_start' => $periodStart,
                        'billing_period_end' => $periodEnd,
                        'status' => Invoice::STATUS_DRAFT,
                        'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
                        'total_amount' => 0,
                        'amount_paid' => 0,
                        'is_sent' => false,
                        'is_paid' => false,
                    ]
                );

                $invoice->items()->create([
                    'shoot_id' => $shoot->id,
                    'type' => InvoiceItem::TYPE_CHARGE,
                    'description' => 'Cancellation Payout - '.($shoot->address ?: 'Shoot #'.$shoot->id),
                    'quantity' => 1,
                    'unit_amount' => $shares[$index],
                    'total_amount' => $shares[$index],
                    'recorded_at' => now(),
                    'meta' => [
                        'type' => 'cancellation_photographer_payout',
                        'shoot_id' => $shoot->id,
                        'photographer_id' => $photographerId,
                        'payout_total' => round($payoutTotal, 2),
                        'eligible_photographer_count' => $photographerIds->count(),
                    ],
                ]);

                $invoice->shoots()->syncWithoutDetaching([$shoot->id]);
                $invoice->refreshTotals();
                $invoice->recordAuditEvent('cancellation_payout_added', null, 'Cancellation payout added.', [
                    'shoot_id' => $shoot->id,
                    'payout_amount' => $shares[$index],
                ]);
                $created->push($invoice->fresh(['photographer', 'items', 'shoots']));
            }

            return $created->filter()->values();
        });
    }

    protected function eligibleCancellationPayoutPhotographerIds(Shoot $shoot): Collection
    {
        $shoot->loadMissing(['services' => fn ($query) => $query->withPivot(['photographer_id', 'scheduled_at'])]);
        $shootScheduledAt = $this->resolveShootScheduledAt($shoot);

        $photographerIds = collect();

        foreach ($shoot->services as $service) {
            $serviceScheduledAt = $service->pivot?->scheduled_at
                ? Carbon::parse($service->pivot->scheduled_at, $shoot->timezone ?: null)
                : $shootScheduledAt;

            if ($serviceScheduledAt && ! $this->isWithinCancellationFeeWindow($serviceScheduledAt)) {
                continue;
            }

            $photographerId = $service->pivot?->photographer_id ?? $shoot->photographer_id;
            if ($photographerId) {
                $photographerIds->push((int) $photographerId);
            }
        }

        if ($photographerIds->isEmpty() && $shoot->photographer_id && $shootScheduledAt && $this->isWithinCancellationFeeWindow($shootScheduledAt)) {
            $photographerIds->push((int) $shoot->photographer_id);
        }

        return $photographerIds->unique()->values();
    }

    protected function splitAmountEqually(float $amount, int $recipientCount): array
    {
        $totalCents = (int) round($amount * 100);
        $baseCents = intdiv($totalCents, $recipientCount);
        $remainder = $totalCents % $recipientCount;
        $shares = [];

        for ($index = 0; $index < $recipientCount; $index++) {
            $shares[] = ($baseCents + ($index < $remainder ? 1 : 0)) / 100;
        }

        return $shares;
    }

    protected function resolveShootScheduledAt(Shoot $shoot): ?Carbon
    {
        if ($shoot->scheduled_at) {
            return Carbon::parse($shoot->scheduled_at, $shoot->timezone ?: null);
        }

        if (! $shoot->scheduled_date || ! $shoot->time) {
            return null;
        }

        $date = $shoot->scheduled_date instanceof Carbon
            ? $shoot->scheduled_date->format('Y-m-d')
            : Carbon::parse($shoot->scheduled_date)->format('Y-m-d');

        return Carbon::parse($date.' '.$shoot->time, $shoot->timezone ?: null);
    }

    protected function isWithinCancellationFeeWindow(Carbon $scheduledAt): bool
    {
        $hoursUntilShoot = now($scheduledAt->timezone)->diffInMinutes($scheduledAt, false) / 60;

        return $hoursUntilShoot >= 0 && $hoursUntilShoot <= 4;
    }

    protected function createShootChargeItems(Invoice $invoice, Shoot $shoot, bool $isCancellationFeeOnly = false): void
    {
        $isComplimentaryReceipt = $shoot->isComplimentaryReshoot();
        $reshootItems = $shoot->relationLoaded('compReshootItems')
            ? $shoot->compReshootItems->keyBy('shoot_service_id')
            : $shoot->compReshootItems()->get()->keyBy('shoot_service_id');

        foreach ($shoot->services as $service) {
            $reshootItem = $reshootItems->get($service->pivot->id);
            $quantity = (int) ($reshootItem?->quantity_snapshot ?? $service->pivot->quantity ?? 1);
            $nominalUnitAmount = (float) (
                $reshootItem?->nominal_unit_price_snapshot
                ?? $service->pivot->nominal_value_snapshot
                ?? $service->pivot->price
                ?? $service->price
                ?? 0
            );
            $nominalTotalAmount = (float) (
                $reshootItem?->nominal_total_snapshot
                ?? ($nominalUnitAmount * $quantity)
            );
            $servicePrice = $isComplimentaryReceipt ? 0.0 : $nominalUnitAmount;
            $originalAmount = $servicePrice * $quantity;

            $meta = [
                'service_id' => $service->id,
                'service_name' => $reshootItem?->service_name_snapshot
                    ?? $service->name
                    ?? $service->service_name,
            ];

            if ($isComplimentaryReceipt) {
                $meta = array_merge($meta, [
                    'complimentary_reshoot' => true,
                    'comp_reshoot_item_id' => $reshootItem?->id,
                    'reason_code' => $reshootItem?->reason_code,
                    'nominal_unit_amount' => round($nominalUnitAmount, 2),
                    'nominal_total_amount' => round($nominalTotalAmount, 2),
                ]);
            }

            if ($isCancellationFeeOnly) {
                $meta['cancelled_service_charge'] = true;
                $meta['waived_due_to_cancellation'] = true;
                $meta['original_amount'] = round($originalAmount, 2);
            }

            $invoice->items()->create([
                'shoot_id' => $shoot->id,
                'type' => InvoiceItem::TYPE_CHARGE,
                'description' => $isComplimentaryReceipt
                    ? 'Complimentary - '.($meta['service_name'] ?: 'Service')
                    : $this->describeShootService($shoot, $service),
                'quantity' => $quantity,
                'unit_amount' => $servicePrice,
                'total_amount' => ($isCancellationFeeOnly || $isComplimentaryReceipt) ? 0 : $originalAmount,
                'recorded_at' => $shoot->scheduled_at ?? $shoot->scheduled_date,
                'meta' => $meta,
            ]);
        }

        if (! $isCancellationFeeOnly) {
            return;
        }

        $billableAdjustments = app(InvoiceAdjustmentService::class)
            ->billableItemsForShoot($shoot)
            ->sum(fn (InvoiceItem $item) => (float) $item->total_amount);
        $cancellationFee = max((float) ($shoot->total_quote ?? 0) - $billableAdjustments, 0);
        if ($cancellationFee <= 0) {
            return;
        }

        $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'Cancellation Fee - '.$shoot->address,
            'quantity' => 1,
            'unit_amount' => $cancellationFee,
            'total_amount' => $cancellationFee,
            'recorded_at' => now(),
            'meta' => [
                'type' => 'cancellation_fee',
                'cancellation_fee' => true,
                'shoot_id' => $shoot->id,
                'shoot_address' => $shoot->address,
            ],
        ]);
    }

    protected function describeShootService(Shoot $shoot, Service $service): string
    {
        $description = $service->name ?? $service->service_name ?? 'Service';

        if (stripos($description, 'floor plan') !== false || stripos($description, 'floorplan') !== false) {
            return $description.' (1-2999 SQFT)';
        }

        if (stripos($description, 'hdr') === false && stripos($description, 'photo') === false) {
            return $description;
        }

        $propertyDetails = is_array($shoot->property_details)
            ? $shoot->property_details
            : (is_string($shoot->property_details) ? json_decode($shoot->property_details, true) : []);
        $sqft = $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? 0;

        if ($sqft >= 1501 && $sqft <= 3000) {
            return $description.' (1501-3000 SQFT)';
        }
        if ($sqft >= 3001 && $sqft <= 5000) {
            return $description.' (3001-5000 SQFT)';
        }
        if ($sqft >= 5001 && $sqft <= 7000) {
            return $description.' (5001-7000 SQFT)';
        }
        if ($sqft >= 7001 && $sqft <= 10000) {
            return $description.' (7001-10000 SQFT)';
        }

        return $description.' (1-1500 SQFT)';
    }

    protected function usesCancellationFeeOnlyInvoice(Shoot $shoot): bool
    {
        $status = strtolower((string) ($shoot->workflow_status ?? $shoot->status));
        if ($status !== strtolower(Shoot::STATUS_CANCELLED)) {
            return false;
        }

        $total = (float) ($shoot->total_quote ?? 0);
        $billableAdjustments = app(InvoiceAdjustmentService::class)
            ->billableItemsForShoot($shoot)
            ->sum(fn (InvoiceItem $item) => (float) $item->total_amount);
        $cancellationPayable = max($total - $billableAdjustments, 0);
        $originalServiceSubtotal = (float) $shoot->services->sum(function ($service) {
            $servicePrice = (float) ($service->pivot->price ?? $service->price ?? 0);
            $quantity = (int) ($service->pivot->quantity ?? 1);

            return $servicePrice * $quantity;
        });

        return $cancellationPayable > 0
            && $originalServiceSubtotal > $cancellationPayable + 0.01;
    }

    protected function determineInvoiceUserId(Shoot $shoot): int
    {
        $candidateIds = [
            $shoot->client_id,
            $shoot->created_by,
            $shoot->rep_id,
        ];

        foreach ($candidateIds as $candidateId) {
            if ($candidateId && User::whereKey($candidateId)->exists()) {
                return (int) $candidateId;
            }
        }

        $fallbackId = User::whereIn('role', ['superadmin', 'admin'])->orderBy('id')->value('id')
            ?? User::orderBy('id')->value('id');

        if ($fallbackId) {
            return (int) $fallbackId;
        }

        throw new \RuntimeException('Unable to determine user_id for invoice creation');
    }

    protected function invoiceTableHasColumn(string $column): bool
    {
        static $columns;

        if ($columns === null) {
            $columns = Schema::getColumnListing((new Invoice)->getTable());
        }

        return in_array($column, $columns, true);
    }

    protected function generateNextInvoiceNumber(): string
    {
        $lastInvoice = Invoice::whereNotNull('invoice_number')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastInvoice
            ? (int) preg_replace('/\D/', '', (string) $lastInvoice->invoice_number)
            : 0;

        return 'Invoice '.str_pad((string) ($lastNumber + 1), 5, '0', STR_PAD_LEFT);
    }
}
