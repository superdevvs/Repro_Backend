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
        Schema::table('watermark_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('watermark_settings', 'text_angle')) {
                $table->integer('text_angle')->default(-30)->after('text_spacing');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('watermark_settings', function (Blueprint $table) {
            if (Schema::hasColumn('watermark_settings', 'text_angle')) {
                $table->dropColumn('text_angle');
            }
        });
    }
};
