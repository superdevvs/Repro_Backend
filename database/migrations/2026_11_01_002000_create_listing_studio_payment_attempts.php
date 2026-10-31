<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_stripe_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->constrained('users');
            $table->string('account_id');
            $table->boolean('livemode');
            $table->string('customer_id')->nullable();
            $table->json('create_parameters');
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'livemode', 'client_id'], 'studio_customer_client_unique');
            $table->unique(['account_id', 'livemode', 'customer_id'], 'studio_customer_external_unique');
        });
        Schema::create('listing_studio_checkout_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->constrained('users');
            $table->uuid('order_id')->nullable()->index();
            $table->string('plan_code')->nullable();
            $table->string('kind');
            $table->string('idempotency_key', 64);
            $table->string('request_hash', 64);
            $table->string('account_id');
            $table->boolean('livemode');
            $table->string('customer_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('payment_intent_id')->nullable();
            $table->string('subscription_id')->nullable();
            $table->string('currency', 3);
            $table->unsignedInteger('amount_cents');
            $table->string('status')->default('pending');
            $table->json('parameters')->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'idempotency_key'], 'studio_checkout_client_key_unique');
            $table->unique(['account_id', 'livemode', 'session_id'], 'studio_checkout_session_unique');
            $table->unique(['account_id', 'livemode', 'payment_intent_id'], 'studio_checkout_payment_unique');
        });
        Schema::create('listing_studio_payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('account_id');
            $table->boolean('livemode');
            $table->string('event_id');
            $table->string('event_type');
            $table->timestamp('processed_at');
            $table->unique(['account_id', 'livemode', 'event_id'], 'studio_payment_event_unique');
        });
        Schema::create('listing_studio_refund_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->index();
            $table->string('operation_id')->unique();
            $table->uuid('checkout_attempt_id');
            $table->string('account_id');
            $table->boolean('livemode');
            $table->string('payment_intent_id');
            $table->string('refund_id')->nullable();
            $table->string('currency', 3);
            $table->unsignedInteger('amount_cents');
            $table->string('status')->default('pending');
            $table->json('parameters')->nullable();
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'livemode', 'refund_id'], 'studio_refund_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_refund_attempts');
        Schema::dropIfExists('listing_studio_payment_events');
        Schema::dropIfExists('listing_studio_checkout_attempts');
        Schema::dropIfExists('listing_studio_stripe_customers');
    }
};
