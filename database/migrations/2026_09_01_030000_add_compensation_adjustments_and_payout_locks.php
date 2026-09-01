<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_compensations', function (Blueprint $table) {
            $table->string('line_type', 16)->default('base')->after('scope_key');
            $table->foreignId('adjusts_compensation_id')
                ->nullable()
                ->after('line_type')
                ->constrained('shoot_compensations')
                ->restrictOnDelete();
            $table->index(
                ['adjusts_compensation_id', 'line_type'],
                'shoot_compensations_adjustment_index'
            );
        });

        Schema::create('payout_generation_locks', function (Blueprint $table) {
            $table->string('lock_key', 160)->primary();
            $table->string('recipient_role', 32);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_generation_locks');

        Schema::table('shoot_compensations', function (Blueprint $table) {
            $table->dropIndex('shoot_compensations_adjustment_index');
            $table->dropForeign(['adjusts_compensation_id']);
            $table->dropColumn(['adjusts_compensation_id', 'line_type']);
        });
    }
};
