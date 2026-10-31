<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_subscription_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('actor_id')->constrained('users');
            $table->foreignId('subscription_id')->constrained('listing_studio_subscriptions');
            $table->uuid('billing_request_id')->nullable()->unique();
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->string('kind', 40);
            $table->string('status', 40)->default('reviewing');
            $table->string('account_id');
            $table->boolean('livemode');
            $table->string('customer_id');
            $table->string('stripe_subscription_id');
            $table->string('item_id');
            $table->string('current_plan_code', 40);
            $table->string('plan_code', 40)->nullable();
            $table->string('source_price_id');
            $table->string('target_price_id')->nullable();
            $table->json('snapshot');
            $table->json('preview')->nullable();
            $table->json('steps')->nullable();
            $table->string('confirmation_token_hash', 64)->nullable();
            $table->timestamp('review_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('invoice_id')->nullable();
            $table->string('schedule_id')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'request_key']);
            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_subscription_actions');
    }
};
