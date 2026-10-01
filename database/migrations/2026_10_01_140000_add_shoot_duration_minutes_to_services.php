<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing catalogue rows keep their tier durations and booking snapshots.
        // Their null base duration resolves to the configured one-hour fallback.
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('shoot_duration_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('shoot_duration_minutes');
        });
    }
};
