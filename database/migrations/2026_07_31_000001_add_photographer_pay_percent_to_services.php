<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow a service's photographer pay to be expressed as a percentage of the
 * service price, alongside the existing flat amount.
 *
 * Meeting 26 Jul 2026 [00:03:31] and the annotated service-editor screenshot in
 * A1.docx: pay was configured as $45.00 against a $100.00 service, i.e. 45% of
 * the price. Admins want to choose per service which of the two applies, so the
 * flat column stays and a type discriminator decides how it is read.
 *
 * `fixed` is the default so every existing service keeps its current payout and
 * no invoice total changes on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                if (! Schema::hasColumn('services', 'photographer_pay_type')) {
                    $table->string('photographer_pay_type', 16)
                        ->default('fixed')
                        ->after('photographer_pay');
                }
                if (! Schema::hasColumn('services', 'photographer_pay_percent')) {
                    $table->decimal('photographer_pay_percent', 5, 2)
                        ->nullable()
                        ->after('photographer_pay_type');
                }
            });
        }

        // Variable (sqft-tiered) pricing resolves pay per tier, so the same pair
        // is needed there for the percentage to apply to tiered prices.
        if (Schema::hasTable('service_sqft_ranges')) {
            Schema::table('service_sqft_ranges', function (Blueprint $table) {
                if (! Schema::hasColumn('service_sqft_ranges', 'photographer_pay_type')) {
                    $table->string('photographer_pay_type', 16)
                        ->default('fixed')
                        ->after('photographer_pay');
                }
                if (! Schema::hasColumn('service_sqft_ranges', 'photographer_pay_percent')) {
                    $table->decimal('photographer_pay_percent', 5, 2)
                        ->nullable()
                        ->after('photographer_pay_type');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                foreach (['photographer_pay_percent', 'photographer_pay_type'] as $column) {
                    if (Schema::hasColumn('services', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('service_sqft_ranges')) {
            Schema::table('service_sqft_ranges', function (Blueprint $table) {
                foreach (['photographer_pay_percent', 'photographer_pay_type'] as $column) {
                    if (Schema::hasColumn('service_sqft_ranges', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
