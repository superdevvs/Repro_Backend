<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('time');
            }

            if (!Schema::hasColumn('shoots', 'mls_image_width')) {
                $table->unsignedInteger('mls_image_width')->nullable()->after('mls_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (Schema::hasColumn('shoots', 'mls_image_width')) {
                $table->dropColumn('mls_image_width');
            }

            if (Schema::hasColumn('shoots', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
