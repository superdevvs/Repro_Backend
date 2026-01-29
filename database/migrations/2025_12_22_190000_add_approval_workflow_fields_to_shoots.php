<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds approval workflow fields for shoot request/approval system
     */
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            // Approval workflow fields
            $table->text('approval_notes')->nullable()->after('admin_issue_notes');
            $table->timestamp('approved_at')->nullable()->after('approval_notes');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            $table->timestamp('declined_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('declined_by')->nullable()->after('declined_at');
            $table->text('declined_reason')->nullable()->after('declined_by');

            // Add foreign keys
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('declined_by')->references('id')->on('users')->onDelete('set null');

            // Add index for requested status filtering
            $table->index(['status', 'workflow_status'], 'shoots_status_workflow_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['declined_by']);

            // Drop index
            $table->dropIndex('shoots_status_workflow_index');

            // Drop columns
            $table->dropColumn([
                'approval_notes',
                'approved_at',
                'approved_by',
                'declined_at',
                'declined_by',
                'declined_reason',
            ]);
        });
    }
};
