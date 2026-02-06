<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('hold_requested_at')->nullable()->after('cancellation_reason');
            $table->unsignedBigInteger('hold_requested_by')->nullable()->after('hold_requested_at');
            $table->text('hold_reason')->nullable()->after('hold_requested_by');

            $table->foreign('hold_requested_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropForeign(['hold_requested_by']);
            $table->dropColumn(['hold_requested_at', 'hold_requested_by', 'hold_reason']);
        });
    }
};
