<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_staff_phones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('available')->default(true);
            $table->boolean('phone_enabled')->default(false);
            $table->string('phone')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('pending_phone')->nullable();
            $table->string('verification_hash')->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->unsignedTinyInteger('verification_attempts')->default(0);
            $table->timestamps();
        });
        Schema::create('voice_incoming_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('voice_call_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('waiting')->index();
            $table->json('eligible_user_ids');
            $table->timestamp('expires_at')->index();
            $table->foreignId('claimed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('claimed_session_id')->nullable();
            $table->string('claim_key', 128)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('browser_call_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('voice_phone_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('incoming_offer_id');
            $table->foreign('incoming_offer_id')->references('id')->on('voice_incoming_offers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('destination');
            $table->string('state')->default('pending');
            $table->string('call_control_id')->nullable()->index();
            $table->string('client_state')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['incoming_offer_id', 'user_id']);
        });
        Schema::create('voice_cancelled_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });
        Schema::table('voice_browser_calls', fn (Blueprint $table) => $table->uuid('session_id')->nullable()->change());
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_cancelled_attempts');
        Schema::dropIfExists('voice_phone_offers');
        Schema::dropIfExists('voice_incoming_offers');
        Schema::dropIfExists('voice_staff_phones');
        // Existing carrier staff calls may have no browser session; retain nullable.
    }
};
