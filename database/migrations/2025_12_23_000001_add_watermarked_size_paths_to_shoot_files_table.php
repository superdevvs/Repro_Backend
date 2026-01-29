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
        Schema::table('shoot_files', function (Blueprint $table) {
            if (!Schema::hasColumn('shoot_files', 'watermarked_thumbnail_path')) {
                $table->string('watermarked_thumbnail_path')->nullable()->after('watermarked_storage_path');
            }
            if (!Schema::hasColumn('shoot_files', 'watermarked_web_path')) {
                $table->string('watermarked_web_path')->nullable()->after('watermarked_thumbnail_path');
            }
            if (!Schema::hasColumn('shoot_files', 'watermarked_placeholder_path')) {
                $table->string('watermarked_placeholder_path')->nullable()->after('watermarked_web_path');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            if (Schema::hasColumn('shoot_files', 'watermarked_thumbnail_path')) {
                $table->dropColumn('watermarked_thumbnail_path');
            }
            if (Schema::hasColumn('shoot_files', 'watermarked_web_path')) {
                $table->dropColumn('watermarked_web_path');
            }
            if (Schema::hasColumn('shoot_files', 'watermarked_placeholder_path')) {
                $table->dropColumn('watermarked_placeholder_path');
            }
        });
    }
};
