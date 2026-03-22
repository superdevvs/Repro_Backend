<?php

namespace App\Console\Commands;

use App\Models\AutomationDispatch;
use App\Models\AutomationRule;
use App\Services\Messaging\AutomationWorkflowExecutor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class RunSystemAutomations extends Command
{
    protected $signature = 'automations:run-system {--trigger=} {--force}';

    protected $description = 'Run due system automation commands such as weekly invoicing and weekly sales reports';

    public function __construct(
        private readonly AutomationWorkflowExecutor $workflowExecutor
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $trigger = $this->option('trigger');

        $rules = AutomationRule::query()
            ->active()
            ->where('scope', 'SYSTEM')
            ->when($trigger, fn ($query) => $query->where('trigger_type', $trigger))
            ->orderBy('trigger_type')
            ->get();

        if ($rules->isEmpty()) {
            $this->warn('No active system automations found. Ensuring system automations exist...');
            Artisan::call('automations:ensure-system');
            $rules = AutomationRule::query()
                ->active()
                ->where('scope', 'SYSTEM')
                ->when($trigger, fn ($query) => $query->where('trigger_type', $trigger))
                ->orderBy('trigger_type')
                ->get();

            if ($rules->isEmpty()) {
                $this->warn('Still no active system automations found after ensure-system.');
                return self::SUCCESS;
            }
        }

        $executed = 0;

        foreach ($rules as $rule) {
            $dispatchPayload = $this->resolveDispatchPayload($rule, $now);
            if ($dispatchPayload === null) {
                continue;
            }

            ['command' => $commandString, 'scheduled_for' => $scheduledFor, 'period_key' => $periodKey] = $dispatchPayload;

            if (!$this->option('force') && $this->alreadySuccessful($rule, $periodKey)) {
                $this->line("Skipping {$rule->name}; already executed for {$periodKey}.");
                continue;
            }

            $dispatch = AutomationDispatch::updateOrCreate(
                [
                    'automation_rule_id' => $rule->id,
                    'period_key' => $periodKey,
                ],
                [
                    'trigger_type' => $rule->trigger_type,
                    'scheduled_for' => $scheduledFor,
                    'command' => $commandString,
                    'status' => 'running',
                    'error_message' => null,
                    'started_at' => now(),
                    'completed_at' => null,
                ]
            );

            try {
                $this->info("Running {$rule->name}: {$commandString}");
                $exitCode = 1;

                $run = $this->workflowExecutor->createSystemRun(
                    $rule,
                    [
                        'trigger_type' => $rule->trigger_type,
                        'scheduled_for' => $scheduledFor->toIso8601String(),
                        'period_key' => $periodKey,
                    ],
                    $commandString,
                    $scheduledFor,
                    function () use ($commandString, &$exitCode): string {
                        $exitCode = Artisan::call($commandString);
                        return trim(Artisan::output());
                    }
                );
                $output = (string) (($run->steps()->latest('id')->first()?->output_json['output']) ?? '');

                $dispatch->update([
                    'status' => $exitCode === 0 ? 'completed' : 'failed',
                    'output' => $output,
                    'error_message' => $exitCode === 0 ? null : $output,
                    'completed_at' => now(),
                ]);

                if ($exitCode !== 0) {
                    $this->error("Automation failed: {$rule->name}");
                    Log::error('System automation command failed', [
                        'rule_id' => $rule->id,
                        'trigger_type' => $rule->trigger_type,
                        'command' => $commandString,
                        'output' => $output,
                    ]);
                    continue;
                }

                $executed++;
                $this->info("Completed {$rule->name}");
            } catch (\Throwable $exception) {
                $dispatch->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);

                Log::error('System automation crashed', [
                    'rule_id' => $rule->id,
                    'trigger_type' => $rule->trigger_type,
                    'command' => $commandString,
                    'error' => $exception->getMessage(),
                ]);

                $this->error("Automation crashed: {$rule->name}");
            }
        }

        $this->info("Executed {$executed} system automation(s).");

        return self::SUCCESS;
    }

    private function resolveDispatchPayload(AutomationRule $rule, Carbon $now): ?array
    {
        $schedule = is_array($rule->schedule_json) ? $rule->schedule_json : [];
        $conditions = is_array($rule->condition_json) ? $rule->condition_json : [];
        $commandString = $conditions['command'] ?? $schedule['command'] ?? null;

        if (!$commandString) {
            return null;
        }

        if (($schedule['type'] ?? null) !== 'weekly') {
            return null;
        }

        $dayOfWeek = (int) ($schedule['day_of_week'] ?? 1);
        $time = (string) ($schedule['time'] ?? '00:00');
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        $scheduledFor = $now->copy()
            ->startOfWeek(Carbon::SUNDAY)
            ->addDays($dayOfWeek)
            ->setTime($hour, $minute, 0);

        if ($now->lt($scheduledFor)) {
            return null;
        }

        $periodKey = sprintf('%s|%s', $rule->trigger_type, $scheduledFor->format('Y-m-d H:i'));

        return [
            'command' => $commandString,
            'scheduled_for' => $scheduledFor,
            'period_key' => $periodKey,
        ];
    }

    private function alreadySuccessful(AutomationRule $rule, string $periodKey): bool
    {
        return AutomationDispatch::query()
            ->where('automation_rule_id', $rule->id)
            ->where('period_key', $periodKey)
            ->where('status', 'completed')
            ->exists();
    }
}
