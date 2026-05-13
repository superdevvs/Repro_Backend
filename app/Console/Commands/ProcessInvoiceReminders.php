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

    /**
     * Fixed reminder offsets (in days past the due date) before the recurring
     * 30-day cadence kicks in.
     */
    private const OVERDUE_FIXED_OFFSETS = [1, 2, 3, 7, 30];

    /**
     * After the final fixed offset, reminders repeat every N days until the
     * invoice balance reaches zero.
     */
    private const OVERDUE_RECURRING_INTERVAL_DAYS = 30;

    public function handle(AutomationService $automationService): int
    {
        $today = now()->startOfDay();

        $dueInvoices = $this->clientInvoiceQuery()
            ->whereDate('due_date', $today)
            ->get();

        $overdueInvoices = $this->clientInvoiceQuery()
            ->whereDate('due_date', '<', $today)
            ->get();

        $dueCount = $this->processDueInvoices($automationService, $dueInvoices);
        $overdueCount = $this->processOverdueInvoices($automationService, $overdueInvoices, $today);

        $this->info(sprintf('Invoice reminders sent: %d due, %d overdue', $dueCount, $overdueCount));

        return Command::SUCCESS;
    }

    private function clientInvoiceQuery()
    {
        return Invoice::query()
            ->whereNotNull('due_date')
            ->where(function ($query) {
                $query->where('role', Invoice::ROLE_CLIENT)
                    ->orWhere(function ($legacy) {
                        $legacy->whereNull('role')->whereNotNull('client_id');
                    });
            })
            ->with(['client', 'salesRep', 'shoot.client', 'shoot.rep', 'shoots.client', 'shoots.rep']);
    }

    private function processDueInvoices(AutomationService $automationService, Collection $invoices): int
    {
        $sent = 0;

        foreach ($invoices as $invoice) {
            $tag = sprintf('INVOICE_DUE:%s:due', $invoice->id);

            if ($this->dispatchReminder($automationService, $invoice, 'INVOICE_DUE', $tag)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function processOverdueInvoices(
        AutomationService $automationService,
        Collection $invoices,
        Carbon $today
    ): int {
        $sent = 0;

        foreach ($invoices as $invoice) {
            $dueDate = $invoice->due_date instanceof Carbon
                ? $invoice->due_date->copy()->startOfDay()
                : Carbon::parse($invoice->due_date)->startOfDay();
            $daysOverdue = (int) $dueDate->diffInDays($today, false);

            if (!$this->isScheduledOverdueOffset($daysOverdue)) {
                continue;
            }

            $tag = sprintf('INVOICE_OVERDUE:%s:%dd', $invoice->id, $daysOverdue);

            if ($this->dispatchReminder($automationService, $invoice, 'INVOICE_OVERDUE', $tag)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function isScheduledOverdueOffset(int $daysOverdue): bool
    {
        if ($daysOverdue <= 0) {
            return false;
        }

        if (in_array($daysOverdue, self::OVERDUE_FIXED_OFFSETS, true)) {
            return true;
        }

        $lastFixed = max(self::OVERDUE_FIXED_OFFSETS);

        return $daysOverdue > $lastFixed
            && ($daysOverdue - $lastFixed) % self::OVERDUE_RECURRING_INTERVAL_DAYS === 0;
    }

    private function dispatchReminder(
        AutomationService $automationService,
        Invoice $invoice,
        string $triggerType,
        string $tag
    ): bool {
        if ($invoice->balanceDue() <= 0) {
            return false;
        }

        $client = $this->resolveClient($invoice);
        if (!$client) {
            return false;
        }

        if ($this->alreadySent($tag)) {
            return false;
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

        return true;
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
