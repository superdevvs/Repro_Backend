<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->string('mmm_status')->nullable()->after('is_private_listing');
            $table->string('mmm_order_number')->nullable()->after('mmm_status');
            $table->string('mmm_buyer_cookie')->nullable()->after('mmm_order_number');
            $table->text('mmm_redirect_url')->nullable()->after('mmm_buyer_cookie');
            $table->timestamp('mmm_last_punchout_at')->nullable()->after('mmm_redirect_url');
            $table->timestamp('mmm_last_order_at')->nullable()->after('mmm_last_punchout_at');
            $table->text('mmm_last_error')->nullable()->after('mmm_last_order_at');
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn([
                'mmm_status',
                'mmm_order_number',
                'mmm_buyer_cookie',
                'mmm_redirect_url',
                'mmm_last_punchout_at',
                'mmm_last_order_at',
                'mmm_last_error',
            ]);
        });
    }
};
