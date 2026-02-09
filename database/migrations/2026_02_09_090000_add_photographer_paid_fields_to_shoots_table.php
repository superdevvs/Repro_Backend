<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'photographer_paid_at')) {
                $table->timestamp('photographer_paid_at')->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('shoots', 'photographer_paid_invoice_id')) {
                $table->foreignId('photographer_paid_invoice_id')->nullable()->after('photographer_paid_at')
                    ->constrained('invoices')->nullOnDelete();
            }
            if (!Schema::hasColumn('shoots', 'sales_rep_paid_at')) {
                $table->timestamp('sales_rep_paid_at')->nullable()->after('photographer_paid_invoice_id');
            }
            if (!Schema::hasColumn('shoots', 'sales_rep_paid_invoice_id')) {
                $table->foreignId('sales_rep_paid_invoice_id')->nullable()->after('sales_rep_paid_at')
                    ->constrained('invoices')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (Schema::hasColumn('shoots', 'sales_rep_paid_invoice_id')) {
                $table->dropForeign(['sales_rep_paid_invoice_id']);
                $table->dropColumn('sales_rep_paid_invoice_id');
            }
            if (Schema::hasColumn('shoots', 'sales_rep_paid_at')) {
                $table->dropColumn('sales_rep_paid_at');
            }
            if (Schema::hasColumn('shoots', 'photographer_paid_invoice_id')) {
                $table->dropForeign(['photographer_paid_invoice_id']);
                $table->dropColumn('photographer_paid_invoice_id');
            }
            if (Schema::hasColumn('shoots', 'photographer_paid_at')) {
                $table->dropColumn('photographer_paid_at');
            }
        });
    }
};
