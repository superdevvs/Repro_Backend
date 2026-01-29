<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('cancellation_requested_at')->nullable()->after('declined_reason');
            $table->unsignedBigInteger('cancellation_requested_by')->nullable()->after('cancellation_requested_at');
            $table->text('cancellation_reason')->nullable()->after('cancellation_requested_by');
            
            $table->foreign('cancellation_requested_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropForeign(['cancellation_requested_by']);
            $table->dropColumn(['cancellation_requested_at', 'cancellation_requested_by', 'cancellation_reason']);
        });
    }
};
