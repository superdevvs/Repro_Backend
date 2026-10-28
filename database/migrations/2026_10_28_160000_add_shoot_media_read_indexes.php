<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            // SQLite foreign keys do not implicitly index their child columns.
            $table->index(['shoot_id', 'workflow_stage'], 'shoot_files_shoot_stage_index');
            $table->index(['shoot_service_id', 'required_for_editing'], 'shoot_files_service_required_index');
            $table->index(['shoot_id', 'shoot_service_id', 'required_for_editing'], 'shoot_files_legacy_required_index');
        });
        Schema::table('payments', fn (Blueprint $table) => $table->index('shoot_id', 'payments_shoot_id_index'));
    }

    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            $table->dropIndex('shoot_files_shoot_stage_index');
            $table->dropIndex('shoot_files_service_required_index');
            $table->dropIndex('shoot_files_legacy_required_index');
        });
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payments_shoot_id_index'));
    }
};
