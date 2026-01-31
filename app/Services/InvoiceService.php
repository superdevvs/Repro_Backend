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
                'services', // Load services to calculate photographer pay
            ])
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('photographer_id')
            ->whereIn('workflow_status', [
                Shoot::WORKFLOW_COMPLETED,
                Shoot::WORKFLOW_ADMIN_VERIFIED,
            ])
            ->get();

        if ($shoots->isEmpty()) {
            return collect();
        }

        $grouped = $shoots->groupBy('photographer_id');

        return DB::transaction(function () use ($grouped, $start, $end, $sendEmails) {
            $invoices = collect();

            foreach ($grouped as $photographerId => $photographerShoots) {
                // Check if invoice already exists
                $existingInvoice = Invoice::where('photographer_id', $photographerId)
                    ->where('billing_period_start', $start->toDateString())
                    ->where('billing_period_end', $end->toDateString())
                    ->first();

                if ($existingInvoice) {
                    // Skip if invoice already exists
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

                // Create invoice items for each shoot
                foreach ($photographerShoots as $shoot) {
                    // Use photographer pay from services, fallback to total_quote if not set
                    $amount = $shoot->total_photographer_pay ?? 0;
                    
                    // If no photographer pay is set in services, use total_quote as fallback
                    if ($amount == 0) {
                        $amount = $shoot->base_quote ?? $shoot->total_quote ?? 0;
                    }
                    
                    $invoice->items()->create([
                        'shoot_id' => $shoot->id,
                        'type' => InvoiceItem::TYPE_CHARGE,
                        'description' => sprintf('Shoot #%d - %s', $shoot->id, $shoot->address ?? 'Location TBD'),
                        'quantity' => 1,
                        'unit_amount' => $amount,
                        'total_amount' => $amount,
                        'recorded_at' => $shoot->scheduled_date,
                        'meta' => [
                            'workflow_status' => $shoot->workflow_status,
                            'photographer_pay_from_services' => $shoot->total_photographer_pay > 0,
                        ],
                    ]);
                }

                // Calculate totals using photographer pay from services
                $totalAmount = $photographerShoots->sum(function (Shoot $shoot) {
                    $photographerPay = $shoot->total_photographer_pay ?? 0;
                    // Fallback to total_quote if no photographer pay is set
                    return $photographerPay > 0 ? $photographerPay : (float) ($shoot->total_quote ?? 0);
                });
                $amountPaid = $photographerShoots
                    ->flatMap(fn (Shoot $shoot) => $shoot->payments)
                    ->sum(fn ($payment) => (float) $payment->amount);

                $invoice->update([
                    'total_amount' => $totalAmount,
                    'amount_paid' => $amountPaid,
                    'is_paid' => $totalAmount > 0 ? $amountPaid >= $totalAmount : false,
                ]);

                // Sync shoots
                $invoice->shoots()->sync($photographerShoots->pluck('id')->all());

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
     * Generate invoices for the last completed calendar week.
     */
    public function generateForLastCompletedWeek(bool $sendEmails = false): Collection
    {
        $end = now()->startOfWeek()->subDay()->endOfDay();
        $start = $end->copy()->startOfWeek();

        return $this->generateForPeriod($start, $end, $sendEmails);
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
