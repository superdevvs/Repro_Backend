<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Shoot;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                    $amount = $shoot->base_quote ?? $shoot->total_quote ?? 0;
                    
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
                        ],
                    ]);
                }

                // Calculate totals
                $totalAmount = $photographerShoots->sum(fn (Shoot $shoot) => (float) ($shoot->total_quote ?? 0));
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
}
