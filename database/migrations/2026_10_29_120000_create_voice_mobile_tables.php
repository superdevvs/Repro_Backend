<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_mobile_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('device_id');
            $t->string('apns_environment', 16);
            $t->string('bundle_id');
            $t->text('push_token')->nullable();
            $t->string('push_token_hash', 64)->nullable()->unique();
            $t->string('connection_id')->nullable()->unique();
            $t->string('connection_username');
            $t->string('credential_id')->nullable();
            $t->string('sip_username')->nullable();
            $t->string('status')->default('creating');
            $t->timestamp('provision_attempted_at')->nullable();
            $t->timestamp('credential_attempted_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('registration_expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'device_id']);
        });
        Schema::create('voice_mobile_tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('session_id')->constrained('voice_mobile_sessions');
            $t->uuid('idempotency_key');
            $t->string('destination');
            $t->string('caller_number');
            $t->string('state')->default('pending');
            $t->timestamp('expires_at');
            $t->string('call_control_id')->nullable()->unique();
            $t->foreignId('voice_call_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['session_id', 'idempotency_key']);
        });
        Schema::create('voice_mobile_offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('incoming_offer_id')->constrained('voice_incoming_offers')->cascadeOnDelete();
            $t->foreignUuid('session_id')->constrained('voice_mobile_sessions');
            $t->uuid('sdk_call_id')->nullable();
            $t->string('state')->default('pending');
            $t->string('call_control_id')->nullable()->unique();
            $t->string('client_state')->unique();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->unique(['incoming_offer_id', 'session_id']);
            $t->unique(['session_id', 'sdk_call_id']);
        });
        Schema::table('voice_browser_legs', fn (Blueprint $t) => $t->foreignUuid('mobile_session_id')->nullable()->constrained('voice_mobile_sessions'));
    }

    public function down(): void
    {
        Schema::table('voice_browser_legs', fn (Blueprint $t) => $t->dropConstrainedForeignId('mobile_session_id'));
        Schema::dropIfExists('voice_mobile_offers');
        Schema::dropIfExists('voice_mobile_tickets');
        Schema::dropIfExists('voice_mobile_sessions');
    }
};
