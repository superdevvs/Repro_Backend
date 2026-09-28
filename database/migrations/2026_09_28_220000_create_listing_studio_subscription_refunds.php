<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_subscription_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_account_id');
            $table->boolean('livemode');
            $table->string('stripe_refund_id');
            $table->string('stripe_subscription_id');
            $table->string('stripe_customer_id');
            $table->string('stripe_invoice_id');
            $table->string('stripe_payment_intent_id');
            $table->string('stripe_charge_id');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 10);
            $table->string('status', 30);
            $table->timestamp('refund_created_at');
            $table->string('last_event_id');
            $table->timestamps();
            $table->unique(['stripe_account_id', 'livemode', 'stripe_refund_id'], 'listing_studio_refund_identity');
            $table->index(['stripe_account_id', 'livemode', 'stripe_subscription_id'], 'listing_studio_refund_subscription');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_subscription_refunds');
    }
};
