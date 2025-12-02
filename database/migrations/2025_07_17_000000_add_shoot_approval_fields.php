<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'admin_issue_notes')) {
                $table->text('admin_issue_notes')->nullable()->after('editor_notes');
            }
            if (!Schema::hasColumn('shoots', 'is_flagged')) {
                $table->boolean('is_flagged')->default(false)->after('admin_issue_notes');
            }
            if (!Schema::hasColumn('shoots', 'issues_resolved_at')) {
                $table->timestamp('issues_resolved_at')->nullable()->after('is_flagged');
            }
            if (!Schema::hasColumn('shoots', 'issues_resolved_by')) {
                $table->foreignId('issues_resolved_by')->nullable()->constrained('users')->nullOnDelete()->after('issues_resolved_at');
            }
            if (!Schema::hasColumn('shoots', 'submitted_for_review_at')) {
                $table->timestamp('submitted_for_review_at')->nullable()->after('issues_resolved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (Schema::hasColumn('shoots', 'submitted_for_review_at')) {
                $table->dropColumn('submitted_for_review_at');
            }
            if (Schema::hasColumn('shoots', 'issues_resolved_by')) {
                $table->dropForeign(['issues_resolved_by']);
                $table->dropColumn('issues_resolved_by');
            }
            if (Schema::hasColumn('shoots', 'issues_resolved_at')) {
                $table->dropColumn('issues_resolved_at');
            }
            if (Schema::hasColumn('shoots', 'is_flagged')) {
                $table->dropColumn('is_flagged');
            }
            if (Schema::hasColumn('shoots', 'admin_issue_notes')) {
                $table->dropColumn('admin_issue_notes');
            }
        });
    }
};





