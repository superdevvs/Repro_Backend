<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add composite index for workflow_status + scheduled_date to optimize common queries
     */
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            // Add composite index for workflow_status + scheduled_date
            // This optimizes queries that filter by both workflow_status and date
            $table->index(['workflow_status', 'scheduled_date'], 'idx_shoots_workflow_status_scheduled_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropIndex('idx_shoots_workflow_status_scheduled_date');
        });
    }
};
