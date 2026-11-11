<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editor_payouts', function (Blueprint $table) {
            // Historical execution identity deliberately survives the booked row.
            $table->unsignedBigInteger('removed_shoot_service_id')->nullable()->index();
            $table->timestamp('service_removed_at')->nullable();
        });
        DB::statement('DROP INDEX editor_payouts_execution_unique');
        DB::statement('CREATE UNIQUE INDEX editor_payouts_execution_unique ON editor_payouts (editor_id, shoot_id, service_id, COALESCE(shoot_service_id, removed_shoot_service_id, 0))');
    }

    public function down(): void
    {
        if (DB::table('editor_payouts')->whereNotNull('removed_shoot_service_id')->exists()) {
            throw new RuntimeException('Removed service editor payouts exist; refusing to discard their execution identity.');
        }
        DB::statement('DROP INDEX editor_payouts_execution_unique');
        DB::statement('CREATE UNIQUE INDEX editor_payouts_execution_unique ON editor_payouts (editor_id, shoot_id, service_id, COALESCE(shoot_service_id, 0))');
        Schema::table('editor_payouts', fn (Blueprint $table) => $table->dropColumn(['removed_shoot_service_id', 'service_removed_at']));
    }
};
