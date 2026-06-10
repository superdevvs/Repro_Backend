<?php

use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('message_templates') || !Schema::hasTable('automation_rules')) {
            return;
        }

        app(MessagingSystemSeeder::class)->run();
    }

    public function down(): void
    {
        if (!Schema::hasTable('message_templates') || !Schema::hasTable('automation_rules')) {
            return;
        }

        app(MessagingSystemSeeder::class)->run();
    }
};
