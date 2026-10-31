<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('service', 32);
            $table->string('source_kind', 32);
            $table->uuid('source_id');
            $table->json('source_snapshot');
            $table->json('options');
            $table->text('instructions')->nullable();
            $table->json('pricing_snapshot');
            $table->foreignId('subscription_id')->nullable()->constrained('listing_studio_subscriptions')->restrictOnDelete();
            $table->string('catalog_version', 64);
            $table->string('request_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'expires_at']);
        });
        Schema::create('listing_studio_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('quote_id')->unique()->constrained('listing_studio_quotes')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('service', 32);
            $table->string('funding_method', 16);
            $table->string('funding_status', 32)->default('pending');
            $table->string('currency', 3)->default('usd');
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('paid_amount_cents')->default(0);
            $table->unsignedInteger('refunded_amount_cents')->default(0);
            $table->string('payment_reference')->nullable()->unique();
            $table->json('payment_evidence')->nullable();
            $table->json('pricing_snapshot');
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->timestamps();
            $table->unique(['client_id', 'idempotency_key']);
            $table->index(['client_id', 'created_at']);
        });
        Schema::create('listing_studio_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained('listing_studio_orders')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('service', 32);
            $table->string('status', 32)->default('awaiting_funding');
            $table->string('source_kind', 32);
            $table->uuid('source_id');
            $table->json('source_snapshot');
            $table->json('options');
            $table->text('instructions')->nullable();
            $table->uuid('workspace_id')->nullable()->unique();
            $table->json('output_descriptor')->nullable();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->uuid('processing_attempt_id')->nullable();
            $table->timestamp('processing_lease_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
            $table->index(['source_kind', 'source_id']);
        });
        Schema::create('listing_studio_credit_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained('listing_studio_orders')->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('listing_studio_subscriptions')->restrictOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->json('allocations');
            $table->string('status', 16)->default('reserved');
            $table->timestamp('period_start');
            $table->timestamp('expires_at');
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'status', 'expires_at']);
        });
        Schema::create('listing_studio_order_funding_effects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('listing_studio_orders')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('external_key');
            $table->string('status', 24);
            $table->unsignedInteger('amount_cents');
            $table->json('evidence');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['kind', 'external_key']);
            $table->index(['order_id', 'kind', 'status']);
        });
        Schema::create('listing_studio_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 32);
            $table->uuid('aggregate_id');
            $table->string('idempotency_key')->unique();
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('reserved_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_outbox');
        Schema::dropIfExists('listing_studio_order_funding_effects');
        Schema::dropIfExists('listing_studio_credit_reservations');
        Schema::dropIfExists('listing_studio_order_items');
        Schema::dropIfExists('listing_studio_orders');
        Schema::dropIfExists('listing_studio_quotes');
    }
};
