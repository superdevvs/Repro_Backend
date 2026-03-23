<?php

use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(MessagingSystemSeeder::class)->run();
    }

    public function down(): void
    {
        // This refresh migration is intentionally non-destructive.
    }
};
