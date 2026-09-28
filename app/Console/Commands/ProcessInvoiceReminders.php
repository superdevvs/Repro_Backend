<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\ScheduledAutomationDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessInvoiceReminders extends Command
{
    protected $signature = 'messaging:invoice-reminders';

    protected $description = 'Send invoice due and overdue reminders via automation rules';

    /**
     * Fixed reminder offsets (in days past the due date) before the recurring
     * 30-day cadence kicks in.
     */
    private const OVERDUE_FIXED_OFFSETS = [1, 3, 7, 14, 30];

    /**
     * After the final fixed offset, reminders repeat every N days until the
     * invoice balance reaches zero.
     */
    private const OVERDUE_RECURRING_INTERVAL_DAYS = 30;

    public function handle(AutomationService $automationService): int
    {
        $now = now();
        $today = $now->copy()->startOfDay();
        $dispatcher = app(ScheduledAutomationDispatcher::class);
        $sent = 0;

        $rules = AutomationRule::active()
            ->whereIn('trigger_type', ['INVOICE_DUE', 'INVOICE_OVERDUE'])
            ->get();

        foreach ($rules as $rule) {
            if (! $dispatcher->dailyTimeReached($rule, $now, '09:30')) {
                continue;
            }

            $query = $this->clientInvoiceQuery();
            if ($rule->trigger_type === 'INVOICE_DUE') {
                $daysBefore = max(0, (int) ($rule->schedule_json['days_before'] ?? 0));
                $query->whereDate('due_date', $today->copy()->addDays($daysBefore));
            } else {
                $query->whereDate('due_date', '<', $today);
            }

            foreach ($query->get() as $invoice) {
                $daysOverdue = (int) Carbon::parse($invoice->due_date)->startOfDay()->diffInDays($today, false);
                if ($rule->trigger_type === 'INVOICE_OVERDUE' && ! $this->isScheduledOverdueOffset($daysOverdue, $rule)) {
                    continue;
                }

                $tag = sprintf('%s:%s:%dd', $rule->trigger_type, $invoice->id, $daysOverdue);
                $key = sprintf('invoice:%d:due:%s:day:%s', $invoice->id, $invoice->due_date->toDateString(), $today->toDateString());
                if ($this->dispatchReminder($invoice, $rule, $tag, $key)) {
                    $sent++;
                }
            }
        }

        $this->info(sprintf('Invoice reminders dispatched: %d', $sent));

        return Command::SUCCESS;
    }

    private function clientInvoiceQuery()
    {
        return Invoice::query()
            ->whereNotNull('due_date')
            ->whereNotIn('status', ['draft', 'paid', 'cancelled', 'canceled', 'void'])
            ->where(function ($query) {
                $query->where('role', Invoice::ROLE_CLIENT)
                    ->orWhere(function ($legacy) {
                        $legacy->whereNull('role')->whereNotNull('client_id');
                    });
            })
            ->with([
                'client',
                'salesRep',
                'shoot.client',
                'shoot.rep',
                'shoots.client',
                'shoots.rep',
                'items.shoot.client',
                'items.shoot.rep',
            ]);
    }

    private function isScheduledOverdueOffset(int $daysOverdue, AutomationRule $rule): bool
    {
        if ($daysOverdue <= 0) {
            return false;
        }

        $offsets = collect($rule->schedule_json['overdue_days'] ?? self::OVERDUE_FIXED_OFFSETS)
            ->filter(fn ($day) => is_numeric($day) && (int) $day > 0)
            ->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();

        if (in_array($daysOverdue, $offsets, true)) {
            return true;
        }

        if ($offsets === []) {
            return false;
        }
        $lastFixed = max($offsets);
        $interval = (int) ($rule->schedule_json['repeat_every_days'] ?? self::OVERDUE_RECURRING_INTERVAL_DAYS);

        return $interval > 0 && $daysOverdue > $lastFixed
            && ($daysOverdue - $lastFixed) % $interval === 0;
    }

    private function dispatchReminder(
        Invoice $invoice,
        AutomationRule $rule,
        string $tag,
        string $key
    ): bool {
        if ($invoice->suppressesExternalNotifications() || $invoice->balanceDue() <= 0) {
            return false;
        }

        $client = $this->resolveClient($invoice);
        if (! $client) {
            return false;
        }

        $dueDate = $invoice->due_date instanceof Carbon
            ? $invoice->due_date->copy()->startOfDay()
            : Carbon::parse($invoice->due_date)->startOfDay();
        $today = now()->startOfDay();
        $daysOverdue = max(0, (int) $dueDate->diffInDays($today, false));
        $reminderStage = $rule->trigger_type === 'INVOICE_DUE' ? 'due' : $this->reminderStageForDaysOverdue($daysOverdue);
        $amountDue = round((float) $invoice->balanceDue(), 2);
        $shootContext = $this->resolveShootContext($invoice);

        $context = array_merge([
            'invoice' => $invoice,
            'invoice_id' => $invoice->id,
            'reminder_stage' => $reminderStage,
            'days_overdue' => $daysOverdue,
            'amount_due' => $amountDue,
            'due_date' => $dueDate->toDateString(),
            'invoice_number' => $invoice->invoice_number,
            'payment_link' => $invoice->paymentLink(),
            'client_name' => $client->name,
            'client' => $client,
            'account_id' => $client->id,
            'tags_json' => [$tag],
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'reminder_stage' => $reminderStage,
                'days_overdue' => $daysOverdue,
                'amount_due' => $amountDue,
                'due_date' => $dueDate->toDateString(),
                'payment_link' => $invoice->paymentLink(),
                'client_name' => $client->name,
            ],
        ], $shootContext);

        if ($shootMetadata = $this->shootMetadata($shootContext)) {
            $context['metadata'] = array_merge($context['metadata'], $shootMetadata);
        }

        $rep = $this->resolveRep($invoice, $client);
        if ($rep) {
            $context['rep'] = $rep;
        }

        return app(ScheduledAutomationDispatcher::class)->dispatch($rule, $context, $key);
    }

    /**
     * Resolve one unambiguous property for reminder copy. The direct invoice
     * foreign key wins; otherwise pivot and line-item relationships are
     * de-duplicated before deciding whether a single address is safe to show.
     *
     * @return array<string, mixed>
     */
    private function resolveShootContext(Invoice $invoice): array
    {
        if ($invoice->shoot instanceof Shoot) {
            return [
                'shoot' => $invoice->shoot,
                'shoot_id' => $invoice->shoot->id,
            ];
        }

        $relatedShoots = collect($invoice->shoots ?? [])
            ->merge(collect($invoice->items ?? [])->pluck('shoot')->filter())
            ->filter(fn (mixed $shoot) => $shoot instanceof Shoot)
            ->unique(fn (Shoot $shoot) => (int) $shoot->id)
            ->values();

        if ($relatedShoots->count() === 1) {
            /** @var Shoot $shoot */
            $shoot = $relatedShoots->first();

            return [
                'shoot' => $shoot,
                'shoot_id' => $shoot->id,
            ];
        }

        if ($relatedShoots->count() > 1) {
            return [
                'shoot_location' => 'Multiple properties',
                'shoot_address' => 'Multiple properties',
                'related_shoot_count' => $relatedShoots->count(),
            ];
        }

        return [
            'shoot_location' => 'Property details unavailable',
            'shoot_address' => 'Property details unavailable',
            'related_shoot_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $shootContext
     * @return array<string, int|string>
     */
    private function shootMetadata(array $shootContext): array
    {
        return collect($shootContext)
            ->only(['shoot_id', 'shoot_location', 'shoot_address', 'related_shoot_count'])
            ->all();
    }

    private function reminderStageForDaysOverdue(int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'overdue';
        }

        return sprintf('overdue_%dd', $daysOverdue);
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
        if (! is_array($metadata)) {
            return null;
        }

        $repId = $metadata['accountRepId']
            ?? $metadata['account_rep_id']
            ?? $metadata['repId']
            ?? $metadata['rep_id']
            ?? null;

        if (! $repId) {
            return null;
        }

        return User::find($repId);
    }
}
