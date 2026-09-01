<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'document_type')) {
                $table->string('document_type', 32)
                    ->default('invoice')
                    ->after('role')
                    ->index();
            }

            if (! Schema::hasColumn('invoices', 'payment_required')) {
                $table->boolean('payment_required')
                    ->default(true)
                    ->after('document_type')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'payment_required')) {
                $table->dropColumn('payment_required');
            }

            if (Schema::hasColumn('invoices', 'document_type')) {
                $table->dropColumn('document_type');
            }
        });
    }
};
