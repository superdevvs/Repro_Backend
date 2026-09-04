<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'payments_stripe_session_id_unique';

    public function up(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'stripe_session_id')) {
            return;
        }

        $duplicate = DB::table('payments')
            ->select('stripe_session_id')
            ->whereNotNull('stripe_session_id')
            ->where('stripe_session_id', '!=', '')
            ->groupBy('stripe_session_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(
                'Duplicate Stripe Session IDs must be resolved before adding the payment uniqueness constraint.'
            );
        }

        if (! Schema::hasIndex('payments', self::INDEX_NAME)) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unique('stripe_session_id', self::INDEX_NAME);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasIndex('payments', self::INDEX_NAME)) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropUnique(self::INDEX_NAME);
            });
        }
    }
};
