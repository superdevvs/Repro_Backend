<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds photographer_id to the shoot_service pivot table,
     * allowing different photographers to be assigned to different services
     * within the same shoot (multi-photographer per shoot feature).
     * 
     * Fallback logic: If photographer_id is NULL, use shoot.photographer_id
     */
    public function up(): void
    {
        Schema::table('shoot_service', function (Blueprint $table) {
            $table->foreignId('photographer_id')
                ->nullable()
                ->after('service_id')
                ->constrained('users')
                ->nullOnDelete();
            
            // Index for payout queries - we group by photographer_id frequently
            $table->index('photographer_id', 'shoot_service_photographer_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoot_service', function (Blueprint $table) {
            $table->dropIndex('shoot_service_photographer_id_index');
            $table->dropForeign(['photographer_id']);
            $table->dropColumn('photographer_id');
        });
    }
};
