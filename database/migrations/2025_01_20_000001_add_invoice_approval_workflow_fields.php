<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Check if columns already exist before adding
            if (!Schema::hasColumn('invoices', 'approval_status')) {
                // Add approval workflow fields
                $afterColumn = Schema::hasColumn('invoices', 'status') ? 'status' : null;
                if ($afterColumn) {
                    $table->string('approval_status')->default('pending')->after($afterColumn);
                } else {
                    $table->string('approval_status')->default('pending');
                }
            }
            
            if (!Schema::hasColumn('invoices', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (!Schema::hasColumn('invoices', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (!Schema::hasColumn('invoices', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('invoices', 'modified_by')) {
                $table->foreignId('modified_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'modified_at')) {
                $table->timestamp('modified_at')->nullable();
            }
            if (!Schema::hasColumn('invoices', 'modification_notes')) {
                $table->text('modification_notes')->nullable();
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            // Add expense type support (already has type field, but we'll ensure it supports 'expense')
            // No schema changes needed as type is already a string field
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['modified_by']);
            $table->dropColumn([
                'approval_status',
                'rejection_reason',
                'rejected_by',
                'rejected_at',
                'approved_by',
                'approved_at',
                'modified_by',
                'modified_at',
                'modification_notes',
            ]);
        });
    }
};


