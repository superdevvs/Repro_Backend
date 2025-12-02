<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            // Add rep_id if not exists
            if (!Schema::hasColumn('shoots', 'rep_id')) {
                $table->foreignId('rep_id')->nullable()->after('client_id')
                    ->constrained('users')->nullOnDelete();
            }

            // Add bypass_paywall
            if (!Schema::hasColumn('shoots', 'bypass_paywall')) {
                $table->boolean('bypass_paywall')->default(false)->after('payment_status');
            }

            // Add tax_region
            if (!Schema::hasColumn('shoots', 'tax_region')) {
                $table->enum('tax_region', ['md', 'dc', 'va', 'none'])->default('none')->after('tax_amount');
            }

            // Add tax_percent
            if (!Schema::hasColumn('shoots', 'tax_percent')) {
                $table->decimal('tax_percent', 5, 2)->default(0)->after('tax_region');
            }

            // Change scheduled_date to scheduled_at (datetime)
            if (Schema::hasColumn('shoots', 'scheduled_date') && !Schema::hasColumn('shoots', 'scheduled_at')) {
                $table->dateTime('scheduled_at')->nullable()->after('zip');
            }

            // Add completed_at
            if (!Schema::hasColumn('shoots', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('scheduled_at');
            }

            // Enhance status enum
            // Note: Laravel doesn't support altering enum easily, so we'll use string with validation
            // The status field already exists, we'll just document the valid values

            // Add updated_by
            if (!Schema::hasColumn('shoots', 'updated_by')) {
                $table->string('updated_by')->nullable()->after('created_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (Schema::hasColumn('shoots', 'rep_id')) {
                $table->dropForeign(['rep_id']);
                $table->dropColumn('rep_id');
            }
            if (Schema::hasColumn('shoots', 'bypass_paywall')) {
                $table->dropColumn('bypass_paywall');
            }
            if (Schema::hasColumn('shoots', 'tax_region')) {
                $table->dropColumn('tax_region');
            }
            if (Schema::hasColumn('shoots', 'tax_percent')) {
                $table->dropColumn('tax_percent');
            }
            if (Schema::hasColumn('shoots', 'scheduled_at')) {
                $table->dropColumn('scheduled_at');
            }
            if (Schema::hasColumn('shoots', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
            if (Schema::hasColumn('shoots', 'updated_by')) {
                $table->dropColumn('updated_by');
            }
        });
    }
};

