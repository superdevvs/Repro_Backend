<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mmm_punchout_sessions', function (Blueprint $table) {
            $table->string('return_token', 128)->nullable()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('mmm_punchout_sessions', function (Blueprint $table) {
            $table->dropColumn('return_token');
        });
    }
};
