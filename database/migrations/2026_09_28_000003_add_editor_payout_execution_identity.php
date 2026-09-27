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
            $table->foreignId('shoot_service_id')->nullable()->constrained('shoot_service')->restrictOnDelete();
            $table->dropUnique('editor_payouts_editor_shoot_service_unique');
        });
        // Existing paid snapshots remain untouched in the NULL legacy scope.
        DB::statement('CREATE UNIQUE INDEX editor_payouts_execution_unique ON editor_payouts (editor_id, shoot_id, service_id, COALESCE(shoot_service_id, 0))');
    }

    public function down(): void
    {
        if (DB::table('editor_payouts')->whereNotNull('shoot_service_id')->exists()) {
            throw new RuntimeException('Execution-specific editor payouts exist; refusing to discard their identity.');
        }
        DB::statement('DROP INDEX editor_payouts_execution_unique');
        Schema::table('editor_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shoot_service_id');
            $table->unique(['editor_id', 'shoot_id', 'service_id'], 'editor_payouts_editor_shoot_service_unique');
        });
    }
};
