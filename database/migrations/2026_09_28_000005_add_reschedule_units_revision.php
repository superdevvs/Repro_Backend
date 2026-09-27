<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_reschedule_requests', function (Blueprint $table) {
            $table->unsignedInteger('units_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shoot_reschedule_requests', fn (Blueprint $table) => $table->dropColumn('units_revision'));
    }
};
