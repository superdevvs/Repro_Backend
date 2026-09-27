<?php

use App\Services\Messaging\SystemAutomationDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automation_rules') && Schema::hasColumn('automation_rules', 'workflow_definition_json')
            && \App\Models\AutomationRule::exists()) {
            app(\Database\Seeders\MessagingSystemSeeder::class)->upgradeDefaultTemplateContent();
            app(SystemAutomationDefaults::class)->repair();
        }
    }

    public function down(): void
    {
        // Preserve operator edits and delivery history on rollback.
    }
};
