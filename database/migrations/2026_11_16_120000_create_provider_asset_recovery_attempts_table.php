<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retry schedule for provider floorplan assets that keep failing to import.
 * Kept in the database because every deploy clears the application cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_asset_recovery_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 120)->unique();
            $table->string('channel', 32);
            $table->unsignedBigInteger('shoot_id')->index();
            $table->unsignedBigInteger('shoot_service_id')->nullable();
            $table->char('fingerprint', 64);
            $table->unsignedInteger('attempts');
            $table->timestamp('first_attempt_at');
            $table->timestamp('last_attempt_at');
            $table->timestamp('next_attempt_at');
            $table->timestamp('gave_up_at')->nullable();
            $table->timestamps();
            $table->index(['channel', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_asset_recovery_attempts');
    }
};
