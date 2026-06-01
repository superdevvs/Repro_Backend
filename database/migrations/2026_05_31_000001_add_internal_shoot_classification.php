<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'shoot_type')) {
                $table->string('shoot_type', 32)->default('standard')->after('workflow_status')->index();
            }

            if (!Schema::hasColumn('shoots', 'product_status')) {
                $table->string('product_status', 32)->default('has_product')->after('shoot_type')->index();
            }
        });

        $this->makeLegacyServiceNullable();

        DB::table('shoots')
            ->where('total_quote', '<=', 0)
            ->update([
                'payment_status' => 'paid',
                'bypass_paywall' => true,
                'product_status' => DB::raw("
                    CASE
                        WHEN EXISTS (SELECT 1 FROM shoot_service WHERE shoot_service.shoot_id = shoots.id) THEN 'zero_dollar_product'
                        ELSE 'no_product'
                    END
                "),
            ]);
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (Schema::hasColumn('shoots', 'shoot_type')) {
                $table->dropColumn('shoot_type');
            }

            if (Schema::hasColumn('shoots', 'product_status')) {
                $table->dropColumn('product_status');
            }
        });
    }

    private function makeLegacyServiceNullable(): void
    {
        try {
            Schema::table('shoots', function (Blueprint $table) {
                $table->foreignId('service_id')->nullable()->change();
            });
        } catch (\Throwable) {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE shoots MODIFY service_id BIGINT UNSIGNED NULL');
            }
        }
    }
};
