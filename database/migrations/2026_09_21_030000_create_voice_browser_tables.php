<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_browser_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id');
            $table->string('credential_id')->nullable();
            $table->string('sip_username')->nullable();
            $table->string('status')->default('creating');
            $table->boolean('registered')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'device_id']);
        });
        Schema::create('voice_browser_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('voice_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users');
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('voice_browser_sessions');
            $table->string('mode');
            $table->string('state')->default('awaiting_agent');
            $table->string('conference_id')->nullable()->index();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'idempotency_key']);
        });
        Schema::create('voice_browser_legs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('browser_call_id');
            $table->foreign('browser_call_id')->references('id')->on('voice_browser_calls')->cascadeOnDelete();
            $table->uuid('session_id')->nullable();
            $table->foreign('session_id')->references('id')->on('voice_browser_sessions');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role');
            $table->string('mode')->nullable();
            $table->string('state')->default('pending');
            $table->string('call_control_id')->nullable()->index();
            $table->string('call_session_id')->nullable()->index();
            $table->string('browser_call_control_id')->nullable()->unique();
            $table->string('operation_key', 128)->nullable()->index();
            $table->string('client_state')->unique();
            $table->string('destination');
            $table->boolean('muted')->default(false);
            $table->boolean('held')->default(false);
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
        Schema::create('voice_browser_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('command_key')->unique();
            $table->string('path');
            $table->json('payload');
            $table->string('state')->default('pending');
            $table->json('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_browser_commands');
        Schema::dropIfExists('voice_browser_legs');
        Schema::dropIfExists('voice_browser_calls');
        Schema::dropIfExists('voice_browser_sessions');
    }
};
