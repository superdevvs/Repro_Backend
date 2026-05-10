<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'cubicasa_order_id')) {
                // CubiCasa order UUID returned from POST /orders or visible in the portal.
                $table->string('cubicasa_order_id')->nullable()->after('is_private_listing');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_external_id')) {
                // Our reference (e.g. "shoot:{id}") set when we eventually create orders via API.
                $table->string('cubicasa_external_id')->nullable()->after('cubicasa_order_id');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_status')) {
                // Last known status: New|Draft|Pending|Ready|Fixing.
                $table->string('cubicasa_status')->nullable()->after('cubicasa_external_id');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_product_type')) {
                // products_2d | products_3d | tour
                $table->string('cubicasa_product_type')->nullable()->after('cubicasa_status');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_tour_url')) {
                $table->string('cubicasa_tour_url')->nullable()->after('cubicasa_product_type');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_floorplans')) {
                // Slim list mirroring iguide_floorplans for UI re-use.
                $table->json('cubicasa_floorplans')->nullable()->after('cubicasa_tour_url');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_data')) {
                // Full normalised order payload (delivery_assets, info, address, billing).
                $table->json('cubicasa_data')->nullable()->after('cubicasa_floorplans');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_last_synced_at')) {
                $table->timestamp('cubicasa_last_synced_at')->nullable()->after('cubicasa_data');
            }
            if (!Schema::hasColumn('shoots', 'cubicasa_last_status_at')) {
                $table->timestamp('cubicasa_last_status_at')->nullable()->after('cubicasa_last_synced_at');
            }
        });

        // Indexes for fast webhook lookups.
        Schema::table('shoots', function (Blueprint $table) {
            try {
                $table->index('cubicasa_order_id', 'shoots_cubicasa_order_id_idx');
            } catch (\Throwable $e) {
                // Index may already exist.
            }
            try {
                $table->index('cubicasa_external_id', 'shoots_cubicasa_external_id_idx');
            } catch (\Throwable $e) {
                // Index may already exist.
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            try {
                $table->dropIndex('shoots_cubicasa_order_id_idx');
            } catch (\Throwable $e) {
                // ignore
            }
            try {
                $table->dropIndex('shoots_cubicasa_external_id_idx');
            } catch (\Throwable $e) {
                // ignore
            }

            $columns = [];
            foreach ([
                'cubicasa_last_status_at',
                'cubicasa_last_synced_at',
                'cubicasa_data',
                'cubicasa_floorplans',
                'cubicasa_tour_url',
                'cubicasa_product_type',
                'cubicasa_status',
                'cubicasa_external_id',
                'cubicasa_order_id',
            ] as $col) {
                if (Schema::hasColumn('shoots', $col)) {
                    $columns[] = $col;
                }
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
