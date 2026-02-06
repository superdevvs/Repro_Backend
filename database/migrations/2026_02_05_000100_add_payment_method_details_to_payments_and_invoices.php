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
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('payments', function (Blueprint $table) use ($driver) {
            if (!Schema::hasColumn('payments', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('currency');
            }

            if (!Schema::hasColumn('payments', 'payment_details')) {
                if ($driver === 'sqlite') {
                    $table->text('payment_details')->nullable()->after('payment_method');
                } else {
                    $table->json('payment_details')->nullable()->after('payment_method');
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table) use ($driver) {
            if (!Schema::hasColumn('invoices', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('paid_at');
            }

            if (!Schema::hasColumn('invoices', 'payment_details')) {
                if ($driver === 'sqlite') {
                    $table->text('payment_details')->nullable()->after('payment_method');
                } else {
                    $table->json('payment_details')->nullable()->after('payment_method');
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'payment_details')) {
                $table->dropColumn('payment_details');
            }
            if (Schema::hasColumn('payments', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'payment_details')) {
                $table->dropColumn('payment_details');
            }
            if (Schema::hasColumn('invoices', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });
    }
};
