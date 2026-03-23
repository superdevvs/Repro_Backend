<?php

namespace Database\Seeders;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Messaging\AutomationWorkflowConverter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemAutomationsSeeder extends Seeder
{
    public function __construct(
        private readonly AutomationWorkflowConverter $workflowConverter
    ) {
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first admin user for created_by/updated_by
        // Check multiple possible role formats
        $admin = User::whereIn('role', ['admin', 'superadmin'])->first();

        if (!$admin) {
            $this->command->warn('No admin user found. Skipping system automations seeding.');
            return;
        }

        // Weekly Automated Invoicing for Photographers
        $weeklyInvoiceTemplateId = MessageTemplate::where('slug', 'weekly-invoice-generated')->first()?->id;

        $invoiceAutomation = AutomationRule::updateOrCreate(
            [
                'trigger_type' => 'WEEKLY_AUTOMATED_INVOICING',
                'scope' => 'SYSTEM',
            ],
            [
                'name' => 'Weekly Automated Invoicing',
                'description' => 'Automatically generates and sends weekly invoices to photographers based on completed shoots. Runs every Monday at 1:00 AM.',
                'trigger_type' => 'WEEKLY_AUTOMATED_INVOICING',
                'editor_mode' => 'visual',
                'engine_version' => 2,
                'is_active' => true,
                'scope' => 'SYSTEM',
                'is_system_locked' => true,
                'owner_id' => null,
                'template_id' => $weeklyInvoiceTemplateId,
                'channel_id' => null,
                'condition_json' => [
                    'schedule' => 'weekly',
                    'day' => 'monday',
                    'time' => '01:00',
                    'command' => 'invoices:generate --weekly',
                ],
                'schedule_json' => [
                    'type' => 'weekly',
                    'day_of_week' => 1, // Monday
                    'time' => '01:00',
                ],
                'recipients_json' => [
                    'type' => 'role',
                    'roles' => ['photographer'],
                ],
                'entry_trigger_json' => [
                    'trigger_type' => 'WEEKLY_AUTOMATED_INVOICING',
                    'node_type' => 'trigger.schedule',
                ],
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );
        $invoiceAutomation->forceFill([
            'workflow_definition_json' => $this->workflowConverter->buildLegacyWorkflow($invoiceAutomation),
        ])->save();
        
        $this->command->info("Created/Updated: {$invoiceAutomation->name} (ID: {$invoiceAutomation->id})");

        // Weekly Sales Reports to Sales Reps
        $salesReportAutomation = AutomationRule::updateOrCreate(
            [
                'trigger_type' => 'WEEKLY_SALES_REPORT',
                'scope' => 'SYSTEM',
            ],
            [
                'name' => 'Weekly Sales Reports',
                'description' => 'Automatically generates and sends weekly sales reports to all sales reps. Includes statistics on shoots, revenue, payments, and client breakdowns. Runs every Monday at 2:00 AM.',
                'trigger_type' => 'WEEKLY_SALES_REPORT',
                'editor_mode' => 'visual',
                'engine_version' => 2,
                'is_active' => true,
                'scope' => 'SYSTEM',
                'is_system_locked' => true,
                'owner_id' => null,
                'template_id' => null, // Uses WeeklySalesReportMail template
                'channel_id' => null,
                'condition_json' => [
                    'schedule' => 'weekly',
                    'day' => 'monday',
                    'time' => '02:00',
                    'command' => 'reports:sales:weekly',
                ],
                'schedule_json' => [
                    'type' => 'weekly',
                    'day_of_week' => 1, // Monday
                    'time' => '02:00',
                ],
                'recipients_json' => [
                    'type' => 'role',
                    'roles' => ['salesRep'],
                ],
                'entry_trigger_json' => [
                    'trigger_type' => 'WEEKLY_SALES_REPORT',
                    'node_type' => 'trigger.schedule',
                ],
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );
        $salesReportAutomation->forceFill([
            'workflow_definition_json' => $this->workflowConverter->buildLegacyWorkflow($salesReportAutomation),
        ])->save();
        
        $this->command->info("Created/Updated: {$salesReportAutomation->name} (ID: {$salesReportAutomation->id})");

        $this->command->info('System automations seeded successfully!');
        $this->command->info('- Weekly Automated Invoicing (Monday 1:00 AM)');
        $this->command->info('- Weekly Sales Reports (Monday 2:00 AM)');
    }
}


