<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shoots', 'property_status')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE shoots MODIFY property_status ENUM('available','coming_soon','pending','sold','rented') NOT NULL DEFAULT 'available'");
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('shoots', 'property_status')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::table('shoots')
                ->whereIn('property_status', ['coming_soon', 'pending'])
                ->update(['property_status' => 'available']);

            DB::statement("ALTER TABLE shoots MODIFY property_status ENUM('available','sold','rented') NOT NULL DEFAULT 'available'");
        }
    }
};
