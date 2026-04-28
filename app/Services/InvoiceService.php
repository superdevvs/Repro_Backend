<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationService;
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

    public function __construct(MailService $mailService = null)
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
                'payments' => function ($query) {
                    $query->where('status', Payment::STATUS_COMPLETED);
                },
                'photographer',
                'service',
                'services' => function ($q) {
                    $q->withPivot(['photographer_id', 'photographer_pay', 'quantity']);
                },
            ])
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

        if ($shoots->isEmpty()) {
            return collect();
        }

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
                if (!$resolvedId) {
                    \Log::warning('Unresolved photographer for invoice', [
                        'shoot_id' => $shoot->id,
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                    ]);
                    continue;
                }
                
                $pivotPay = $service->pivot->photographer_pay ?? null;
                $pay = ($pivotPay !== null && $pivotPay !== '')
                    ? (float) $pivotPay
                    : (float) ($service->photographer_pay ?? 0);
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

        // Group by resolved photographer
        $grouped = $serviceRows->groupBy('resolved_photographer_id');

        return DB::transaction(function () use ($grouped, $start, $end, $sendEmails, $shoots) {
            $invoices = collect();

            foreach ($grouped as $photographerId => $photographerServices) {
                $shootIds = $photographerServices->pluck('shoot_id')->unique();
                $relatedShoots = $shoots->whereIn('id', $shootIds);
                $totalAmount = $photographerServices->sum('photographer_pay');
                $amountPaid = $relatedShoots
                    ->flatMap(fn (Shoot $shoot) => $shoot->payments)
                    ->sum(fn ($payment) => (float) $payment->amount);

                // Check if invoice already exists
                $existingInvoice = Invoice::where('photographer_id', $photographerId)
                    ->where('role', Invoice::ROLE_PHOTOGRAPHER)
                    ->where('billing_period_start', $start->toDateString())
                    ->where('billing_period_end', $end->toDateString())
                    ->first();

                if ($existingInvoice) {
                    if ($existingInvoice->isAccountsApproved() || $existingInvoice->status === Invoice::STATUS_PAID) {
                        $existingInvoice->recordAuditEvent('recalculation_skipped', null, 'Approved or paid photographer invoice was not recalculated.', [
                            'total_amount' => $totalAmount,
                            'service_count' => $photographerServices->count(),
                        ]);
                        $invoices->push($existingInvoice->fresh(['photographer', 'items', 'shoots']));
                        continue;
                    }

                    $before = [
                        'total_amount' => round((float) $existingInvoice->total_amount, 2),
                        'unresolved_warnings' => $existingInvoice->unresolved_warnings ?? [],
                    ];
                    $existingInvoice->items()
                        ->where('type', InvoiceItem::TYPE_CHARGE)
                        ->delete();

                    foreach ($photographerServices as $serviceRow) {
                        $existingInvoice->items()->create([
                            'shoot_id' => $serviceRow['shoot_id'],
                            'type' => InvoiceItem::TYPE_CHARGE,
                            'description' => sprintf(
                                'Shoot #%d - %s - %s',
                                $serviceRow['shoot_id'],
                                $serviceRow['address'],
                                $serviceRow['service_name']
                            ),
                            'quantity' => 1,
                            'unit_amount' => $serviceRow['photographer_pay'],
                            'total_amount' => $serviceRow['photographer_pay'],
                            'recorded_at' => $serviceRow['scheduled_date'],
                            'meta' => [
                                'service_id' => $serviceRow['service_id'],
                                'service_name' => $serviceRow['service_name'],
                            ],
                        ]);
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
                    $existingItem = $invoice->items()
                        ->where('shoot_id', $serviceRow['shoot_id'])
                        ->whereJsonContains('meta->service_id', $serviceRow['service_id'])
                        ->first();
                    
                    if ($existingItem) {
                        // Update existing item instead of creating duplicate
                        $existingItem->update([
                            'unit_amount' => $serviceRow['photographer_pay'],
                            'total_amount' => $serviceRow['photographer_pay'],
                        ]);
                        continue;
                    }
                    
                    $invoice->items()->create([
                        'shoot_id' => $serviceRow['shoot_id'],
                        'type' => InvoiceItem::TYPE_CHARGE,
                        'description' => sprintf(
                            'Shoot #%d - %s - %s',
                            $serviceRow['shoot_id'],
                            $serviceRow['address'],
                            $serviceRow['service_name']
                        ),
                        'quantity' => 1,
                        'unit_amount' => $serviceRow['photographer_pay'],
                        'total_amount' => $serviceRow['photographer_pay'],
                        'recorded_at' => $serviceRow['scheduled_date'],
                        'meta' => [
                            'service_id' => $serviceRow['service_id'],
                            'service_name' => $serviceRow['service_name'],
                        ],
                    ]);
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
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $invoices->push($invoice);
            }

            return $invoices;
        });
    }

    private function extractShootSqft(Shoot $shoot): ?int
    {
        $propertyDetails = $shoot->property_details;

        if (is_string($propertyDetails)) {
            $propertyDetails = json_decode($propertyDetails, true);
        }

        if (!is_array($propertyDetails)) {
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
                    $query->where('status', Payment::STATUS_COMPLETED);
                },
                'services',
            ])
            ->where('client_id', $user->id)
            ->whereBetween('scheduled_date', [
                $start->copy()->startOfDay()->toDateTimeString(),
                $end->copy()->endOfDay()->toDateTimeString(),
            ])
            ->get();

        return DB::transaction(function () use ($user, $start, $end, $shoots) {
            $invoice = Invoice::firstOrNew([
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

            if (!$invoice->exists && $this->invoiceTableHasColumn('invoice_number')) {
                $invoiceData['invoice_number'] = $this->generateNextInvoiceNumber();
            }

            $invoice->fill($invoiceData);
            $invoice->save();

            $invoice->items()->delete();

            foreach ($shoots as $shoot) {
                $serviceNames = $shoot->services->pluck('name')->filter()->implode(', ');
                $description = trim(sprintf(
                    'Shoot #%d%s%s',
                    $shoot->id,
                    $shoot->address ? ' - ' . $shoot->address : '',
                    $serviceNames !== '' ? ' - ' . $serviceNames : ''
                ));

                $invoice->items()->create([
                    'shoot_id' => $shoot->id,
                    'type' => InvoiceItem::TYPE_CHARGE,
                    'description' => $description,
                    'quantity' => 1,
                    'unit_amount' => (float) ($shoot->total_quote ?? 0),
                    'total_amount' => (float) ($shoot->total_quote ?? 0),
                    'recorded_at' => $shoot->scheduled_at ?? $shoot->scheduled_date,
                    'meta' => [
                        'shoot_id' => $shoot->id,
                    ],
                ]);

                foreach ($shoot->payments as $payment) {
                    $invoice->items()->create([
                        'shoot_id' => $shoot->id,
                        'type' => InvoiceItem::TYPE_PAYMENT,
                        'description' => 'Payment received',
                        'quantity' => 1,
                        'unit_amount' => (float) $payment->amount,
                        'total_amount' => (float) $payment->amount,
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
            $chargesTotal = (float) ($invoice->charges_total ?? $invoice->total_amount ?? 0);
            $paymentsTotal = (float) ($invoice->payments_total ?? 0);
            $balanceDue = max($chargesTotal - $paymentsTotal, 0);

            $normalizedTotals = [
                'amount_paid' => $paymentsTotal,
                'is_paid' => $chargesTotal > 0 ? $balanceDue <= 0.01 : false,
                'status' => $chargesTotal > 0 && $balanceDue <= 0.01
                    ? Invoice::STATUS_PAID
                    : Invoice::STATUS_SENT,
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
            ->whereBetween('scheduled_date', [
                $start->copy()->startOfDay()->toDateTimeString(),
                $end->copy()->endOfDay()->toDateTimeString(),
            ])
            ->whereNotNull('rep_id')
            ->whereNotIn('workflow_status', [
                Shoot::STATUS_ON_HOLD,
                Shoot::STATUS_CANCELLED,
                Shoot::STATUS_DECLINED,
            ])
            ->get();

        if ($shoots->isEmpty()) {
            return collect();
        }

        $grouped = $shoots->groupBy('rep_id');

        return DB::transaction(function () use ($grouped, $start, $end, $sendEmails) {
            $invoices = collect();

            foreach ($grouped as $repId => $repShoots) {
                $rep = $repShoots->first()->rep ?: User::find($repId);
                if (!$this->isActiveSalesRep($rep)) {
                    continue;
                }

                $commissionRate = $this->resolveSalesCommissionRate($rep);
                $commissionRows = $repShoots
                    ->map(fn (Shoot $shoot) => $this->buildSalesRepCommissionRow($shoot, $commissionRate))
                    ->values();
                $grossTotal = round((float) $commissionRows->sum('commissionable_gross'), 2);
                $excludedFeesTotal = round((float) $commissionRows->sum('excluded_fees_total'), 2);
                $commissionTotal = round((float) $commissionRows->sum('commission_amount'), 2);
                $invoiceNotes = sprintf(
                    'Commission rate: %s%% on $%s commissionable gross. Excluded fees: $%s.',
                    $commissionRate,
                    number_format($grossTotal, 2),
                    number_format($excludedFeesTotal, 2)
                );
                $shootIds = $repShoots->pluck('id')->all();
                $warnings = $commissionRows
                    ->flatMap(fn (array $row) => $row['warnings'])
                    ->values()
                    ->all();

                // Check if invoice already exists
                $existingInvoice = Invoice::where('sales_rep_id', $repId)
                    ->whereNull('photographer_id')
                    ->where('billing_period_start', $start->toDateString())
                    ->where('billing_period_end', $end->toDateString())
                    ->first();

                if ($existingInvoice) {
                    if ($existingInvoice->isAccountsApproved() || $existingInvoice->status === Invoice::STATUS_PAID) {
                        $existingInvoice->recordAuditEvent('recalculation_skipped', null, 'Approved or paid sales rep invoice was not recalculated.', [
                            'commissionable_gross' => $grossTotal,
                            'commission_total' => $commissionTotal,
                            'warnings' => $warnings,
                        ]);
                        $invoices->push($existingInvoice->fresh(['salesRep', 'items', 'shoots']));
                        continue;
                    }

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

                    $invoiceUpdateData = [
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

                // Sync shoots
                $invoice->shoots()->sync($shootIds);

                // Refresh totals
                $invoice->refreshTotals();
                $invoice->recordAuditEvent('generated', null, 'Sales rep commission invoice generated.', [
                    'commissionable_gross' => $grossTotal,
                    'excluded_fees_total' => $excludedFeesTotal,
                    'commission_rate' => $commissionRate,
                    'commission_total' => $commissionTotal,
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
                            'error' => $e->getMessage()
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
        $end = now()->startOfWeek(Carbon::SUNDAY)->subDay()->endOfDay();
        $start = $end->copy()->startOfWeek(Carbon::SUNDAY);

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

            if ($this->looksLikeExcludedFee($service->name ?? '') && !$line['excluded']) {
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
        if (!$user) {
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
     * 
     * @param Shoot $shoot
     * @return Invoice|null
     */
    public function generateForShoot(Shoot $shoot): ?Invoice
    {
        // Check if invoice already exists for this shoot
        $existingInvoice = Invoice::where('shoot_id', $shoot->id)->first();
        if ($existingInvoice) {
            // Always refresh items/totals to reflect current services
            $shoot->load(['services', 'payments' => function ($query) {
                $query->where('status', Payment::STATUS_COMPLETED);
            }]);

            // Remove existing charge items for this shoot
            $existingInvoice->items()
                ->where('type', InvoiceItem::TYPE_CHARGE)
                ->where('shoot_id', $shoot->id)
                ->delete();

            foreach ($shoot->services as $service) {
                $servicePrice = (float) ($service->pivot->price ?? $service->price ?? 0);
                $quantity = (int) ($service->pivot->quantity ?? 1);
                $description = $service->name ?? $service->service_name ?? 'Service';

                if (stripos($description, 'floor plan') !== false || stripos($description, 'floorplan') !== false) {
                    $description .= ' (1-2999 SQFT)';
                } elseif (stripos($description, 'hdr') !== false || stripos($description, 'photo') !== false) {
                    $propertyDetails = is_array($shoot->property_details) ? $shoot->property_details : (is_string($shoot->property_details) ? json_decode($shoot->property_details, true) : []);
                    $sqft = $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? 0;
                    if ($sqft >= 1501 && $sqft <= 3000) {
                        $description .= ' (1501-3000 SQFT)';
                    } elseif ($sqft >= 3001 && $sqft <= 5000) {
                        $description .= ' (3001-5000 SQFT)';
                    } elseif ($sqft >= 5001 && $sqft <= 7000) {
                        $description .= ' (5001-7000 SQFT)';
                    } elseif ($sqft >= 7001 && $sqft <= 10000) {
                        $description .= ' (7001-10000 SQFT)';
                    } else {
                        $description .= ' (1-1500 SQFT)';
                    }
                }

                $existingInvoice->items()->create([
                    'shoot_id' => $shoot->id,
                    'type' => InvoiceItem::TYPE_CHARGE,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_amount' => $servicePrice,
                    'total_amount' => $servicePrice * $quantity,
                    'recorded_at' => $shoot->scheduled_at ?? $shoot->scheduled_date,
                    'meta' => [
                        'service_id' => $service->id,
                        'service_name' => $service->name ?? $service->service_name,
                    ],
                ]);
            }

            // Update invoice totals
            $subtotal = (float) ($shoot->base_quote ?? 0);
            $taxAmount = (float) ($shoot->tax_amount ?? 0);
            $total = (float) ($shoot->total_quote ?? $subtotal + $taxAmount);
            $totalPaid = (float) $shoot->payments->where('status', Payment::STATUS_COMPLETED)->sum('amount');

            $existingInvoice->update([
                'subtotal' => $subtotal,
                'tax' => $taxAmount,
                'total' => $total,
                'total_amount' => $total,
                'amount_paid' => $totalPaid,
                'is_paid' => $total > 0 ? $totalPaid >= $total : false,
                'status' => $totalPaid >= $total ? Invoice::STATUS_PAID : ($existingInvoice->status ?? Invoice::STATUS_SENT),
            ]);

            return $existingInvoice->fresh(['shoot', 'client', 'photographer', 'items']);
        }

        return DB::transaction(function () use ($shoot) {
            // Load shoot relationships
            $shoot->load(['client', 'photographer', 'services', 'payments' => function ($query) {
                $query->where('status', Payment::STATUS_COMPLETED);
            }]);

            // Generate invoice number (format: Invoice 02195)
            $lastInvoice = Invoice::whereNotNull('invoice_number')
                ->orderBy('id', 'desc')
                ->first();
            
            $invoiceNumber = 'Invoice ' . str_pad(
                $lastInvoice ? ((int) preg_replace('/\D/', '', $lastInvoice->invoice_number)) + 1 : 1,
                5,
                '0',
                STR_PAD_LEFT
            );

            // Calculate totals from shoot data
            $subtotal = (float) ($shoot->base_quote ?? 0);
            $taxAmount = (float) ($shoot->tax_amount ?? 0);
            $taxRate = $taxAmount > 0 && $subtotal > 0 ? ($taxAmount / $subtotal) * 100 : 0;
            $total = (float) ($shoot->total_quote ?? $subtotal + $taxAmount);
            $totalPaid = (float) $shoot->payments->where('status', Payment::STATUS_COMPLETED)->sum('amount');

            // Create invoice
            // Note: user_id, role, period_start, and period_end are required by the original schema
            // For shoot-based invoices, we use client_id as user_id and set appropriate period dates
            $shootDate = $shoot->scheduled_at ? Carbon::parse($shoot->scheduled_at) : now();
            $periodStart = $shootDate->copy()->startOfDay()->toDateString();
            $periodEnd = $shootDate->copy()->endOfDay()->toDateString();
            
            $userId = $this->determineInvoiceUserId($shoot);

            $invoiceData = [
                'user_id' => $userId,
                'role' => Invoice::ROLE_CLIENT,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'invoice_number' => $invoiceNumber,
                'issue_date' => now(),
                'due_date' => $shoot->scheduled_at ? Carbon::parse($shoot->scheduled_at)->addDays(30) : now()->addDays(30),
                'subtotal' => $subtotal,
                'tax' => $taxAmount,
                'total' => $total,
                'total_amount' => $total,
                'amount_paid' => $totalPaid,
                'is_paid' => $total > 0 ? $totalPaid >= $total : false,
                'is_sent' => true,
                'status' => $totalPaid >= $total ? Invoice::STATUS_PAID : Invoice::STATUS_SENT,
                'paid_at' => $totalPaid >= $total ? now() : null,
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

            // Create invoice items for each service
            foreach ($shoot->services as $service) {
                $servicePrice = (float) ($service->pivot->price ?? $service->price ?? 0);
                $quantity = (int) ($service->pivot->quantity ?? 1);

                // Get service description
                $description = $service->name ?? $service->service_name ?? 'Service';
                
                // Add service-specific descriptions
                if (stripos($description, 'floor plan') !== false || stripos($description, 'floorplan') !== false) {
                    $description .= ' (1-2999 SQFT)';
                } elseif (stripos($description, 'hdr') !== false || stripos($description, 'photo') !== false) {
                    $propertyDetails = is_array($shoot->property_details) ? $shoot->property_details : (is_string($shoot->property_details) ? json_decode($shoot->property_details, true) : []);
                    $sqft = $propertyDetails['sqft'] ?? $propertyDetails['squareFeet'] ?? 0;
                    if ($sqft >= 1501 && $sqft <= 3000) {
                        $description .= ' (1501-3000 SQFT)';
                    } elseif ($sqft >= 3001 && $sqft <= 5000) {
                        $description .= ' (3001-5000 SQFT)';
                    } elseif ($sqft >= 5001 && $sqft <= 7000) {
                        $description .= ' (5001-7000 SQFT)';
                    } elseif ($sqft >= 7001 && $sqft <= 10000) {
                        $description .= ' (7001-10000 SQFT)';
                    } else {
                        $description .= ' (1-1500 SQFT)';
                    }
                }

                $invoice->items()->create([
                    'shoot_id' => $shoot->id,
                    'type' => InvoiceItem::TYPE_CHARGE,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_amount' => $servicePrice,
                    'total_amount' => $servicePrice * $quantity,
                    'recorded_at' => $shoot->scheduled_at ?? $shoot->scheduled_date,
                    'meta' => [
                        'service_id' => $service->id,
                        'service_name' => $service->name ?? $service->service_name,
                    ],
                ]);
            }

            return $invoice->fresh(['shoot', 'client', 'photographer', 'items']);
        });
    }

    /**
     * Generate a cancellation fee invoice for a shoot
     * 
     * @param Shoot $shoot
     * @param float $cancellationFee
     * @return Invoice|null
     */
    public function generateCancellationFeeInvoice(Shoot $shoot, float $cancellationFee = 60.00): ?Invoice
    {
        return DB::transaction(function () use ($shoot, $cancellationFee) {
            $shoot->load(['client']);

            // Generate invoice number
            $lastInvoice = Invoice::whereNotNull('invoice_number')
                ->orderBy('id', 'desc')
                ->first();
            
            $invoiceNumber = 'Invoice ' . str_pad(
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
            $note = 'Cancellation fee for shoot at ' . $shoot->address;
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
                'description' => 'Cancellation Fee - ' . $shoot->address,
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
            $columns = Schema::getColumnListing((new Invoice())->getTable());
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

        return 'Invoice ' . str_pad((string) ($lastNumber + 1), 5, '0', STR_PAD_LEFT);
    }
}
