<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('ai_editing_jobs')) {
            return;
        }

        Schema::table('ai_editing_jobs', function (Blueprint $table) {
            $table->foreignId('shoot_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('ai_editing_jobs')) {
            return;
        }

        Schema::table('ai_editing_jobs', function (Blueprint $table) {
            // NOTE: Reverting requires backfilling NULL shoot_id rows; left as best-effort.
            $table->foreignId('shoot_id')->nullable(false)->change();
        });
    }
};
