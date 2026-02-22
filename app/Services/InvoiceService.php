<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
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
                'services' => function ($q) {
                    $q->withPivot(['photographer_id', 'photographer_pay', 'quantity']);
                },
            ])
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
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
            foreach ($shoot->services as $service) {
                $resolvedId = $service->pivot->photographer_id ?? $fallbackId;
                if (!$resolvedId) {
                    \Log::warning('Unresolved photographer for invoice', [
                        'shoot_id' => $shoot->id,
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                    ]);
                    continue;
                }
                
                $pay = (float) ($service->pivot->photographer_pay ?? 0);
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
                // Check if invoice already exists
                $existingInvoice = Invoice::where('photographer_id', $photographerId)
                    ->where('billing_period_start', $start->toDateString())
                    ->where('billing_period_end', $end->toDateString())
                    ->first();

                if ($existingInvoice) {
                    $invoices->push($existingInvoice->fresh(['photographer', 'items', 'shoots']));
                    continue;
                }

                // Create invoice
                $invoice = Invoice::create([
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

                // Calculate totals from service rows
                $totalAmount = $photographerServices->sum('photographer_pay');
                
                // Get payments for shoots this photographer worked on
                $shootIds = $photographerServices->pluck('shoot_id')->unique();
                $relatedShoots = $shoots->whereIn('id', $shootIds);
                $amountPaid = $relatedShoots
                    ->flatMap(fn (Shoot $shoot) => $shoot->payments)
                    ->sum(fn ($payment) => (float) $payment->amount);

                $invoice->update([
                    'total_amount' => $totalAmount,
                    'amount_paid' => $amountPaid,
                    'is_paid' => $totalAmount > 0 ? $amountPaid >= $totalAmount : false,
                ]);

                // Sync shoots (use unique shoot IDs from service rows)
                $invoice->shoots()->sync($shootIds->all());

                // Refresh totals
                $invoice->refreshTotals();

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

    /**
     * Generate sales rep invoices for the provided billing period.
     */
    public function generateSalesRepInvoicesForPeriod(Carbon $start, Carbon $end, bool $sendEmails = false): Collection
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        $shoots = Shoot::with(['services', 'rep:id,name,email,role,metadata'])
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('rep_id')
            ->whereIn('workflow_status', [
                Shoot::WORKFLOW_COMPLETED,
                Shoot::WORKFLOW_ADMIN_VERIFIED,
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
                if (!$rep || $rep->role !== 'salesRep') {
                    continue;
                }

                // Check if invoice already exists
                $existingInvoice = Invoice::where('sales_rep_id', $repId)
                    ->whereNull('photographer_id')
                    ->where('billing_period_start', $start->toDateString())
                    ->where('billing_period_end', $end->toDateString())
                    ->first();

                if ($existingInvoice) {
                    $invoices->push($existingInvoice->fresh(['salesRep', 'items', 'shoots']));
                    continue;
                }

                // Calculate commission
                $commissionRate = (float) data_get($rep->metadata, 'repDetails.commissionPercentage', 0);
                $grossTotal = (float) $repShoots->sum('total_quote');
                $commissionTotal = $commissionRate > 0 ? round($grossTotal * ($commissionRate / 100), 2) : 0;

                // Create invoice
                $invoice = Invoice::create([
                    'sales_rep_id' => $repId,
                    'user_id' => $repId,
                    'role' => 'salesRep',
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'billing_period_start' => $start->toDateString(),
                    'billing_period_end' => $end->toDateString(),
                    'status' => Invoice::STATUS_DRAFT,
                    'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
                    'total_amount' => $commissionTotal,
                    'notes' => $commissionRate > 0
                        ? sprintf('Commission rate: %s%% on $%s gross', $commissionRate, number_format($grossTotal, 2))
                        : null,
                ]);

                // Create invoice items for each shoot
                foreach ($repShoots as $shoot) {
                    $shootTotal = (float) ($shoot->total_quote ?? 0);
                    $shootCommission = $commissionRate > 0
                        ? round($shootTotal * ($commissionRate / 100), 2)
                        : 0;

                    $invoice->items()->create([
                        'shoot_id' => $shoot->id,
                        'type' => InvoiceItem::TYPE_CHARGE,
                        'description' => sprintf(
                            'Shoot #%d - %s (Commission %s%% on $%s)',
                            $shoot->id,
                            $shoot->address ?? 'Location TBD',
                            $commissionRate,
                            number_format($shootTotal, 2)
                        ),
                        'quantity' => 1,
                        'unit_amount' => $shootCommission,
                        'total_amount' => $shootCommission,
                        'recorded_at' => $shoot->scheduled_date,
                        'meta' => [
                            'workflow_status' => $shoot->workflow_status,
                            'gross_amount' => $shootTotal,
                            'commission_rate' => $commissionRate,
                        ],
                    ]);
                }

                // Sync shoots
                $invoice->shoots()->sync($repShoots->pluck('id')->all());

                // Refresh totals
                $invoice->refreshTotals();

                $invoice = $invoice->fresh(['salesRep', 'items', 'shoots']);

                // Send email notification if requested
                if ($sendEmails && $rep->email) {
                    try {
                        $this->mailService->sendInvoiceGeneratedEmail($invoice);
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
        $end = now()->startOfWeek()->subDay()->endOfDay();
        $start = $end->copy()->startOfWeek();

        $photographerInvoices = $this->generateForPeriod($start, $end, $sendEmails);
        $salesRepInvoices = $this->generateSalesRepInvoicesForPeriod($start, $end, $sendEmails);

        return $photographerInvoices->merge($salesRepInvoices);
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
                'notes' => 'Cancellation fee for shoot at ' . $shoot->address,
            ];

            $optionalColumns = [
                'billing_period_start' => $now->toDateString(),
                'billing_period_end' => $now->toDateString(),
                'shoot_id' => $shoot->id,
                'client_id' => $shoot->client_id,
            ];

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
}
