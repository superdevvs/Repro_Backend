<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_service', function (Blueprint $table) {
            if (!Schema::hasColumn('shoot_service', 'editor_id')) {
                $table->foreignId('editor_id')
                    ->nullable()
                    ->after('photographer_id')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->index('editor_id', 'shoot_service_editor_id_index');
            }

            if (!Schema::hasColumn('shoot_service', 'editing_completed_at')) {
                $table->timestamp('editing_completed_at')
                    ->nullable()
                    ->after('editor_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoot_service', function (Blueprint $table) {
            if (Schema::hasColumn('shoot_service', 'editing_completed_at')) {
                $table->dropColumn('editing_completed_at');
            }

            if (Schema::hasColumn('shoot_service', 'editor_id')) {
                $table->dropIndex('shoot_service_editor_id_index');
                $table->dropForeign(['editor_id']);
                $table->dropColumn('editor_id');
            }
        });
    }
};
