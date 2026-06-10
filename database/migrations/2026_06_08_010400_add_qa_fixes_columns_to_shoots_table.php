<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            // Test_Shoot region/state/area scoping (Req 10). Real test shoots reuse the
            // existing shoot_type = internal_test classification; these columns only record
            // which service area a Test_Shoot is scoped to.
            if (!Schema::hasColumn('shoots', 'service_area_kind')) {
                $table->string('service_area_kind', 32)->nullable()->after('shoot_type');
            }
            if (!Schema::hasColumn('shoots', 'service_area_value')) {
                $table->string('service_area_value')->nullable()->after('service_area_kind');
            }

            // Payment-reminder cadence anchor (Req 12.10): set when the Shoot ready
            // Notification is sent, distinct from the shoot date and the invoice date.
            if (!Schema::hasColumn('shoots', 'shoot_ready_notified_at')) {
                $table->timestamp('shoot_ready_notified_at')->nullable();
            }

            // Per-shoot CubiCasa idempotency key (Req 19.6) to prevent duplicate orders.
            if (!Schema::hasColumn('shoots', 'cubicasa_idempotency_key')) {
                $table->string('cubicasa_idempotency_key')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            foreach ([
                'cubicasa_idempotency_key',
                'shoot_ready_notified_at',
                'service_area_value',
                'service_area_kind',
            ] as $column) {
                if (Schema::hasColumn('shoots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
