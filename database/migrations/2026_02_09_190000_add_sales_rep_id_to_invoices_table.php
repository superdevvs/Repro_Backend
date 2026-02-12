<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'sales_rep_id')) {
                $table->foreignId('sales_rep_id')->nullable()->after('photographer_id')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'sales_rep_id')) {
                $table->dropForeign(['sales_rep_id']);
                $table->dropColumn('sales_rep_id');
            }
        });
    }
};
