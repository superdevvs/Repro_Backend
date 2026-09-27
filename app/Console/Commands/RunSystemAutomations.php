<?php

namespace App\Console\Commands;

use App\Models\AutomationDispatch;
use App\Models\AutomationRule;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\AutomationWorkflowValidator;
use App\Services\Messaging\ScheduledAutomationDispatcher;
use App\Services\Messaging\WeeklyAutomationContext;
use App\Services\Messaging\WeeklyDigestContexts;
use App\Services\SalesReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunSystemAutomations extends Command
{
    protected $signature = 'automations:run-system {--trigger=} {--rule=} {--force}';

    protected $description = 'Prepare due weekly invoices and reports, then execute their saved automation actions';

    public function handle(ScheduledAutomationDispatcher $dispatcher, WeeklyAutomationContext $contexts): int
    {
        $rules = AutomationRule::active()
            ->where('scope', 'SYSTEM')
            ->whereIn('trigger_type', ['WEEKLY_AUTOMATED_INVOICING', 'WEEKLY_SALES_REPORT', 'INVOICE_SUMMARY', 'WEEKLY_REP_INVOICE', 'WEEKLY_PAYOUT_REPORT', 'WEEKLY_PAYOUT_DIGEST'])
            ->when($this->option('trigger'), fn ($query) => $query->where('trigger_type', $this->option('trigger')))
            ->when($this->option('rule'), fn ($query) => $query->whereKey($this->option('rule')))
            ->orderBy('trigger_type')->get();
        $failed = 0;
        $generatedInvoices = null;
        $generatedInvoiceIds = [];

        // Missing or disabled rules deliberately stay that way. Creation and repair
        // belong to migrations, never to a scheduler that might undo an admin edit.
        foreach ($rules as $rule) {
            $scheduledFor = $this->scheduledFor($rule, now());
            if (! $scheduledFor) {
                continue;
            }
            $periodKey = $rule->trigger_type.'|'.$scheduledFor->toDateString();
            $dispatch = AutomationDispatch::firstOrNew([
                'automation_rule_id' => $rule->id,
                'period_key' => $periodKey,
            ]);
            if ($dispatch->status === 'completed' && ! $this->option('force')) {
                continue;
            }

            $dispatch->fill([
                'trigger_type' => $rule->trigger_type,
                'scheduled_for' => $scheduledFor,
                'command' => 'workflow:'.$rule->trigger_type,
                'status' => 'running',
                'error_message' => null,
                'started_at' => now(),
                'completed_at' => null,
            ])->save();

            try {
                $workflow = app(AutomationWorkflowConverter::class)->getWorkflowDefinition($rule);
                $validation = app(AutomationWorkflowValidator::class)->validate($workflow);
                if (! $validation['valid']) {
                    throw new \RuntimeException('Weekly automation has an invalid workflow.');
                }
                $delivered = 0;
                $invoiceIds = [];
                if ($rule->trigger_type === 'WEEKLY_AUTOMATED_INVOICING') {
                    // Generation is bookkeeping. All delivery belongs to the visible
                    // workflow, including its channels, template, recipients and filters.
                    if ($generatedInvoices === null) {
                        $generatedInvoices = app(InvoiceService::class)->generateForLastCompletedWeek(false);
                        $generatedInvoiceIds = $generatedInvoices->filter(fn ($invoice) => $invoice->created_at
                            && $invoice->created_at->gte($dispatch->created_at->copy()->startOfSecond()))->pluck('id')->all();
                    }
                    // Every due rule sees the same generation batch. Persist its IDs
                    // before delivery so a failed clone can retry without regenerating
                    // notifications for unrelated historical or manual invoices.
                    $previousBatch = json_decode((string) $dispatch->output, true);
                    $invoiceIds = array_values(array_unique(array_merge($generatedInvoiceIds, (array) ($previousBatch['invoice_ids'] ?? []))));
                    $dispatch->update(['output' => json_encode(['invoice_ids' => $invoiceIds])]);
                    foreach ($generatedInvoices as $invoice) {
                        if ((float) ($invoice->total_amount ?? $invoice->total) <= 0
                            || ! in_array($invoice->id, $invoiceIds, true)) {
                            continue;
                        }
                        $key = 'weekly-invoice:'.$invoice->id;
                        if ($dispatcher->alreadyDispatched($rule, $key)) {
                            continue;
                        }
                        if (! $dispatcher->dispatch($rule, $contexts->invoice($invoice), $key)) {
                            throw new \RuntimeException('Weekly invoice workflow did not complete.');
                        }
                        $delivered++;
                    }
                } elseif ($rule->trigger_type === 'WEEKLY_SALES_REPORT') {
                    $reports = app(SalesReportService::class);
                    [$start, $end] = $reports->getLastCompletedWeek();
                    foreach ($reports->generateWeeklyReportsForAllSalesReps($start, $end) as $report) {
                        if (! $contexts->hasReportActivity($report)) {
                            continue;
                        }
                        $rep = User::find(data_get($report, 'sales_rep.id'));
                        if (! $rep || ! $reports->isSalesRep($rep)) {
                            continue;
                        }
                        $key = 'weekly-report:'.$rep->id.':'.$start->toDateString();
                        if ($dispatcher->alreadyDispatched($rule, $key)) {
                            continue;
                        }
                        if (! $dispatcher->dispatch($rule, $contexts->report($rep, $report), $key)) {
                            throw new \RuntimeException('Weekly sales report workflow did not complete.');
                        }
                        $delivered++;
                    }
                } else {
                    foreach (app(WeeklyDigestContexts::class)->forRule($rule) as $key => $context) {
                        if ($dispatcher->alreadyDispatched($rule, $key)) {
                            continue;
                        }
                        if (! $dispatcher->dispatch($rule, $context, $key)) {
                            throw new \RuntimeException('Weekly summary workflow did not complete.');
                        }
                        $delivered++;
                    }
                }
                $dispatch->update([
                    'status' => 'completed',
                    'output' => json_encode(['invoice_ids' => $invoiceIds, 'dispatched_count' => $delivered]),
                    'completed_at' => now(),
                ]);
                $this->info($rule->name.': '.$delivered.' workflow context(s) dispatched.');
            } catch (\Throwable $exception) {
                $failed++;
                $dispatch->update([
                    'status' => 'failed',
                    'error_message' => 'Weekly automation could not complete. Review its run history and configuration.',
                    'completed_at' => now(),
                ]);
                Log::error('Weekly automation failed', ['rule_id' => $rule->id, 'exception' => $exception]);
                $this->error('Automation failed: '.$rule->name);
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function scheduledFor(AutomationRule $rule, Carbon $now): ?Carbon
    {
        $schedule = (array) $rule->schedule_json;
        if (($schedule['type'] ?? null) !== 'weekly') {
            return null;
        }
        $day = (int) ($schedule['day_of_week'] ?? 1);
        $time = (string) ($schedule['time'] ?? '00:00');
        if ($day < 0 || $day > 6 || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $scheduled = $now->copy()->startOfWeek(Carbon::SUNDAY)->addDays($day)->setTime($hour, $minute);

        return $now->gte($scheduled) || $this->option('force') ? $scheduled : null;
    }
}
