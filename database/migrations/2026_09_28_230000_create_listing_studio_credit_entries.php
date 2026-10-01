<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('listing_studio_subscriptions')->cascadeOnDelete();
            $table->string('stripe_invoice_id');
            $table->string('source_type', 30);
            $table->string('source_key');
            $table->string('plan_code')->nullable();
            $table->bigInteger('amount_cents');
            $table->string('currency', 10);
            $table->timestamp('period_start');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['subscription_id', 'source_type', 'source_key'], 'listing_studio_credit_source');
            $table->index(['subscription_id', 'expires_at']);
            $table->index('stripe_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_credit_entries');
    }
};
