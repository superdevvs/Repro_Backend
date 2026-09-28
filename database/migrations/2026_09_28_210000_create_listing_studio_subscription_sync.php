<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_account_id');
            $table->boolean('livemode');
            $table->string('stripe_subscription_id');
            $table->string('stripe_customer_id');
            $table->string('stripe_price_id')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('plan_code')->nullable();
            $table->string('plan_name')->nullable();
            $table->string('status', 40);
            $table->string('sync_status', 40);
            $table->string('attention_reason')->nullable();
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('billing_interval', 20)->nullable();
            $table->unsignedInteger('billing_interval_count')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->string('latest_invoice_id')->nullable();
            $table->string('latest_invoice_status', 40)->nullable();
            $table->timestamp('last_paid_at')->nullable();
            $table->boolean('account_created')->default(false);
            $table->timestamps();
            $table->unique(['stripe_account_id', 'livemode', 'stripe_subscription_id'], 'listing_studio_subscription_identity');
            $table->index(['client_id', 'status']);
            $table->index(['sync_status', 'updated_at']);
        });
        Schema::create('listing_studio_stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_account_id');
            $table->boolean('livemode');
            $table->string('stripe_event_id');
            $table->string('event_type');
            $table->foreignId('subscription_id')->constrained('listing_studio_subscriptions')->cascadeOnDelete();
            $table->timestamp('processed_at');
            $table->unique(['stripe_account_id', 'livemode', 'stripe_event_id'], 'listing_studio_event_identity');
        });
        Schema::create('listing_studio_account_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('notification_state')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_account_setups');
        Schema::dropIfExists('listing_studio_stripe_events');
        Schema::dropIfExists('listing_studio_subscriptions');
    }
};
