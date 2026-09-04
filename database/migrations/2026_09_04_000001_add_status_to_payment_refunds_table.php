<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_refunds')) {
            return;
        }

        $duplicateProviderRefund = DB::table('payment_refunds')
            ->select('payment_id', 'provider', 'provider_refund_id')
            ->whereNotNull('provider_refund_id')
            ->groupBy('payment_id', 'provider', 'provider_refund_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateProviderRefund) {
            throw new RuntimeException(
                'Duplicate payment refund provider IDs must be resolved before adding the Stripe refund uniqueness constraint.'
            );
        }

        if (! Schema::hasColumn('payment_refunds', 'status')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                // Existing rows were already applied to balances, so they are
                // succeeded by definition.
                $table->string('status', 32)->default('succeeded')->after('provider_refund_id');
            });
        }

        if (! Schema::hasColumn('payment_refunds', 'operation_key')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                // A client-generated operation ID is persisted before Stripe is
                // called. Transport retries can then reuse one Stripe
                // idempotency key instead of risking a second refund.
                $table->string('operation_key', 100)->nullable()->after('provider_refund_id');
            });
        }

        if (! Schema::hasIndex('payment_refunds', 'payment_refunds_payment_provider_id_unique')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->unique(
                    ['payment_id', 'provider', 'provider_refund_id'],
                    'payment_refunds_payment_provider_id_unique'
                );
            });
        }

        if (! Schema::hasIndex('payment_refunds', 'payment_refunds_payment_operation_unique')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->unique(
                    ['payment_id', 'operation_key'],
                    'payment_refunds_payment_operation_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_refunds')) {
            return;
        }

        if (Schema::hasIndex('payment_refunds', 'payment_refunds_payment_operation_unique')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->dropUnique('payment_refunds_payment_operation_unique');
            });
        }

        if (Schema::hasIndex('payment_refunds', 'payment_refunds_payment_provider_id_unique')) {
            Schema::table('payment_refunds', function (Blueprint $table) {
                $table->dropUnique('payment_refunds_payment_provider_id_unique');
            });
        }

        $columns = collect(['operation_key', 'status'])
            ->filter(fn (string $column) => Schema::hasColumn('payment_refunds', $column))
            ->all();

        if ($columns !== []) {
            Schema::table('payment_refunds', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
