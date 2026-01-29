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
        Schema::table('shoots', function (Blueprint $table) {
            // Add indexes for frequently queried columns
            $table->index(['status'], 'idx_shoots_status');
            $table->index(['client_id'], 'idx_shoots_client_id');
            $table->index(['photographer_id'], 'idx_shoots_photographer_id');
            $table->index(['editor_id'], 'idx_shoots_editor_id');
            $table->index(['scheduled_date'], 'idx_shoots_scheduled_date');
            $table->index(['created_at'], 'idx_shoots_created_at');
            $table->index(['admin_verified_at'], 'idx_shoots_admin_verified_at');
            $table->index(['editing_completed_at'], 'idx_shoots_editing_completed_at');
            
            // Composite indexes for common query patterns
            $table->index(['status', 'scheduled_date'], 'idx_shoots_status_date');
            $table->index(['client_id', 'status'], 'idx_shoots_client_status');
            $table->index(['photographer_id', 'status'], 'idx_shoots_photographer_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex('idx_shoots_status');
            $table->dropIndex('idx_shoots_client_id');
            $table->dropIndex('idx_shoots_photographer_id');
            $table->dropIndex('idx_shoots_editor_id');
            $table->dropIndex('idx_shoots_scheduled_date');
            $table->dropIndex('idx_shoots_created_at');
            $table->dropIndex('idx_shoots_admin_verified_at');
            $table->dropIndex('idx_shoots_editing_completed_at');
            $table->dropIndex('idx_shoots_status_date');
            $table->dropIndex('idx_shoots_client_status');
            $table->dropIndex('idx_shoots_photographer_status');
        });
    }
};
