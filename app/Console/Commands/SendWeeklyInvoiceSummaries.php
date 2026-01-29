<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendWeeklyInvoiceSummaries extends Command
{
    protected $signature = 'messaging:invoice-summaries';
    protected $description = 'Send weekly invoice summaries to clients and reps via automation rules';

    public function handle(AutomationService $automationService): int
    {
        [$start, $end] = $this->getLastCompletedWeek();

        $invoices = Invoice::query()
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('issue_date', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end) {
                        $inner->whereNull('issue_date')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->with(['client', 'salesRep', 'shoot.client', 'shoot.rep', 'shoots.client', 'shoots.rep'])
            ->get();

        $clientSent = $this->sendClientSummaries($automationService, $invoices, $start, $end);
        $repSent = $this->sendRepSummaries($automationService, $invoices, $start, $end);

        $this->info(sprintf('Weekly invoice summaries sent: %d clients, %d reps', $clientSent, $repSent));

        return Command::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function getLastCompletedWeek(): array
    {
        $end = now()->startOfWeek()->subDay()->endOfDay();
        $start = $end->copy()->startOfWeek();

        return [$start, $end];
    }

    private function sendClientSummaries(
        AutomationService $automationService,
        Collection $invoices,
        Carbon $start,
        Carbon $end
    ): int {
        $sent = 0;

        $clientGroups = $invoices->groupBy(function (Invoice $invoice) {
            return $this->resolveClient($invoice)?->id;
        });

        foreach ($clientGroups as $clientId => $group) {
            if (!$clientId) {
                continue;
            }

            $client = $this->resolveClient($group->first());
            if (!$client) {
                continue;
            }

            $tag = sprintf('INVOICE_SUMMARY:client:%s:%s', $client->id, $end->toDateString());
            if ($this->alreadySent($tag)) {
                continue;
            }

            $summary = $this->buildSummary($group);

            $context = array_merge($summary, [
                'client' => $client,
                'account_id' => $client->id,
                'summary_start' => $start->toDateString(),
                'summary_end' => $end->toDateString(),
                'tags_json' => [$tag],
                'summary_invoices' => $group->map(fn (Invoice $invoice) => $this->formatInvoiceSummary($invoice))->values()->all(),
            ]);

            $rep = $this->resolveRep($group->first(), $client);
            if ($rep) {
                $context['rep'] = $rep;
            }

            $automationService->handleEvent('INVOICE_SUMMARY', $context);
            $sent++;
        }

        return $sent;
    }

    private function sendRepSummaries(
        AutomationService $automationService,
        Collection $invoices,
        Carbon $start,
        Carbon $end
    ): int {
        $sent = 0;
        $repBuckets = [];

        foreach ($invoices as $invoice) {
            $client = $this->resolveClient($invoice);
            $rep = $this->resolveRep($invoice, $client);
            if (!$rep) {
                continue;
            }

            $repBuckets[$rep->id]['rep'] = $rep;
            $repBuckets[$rep->id]['invoices'][] = $invoice;
        }

        foreach ($repBuckets as $repId => $bucket) {
            /** @var User $rep */
            $rep = $bucket['rep'];
            $group = collect($bucket['invoices'] ?? []);
            if ($group->isEmpty()) {
                continue;
            }

            $tag = sprintf('WEEKLY_REP_INVOICE:rep:%s:%s', $repId, $end->toDateString());
            if ($this->alreadySent($tag)) {
                continue;
            }

            $summary = $this->buildSummary($group);

            $context = array_merge($summary, [
                'rep' => $rep,
                'account_id' => $rep->id,
                'summary_start' => $start->toDateString(),
                'summary_end' => $end->toDateString(),
                'tags_json' => [$tag],
                'summary_invoices' => $group->map(fn (Invoice $invoice) => $this->formatInvoiceSummary($invoice))->values()->all(),
            ]);

            $automationService->handleEvent('WEEKLY_REP_INVOICE', $context);
            $sent++;
        }

        return $sent;
    }

    private function resolveClient(?Invoice $invoice): ?User
    {
        if (!$invoice) {
            return null;
        }

        if ($invoice->client) {
            return $invoice->client;
        }

        if ($invoice->shoot?->client) {
            return $invoice->shoot->client;
        }

        return $invoice->shoots?->first()?->client;
    }

    private function resolveRep(Invoice $invoice, ?User $client): ?User
    {
        if ($invoice->salesRep) {
            return $invoice->salesRep;
        }

        $repFromShoot = $invoice->shoot?->rep ?? $invoice->shoots?->first()?->rep;
        if ($repFromShoot) {
            return $repFromShoot;
        }

        $metadata = $client?->metadata ?? [];
        if (!is_array($metadata)) {
            return null;
        }

        $repId = $metadata['accountRepId']
            ?? $metadata['account_rep_id']
            ?? $metadata['repId']
            ?? $metadata['rep_id']
            ?? null;

        if (!$repId) {
            return null;
        }

        return User::find($repId);
    }

    private function buildSummary(Collection $invoices): array
    {
        $invoiceCount = $invoices->count();
        $totalInvoiced = $invoices->sum(fn (Invoice $invoice) => (float) ($invoice->total ?? $invoice->total_amount ?? $invoice->charges_total ?? 0));
        $totalPaid = $invoices->sum(fn (Invoice $invoice) => (float) ($invoice->amount_paid ?? $invoice->payments_total ?? 0));
        $totalOutstanding = $invoices->sum(fn (Invoice $invoice) => $invoice->balanceDue());

        return [
            'summary_invoice_count' => $invoiceCount,
            'summary_total_invoiced' => round($totalInvoiced, 2),
            'summary_total_paid' => round($totalPaid, 2),
            'summary_total_outstanding' => round($totalOutstanding, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatInvoiceSummary(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'total' => $invoice->total ?? $invoice->total_amount ?? $invoice->charges_total ?? 0,
            'amount_paid' => $invoice->amount_paid ?? $invoice->payments_total ?? 0,
            'balance_due' => $invoice->balanceDue(),
            'status' => $invoice->status,
        ];
    }

    private function alreadySent(string $tag): bool
    {
        return Message::query()
            ->where('send_source', 'AUTOMATION')
            ->where('tags_json', 'like', '%' . $tag . '%')
            ->exists();
    }
}
