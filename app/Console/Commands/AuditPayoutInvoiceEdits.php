<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class AuditPayoutInvoiceEdits extends Command
{
    protected $signature = 'invoices:audit-payout-edits {--invoice=* : Limit to exact invoice IDs}';

    protected $description = 'Read-only report of payout edits potentially lost to historical regeneration; never restores amounts or sends mail.';

    public function handle(): int
    {
        $rows = Invoice::query()->whereIn('role', [Invoice::ROLE_PHOTOGRAPHER, Invoice::ROLE_SALES_REP])
            ->when($this->option('invoice'), fn ($q) => $q->whereIn('id', $this->option('invoice')))
            ->whereHas('auditEvents', fn ($q) => $q->whereIn('event', ['payee_edit', 'admin_edit']))
            ->with(['auditEvents', 'photographer', 'salesRep'])->get()->map(function (Invoice $invoice) {
                $review = $invoice->payout_review;
                if (! $review['recovery_required']) {
                    return null;
                }

                return [
                    'invoice_id' => $invoice->id,
                    'payee' => $invoice->photographer?->name ?? $invoice->salesRep?->name,
                    'current_total' => (float) $invoice->total_amount,
                    'recovery' => $review['recovery_required'],
                    'historical_edits' => $review['changes'],
                    'action' => 'Accounts must verify each service, date, rate and previous payout, then reapply intended corrections in Edit invoice. Do not blindly restore historical totals.',
                ];
            })->filter()->values();
        $this->line(json_encode(['read_only' => true, 'invoices' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
