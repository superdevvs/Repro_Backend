<?php

use Database\Seeders\MessagingSystemSeeder;
use Database\Seeders\SystemAutomationsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(MessagingSystemSeeder::class)->run();
        app(SystemAutomationsSeeder::class)->run();
    }

    public function down(): void
    {
        // This sync is intentionally forward-only.
    }
};
