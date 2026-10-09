<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('shoot_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNull('shoot_id')->exists()) {
            throw new RuntimeException('Invoice-only payment records must be preserved; cannot restore a required shoot_id.');
        }Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('shoot_id')->nullable(false)->change();
        });
    }
};
