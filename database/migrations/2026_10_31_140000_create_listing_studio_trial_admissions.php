<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_trial_admissions', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->string('session_hash', 64)->index();
            $table->string('idempotency_key', 64);
            $table->string('ip_hash', 64)->nullable();
            $table->string('challenge_hash', 64)->nullable();
            $table->uuid('verification_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->uuid('trial_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_trial_admissions');
    }
};
