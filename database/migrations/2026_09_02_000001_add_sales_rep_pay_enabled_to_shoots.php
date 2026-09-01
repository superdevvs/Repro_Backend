<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            // Existing standard shoots retain their ordinary commission behavior.
            // Paid return visits can opt out without dropping the inherited rep,
            // which remains important for ownership, visibility, and messaging.
            $table->boolean('sales_rep_pay_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn('sales_rep_pay_enabled');
        });
    }
};
