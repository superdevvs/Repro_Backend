<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\User;
use App\Services\Messaging\AutomationWorkflowConverter;
use Illuminate\Console\Command;

class EnsureSystemAutomations extends Command
{
    protected $signature = 'automations:ensure-system';
    protected $description = 'Ensure system automations (Weekly Sales Reports and Weekly Automated Invoicing) exist in the database';

    public function __construct(
        private readonly AutomationWorkflowConverter $workflowConverter
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('=== Ensuring System Automations Exist ===');
        $this->newLine();

        // Get admin user - try all possible role formats
        $admin = User::whereIn('role', ['admin', 'superadmin', 'super_admin'])->first();

        if (!$admin) {
            $this->error('No admin user found!');
            $roles = User::distinct()->pluck('role')->toArray();
            $this->warn('Available roles in database: ' . implode(', ', $roles));
            $this->newLine();
            $this->info('Trying to get any user...');
            $admin = User::first();
            if ($admin) {
                $this->info("Using first user: {$admin->email} (role: {$admin->role})");
            } else {
                $this->error('No users found in database!');
                return 1;
            }
        }

        $this->info("Using admin user: {$admin->email} (ID: {$admin->id}, Role: {$admin->role})");
        $this->newLine();

        // Check existing automations
        $existing = AutomationRule::whereIn('trigger_type', ['WEEKLY_SALES_REPORT', 'WEEKLY_AUTOMATED_INVOICING'])->get();
        $this->info("Found {$existing->count()} existing weekly automations");

        if ($existing->count() > 0) {
            foreach ($existing as $auto) {
                $this->line("  - {$auto->name} (ID: {$auto->id}, Scope: {$auto->scope}, Active: " . ($auto->is_active ? 'Yes' : 'No') . ")");
            }
            $this->newLine();
        }

        // Create/Update Weekly Automated Invoicing
        $this->info('Creating/Updating: Weekly Automated Invoicing');
        $invoiceAuto = AutomationRule::updateOrCreate(
            [
                'trigger_type' => 'WEEKLY_AUTOMATED_INVOICING',
                'scope' => 'SYSTEM',
            ],
            [
                'name' => 'Weekly Automated Invoicing',
                'description' => 'Automatically generates weekly photographer and sales rep invoices for the last completed week. Runs every Monday at 1:00 AM through the system automation runner.',
                'trigger_type' => 'WEEKLY_AUTOMATED_INVOICING',
                'editor_mode' => 'visual',
                'engine_version' => 2,
                'is_active' => true,
                'scope' => 'SYSTEM',
                'is_system_locked' => true,
                'owner_id' => null,
                'template_id' => null,
                'channel_id' => null,
                'condition_json' => [
                    'schedule' => 'weekly',
                    'day' => 'monday',
                    'time' => '01:00',
                    'command' => 'invoices:generate --weekly',
                ],
                'schedule_json' => [
                    'type' => 'weekly',
                    'day_of_week' => 1,
                    'time' => '01:00',
                    'command' => 'invoices:generate --weekly',
                ],
                'recipients_json' => [
                    'type' => 'role',
                    'roles' => ['photographer', 'rep'],
                ],
                'entry_trigger_json' => [
                    'trigger_type' => 'WEEKLY_AUTOMATED_INVOICING',
                    'node_type' => 'trigger.schedule',
                ],
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );
        $invoiceAuto->forceFill([
            'workflow_definition_json' => $this->workflowConverter->buildLegacyWorkflow($invoiceAuto),
        ])->save();
        $this->line("  ✓ {$invoiceAuto->name} (ID: {$invoiceAuto->id}, Scope: {$invoiceAuto->scope})");

        // Create/Update Weekly Sales Reports
        $this->info('Creating/Updating: Weekly Sales Reports');
        $salesAuto = AutomationRule::updateOrCreate(
            [
                'trigger_type' => 'WEEKLY_SALES_REPORT',
                'scope' => 'SYSTEM',
            ],
            [
                'name' => 'Weekly Sales Reports',
                'description' => 'Automatically generates and sends weekly sales reports to all sales reps. Runs every Monday at 2:00 AM through the system automation runner.',
                'trigger_type' => 'WEEKLY_SALES_REPORT',
                'editor_mode' => 'visual',
                'engine_version' => 2,
                'is_active' => true,
                'scope' => 'SYSTEM',
                'is_system_locked' => true,
                'owner_id' => null,
                'template_id' => null,
                'channel_id' => null,
                'condition_json' => [
                    'schedule' => 'weekly',
                    'day' => 'monday',
                    'time' => '02:00',
                    'command' => 'reports:sales:weekly',
                ],
                'schedule_json' => [
                    'type' => 'weekly',
                    'day_of_week' => 1,
                    'time' => '02:00',
                    'command' => 'reports:sales:weekly',
                ],
                'recipients_json' => [
                    'type' => 'role',
                    'roles' => ['rep'],
                ],
                'entry_trigger_json' => [
                    'trigger_type' => 'WEEKLY_SALES_REPORT',
                    'node_type' => 'trigger.schedule',
                ],
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );
        $salesAuto->forceFill([
            'workflow_definition_json' => $this->workflowConverter->buildLegacyWorkflow($salesAuto),
        ])->save();
        $this->line("  ✓ {$salesAuto->name} (ID: {$salesAuto->id}, Scope: {$salesAuto->scope})");

        // Verify all system automations
        $this->newLine();
        $this->info('=== Verification ===');
        $allSystem = AutomationRule::where('scope', 'SYSTEM')->get(['id', 'name', 'trigger_type', 'scope', 'is_active']);
        $this->info("Total SYSTEM automations: {$allSystem->count()}");
        foreach ($allSystem as $auto) {
            $this->line("  - {$auto->name} ({$auto->trigger_type}) - Active: " . ($auto->is_active ? 'Yes' : 'No'));
        }

        $this->newLine();
        $this->info('=== Done ===');
        return 0;
    }
}


