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
        Schema::table('invoices', function (Blueprint $table) {
            // Add billing_period_start if not exists (different from period_start)
            if (!Schema::hasColumn('invoices', 'billing_period_start')) {
                $table->date('billing_period_start')->nullable()->after('due_date');
            }
            
            // Add billing_period_end if not exists (different from period_end)
            if (!Schema::hasColumn('invoices', 'billing_period_end')) {
                $table->date('billing_period_end')->nullable()->after('billing_period_start');
            }
            
            // Add total_amount if not exists
            if (!Schema::hasColumn('invoices', 'total_amount')) {
                $table->decimal('total_amount', 10, 2)->nullable()->after('total');
            }
            
            // Add amount_paid if not exists
            if (!Schema::hasColumn('invoices', 'amount_paid')) {
                $table->decimal('amount_paid', 10, 2)->default(0)->after('total_amount');
            }
            
            // Add is_paid if not exists
            if (!Schema::hasColumn('invoices', 'is_paid')) {
                $table->boolean('is_paid')->default(false)->after('amount_paid');
            }
            
            // Add is_sent if not exists
            if (!Schema::hasColumn('invoices', 'is_sent')) {
                $table->boolean('is_sent')->default(false)->after('is_paid');
            }
            
            // Make photographer_id nullable if it exists and is not already nullable
            // SQLite doesn't support changing columns, so we skip this for SQLite
            $driver = Schema::getConnection()->getDriverName();
            if ($driver !== 'sqlite' && Schema::hasColumn('invoices', 'photographer_id')) {
                // For MySQL/PostgreSQL
                $table->foreignId('photographer_id')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'is_sent')) {
                $table->dropColumn('is_sent');
            }
            if (Schema::hasColumn('invoices', 'is_paid')) {
                $table->dropColumn('is_paid');
            }
            if (Schema::hasColumn('invoices', 'amount_paid')) {
                $table->dropColumn('amount_paid');
            }
            if (Schema::hasColumn('invoices', 'total_amount')) {
                $table->dropColumn('total_amount');
            }
            if (Schema::hasColumn('invoices', 'billing_period_end')) {
                $table->dropColumn('billing_period_end');
            }
            if (Schema::hasColumn('invoices', 'billing_period_start')) {
                $table->dropColumn('billing_period_start');
            }
        });
    }
};
