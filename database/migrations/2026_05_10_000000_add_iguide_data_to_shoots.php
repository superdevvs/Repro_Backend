<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'iguide_data')) {
                // Full normalized iGUIDE ready event payload (urls, media, billing, summary, etc.).
                $table->json('iguide_data')->nullable()->after('iguide_property_id');
            }
            if (!Schema::hasColumn('shoots', 'iguide_work_order_id')) {
                $table->string('iguide_work_order_id')->nullable()->after('iguide_data');
            }
        });

        // Add an index for fast webhook lookups by work order id.
        if (Schema::hasColumn('shoots', 'iguide_work_order_id')) {
            Schema::table('shoots', function (Blueprint $table) {
                try {
                    $table->index('iguide_work_order_id', 'shoots_iguide_work_order_id_idx');
                } catch (\Throwable $e) {
                    // Index may already exist when re-running.
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            try {
                $table->dropIndex('shoots_iguide_work_order_id_idx');
            } catch (\Throwable $e) {
                // ignore
            }

            $columns = [];
            if (Schema::hasColumn('shoots', 'iguide_work_order_id')) {
                $columns[] = 'iguide_work_order_id';
            }
            if (Schema::hasColumn('shoots', 'iguide_data')) {
                $columns[] = 'iguide_data';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
