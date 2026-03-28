<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'shoot_cc_emails')) {
                $table->json('shoot_cc_emails')->nullable()->after('company_notes');
            }

            if (!Schema::hasColumn('users', 'client_discount_type')) {
                $table->string('client_discount_type', 20)->nullable()->after('shoot_cc_emails');
            }

            if (!Schema::hasColumn('users', 'client_discount_value')) {
                $table->decimal('client_discount_value', 10, 2)->nullable()->after('client_discount_type');
            }
        });

        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'discount_type')) {
                $table->string('discount_type', 20)->nullable()->after('base_quote');
            }

            if (!Schema::hasColumn('shoots', 'discount_value')) {
                $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type');
            }

            if (!Schema::hasColumn('shoots', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_value');
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'cc_addresses_json')) {
                $table->json('cc_addresses_json')->nullable()->after('to_address');
            }

            if (!Schema::hasColumn('messages', 'bcc_addresses_json')) {
                $table->json('bcc_addresses_json')->nullable()->after('cc_addresses_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'bcc_addresses_json')) {
                $table->dropColumn('bcc_addresses_json');
            }

            if (Schema::hasColumn('messages', 'cc_addresses_json')) {
                $table->dropColumn('cc_addresses_json');
            }
        });

        Schema::table('shoots', function (Blueprint $table) {
            foreach (['discount_amount', 'discount_value', 'discount_type'] as $column) {
                if (Schema::hasColumn('shoots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['client_discount_value', 'client_discount_type', 'shoot_cc_emails'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
