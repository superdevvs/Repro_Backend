<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('shoot_compensation_id')
                ->nullable()
                ->after('shoot_id')
                ->constrained('shoot_compensations')
                ->nullOnDelete();
            $table->unique('shoot_compensation_id', 'invoice_items_shoot_compensation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropUnique('invoice_items_shoot_compensation_unique');
            $table->dropConstrainedForeignId('shoot_compensation_id');
        });
    }
};
