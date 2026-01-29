<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ProcessInvoiceReminders extends Command
{
    protected $signature = 'messaging:invoice-reminders';
    protected $description = 'Send invoice due and overdue reminders via automation rules';

    public function handle(AutomationService $automationService): int
    {
        $today = now()->startOfDay();

        $dueInvoices = Invoice::query()
            ->whereNotNull('due_date')
            ->whereDate('due_date', $today)
            ->with(['client', 'salesRep', 'shoot.client', 'shoot.rep', 'shoots.client', 'shoots.rep'])
            ->get();

        $overdueInvoices = Invoice::query()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->with(['client', 'salesRep', 'shoot.client', 'shoot.rep', 'shoots.client', 'shoots.rep'])
            ->get();

        $dueCount = $this->processInvoices($automationService, $dueInvoices, 'INVOICE_DUE', $today);
        $overdueCount = $this->processInvoices($automationService, $overdueInvoices, 'INVOICE_OVERDUE', $today);

        $this->info(sprintf('Invoice reminders sent: %d due, %d overdue', $dueCount, $overdueCount));

        return Command::SUCCESS;
    }

    private function processInvoices(
        AutomationService $automationService,
        Collection $invoices,
        string $triggerType,
        Carbon $today
    ): int {
        $sent = 0;

        foreach ($invoices as $invoice) {
            if ($invoice->balanceDue() <= 0) {
                continue;
            }

            $client = $this->resolveClient($invoice);
            if (!$client) {
                continue;
            }

            $tag = sprintf('%s:%s:%s', $triggerType, $invoice->id, $today->toDateString());
            if ($this->alreadySent($tag)) {
                continue;
            }

            $context = [
                'invoice' => $invoice,
                'invoice_id' => $invoice->id,
                'client' => $client,
                'account_id' => $client->id,
                'tags_json' => [$tag],
            ];

            $rep = $this->resolveRep($invoice, $client);
            if ($rep) {
                $context['rep'] = $rep;
            }

            $automationService->handleEvent($triggerType, $context);
            $sent++;
        }

        return $sent;
    }

    private function resolveClient(Invoice $invoice): ?User
    {
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

    private function alreadySent(string $tag): bool
    {
        return Message::query()
            ->where('send_source', 'AUTOMATION')
            ->where('tags_json', 'like', '%' . $tag . '%')
            ->exists();
    }
}
