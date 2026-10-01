<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_push_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('incoming_calls')->default(true);
            $table->timestamps();
        });
        Schema::create('voice_push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('label', 80);
            $table->uuid('scope');
            $table->string('revoke_hash', 64);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error', 100)->nullable();
            $table->timestamps();
        });
        Schema::create('voice_push_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('voice_push_subscriptions')->cascadeOnDelete();
            $table->foreignId('voice_call_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('offer_id');
            $table->uuid('scope');
            $table->string('event', 20);
            $table->string('status', 30)->default('queued');
            $table->timestamp('expires_at');
            $table->string('error_code', 100)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'offer_id', 'event'], 'voice_push_delivery_unique');
            $table->index(['offer_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_push_deliveries');
        Schema::dropIfExists('voice_push_subscriptions');
        Schema::dropIfExists('voice_push_preferences');
    }
};
