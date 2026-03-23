<?php

use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => MessagingSystemSeeder::class,
            '--force' => true,
        ]);
    }

    public function down(): void
    {
        // Intentionally left blank. Template design refresh is forward-only.
    }
};
