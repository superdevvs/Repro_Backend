<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\AutomationRule;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        // Get the first admin user for created_by/updated_by
        // Check multiple possible role formats
        $admin = User::whereIn('role', ['admin', 'super_admin', 'superadmin'])->first();

        if (!$admin) {
            // If no admin exists, we'll skip this migration
            // The seeder will handle it when an admin is created
            return;
        }

        // Weekly Automated Invoicing for Photographers
        AutomationRule::updateOrCreate(
            [
                'trigger_type' => 'WEEKLY_AUTOMATED_INVOICING',
                'scope' => 'SYSTEM',
            ],
            [
                'name' => 'Weekly Automated Invoicing',
                'description' => 'Automatically generates and sends weekly invoices to photographers based on completed shoots. Runs every Monday at 1:00 AM.',
                'trigger_type' => 'WEEKLY_AUTOMATED_INVOICING',
                'is_active' => true,
                'scope' => 'SYSTEM',
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
                    'day_of_week' => 1, // Monday
                    'time' => '01:00',
                ],
                'recipients_json' => [
                    'type' => 'role',
                    'roles' => ['photographer'],
                ],
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        // Weekly Sales Reports to Sales Reps
        AutomationRule::updateOrCreate(
            [
                'trigger_type' => 'WEEKLY_SALES_REPORT',
                'scope' => 'SYSTEM',
            ],
            [
                'name' => 'Weekly Sales Reports',
                'description' => 'Automatically generates and sends weekly sales reports to all sales reps. Includes statistics on shoots, revenue, payments, and client breakdowns. Runs every Monday at 2:00 AM.',
                'trigger_type' => 'WEEKLY_SALES_REPORT',
                'is_active' => true,
                'scope' => 'SYSTEM',
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
                    'day_of_week' => 1, // Monday
                    'time' => '02:00',
                ],
                'recipients_json' => [
                    'type' => 'role',
                    'roles' => ['salesRep'],
                ],
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );
    }

    public function down(): void
    {
        // Don't delete system automations on rollback
        // They should remain in the system
    }
};


