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
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'stripe_payment_id')) {
                $table->string('stripe_payment_id')->nullable()->after('square_order_id');
            }
            if (!Schema::hasColumn('payments', 'stripe_session_id')) {
                $table->string('stripe_session_id')->nullable()->after('stripe_payment_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'stripe_payment_id')) {
                $table->dropColumn('stripe_payment_id');
            }
            if (Schema::hasColumn('payments', 'stripe_session_id')) {
                $table->dropColumn('stripe_session_id');
            }
        });
    }
};
