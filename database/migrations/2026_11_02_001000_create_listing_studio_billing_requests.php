<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['listing_studio_uploads', 'listing_studio_quotes', 'listing_studio_orders', 'listing_studio_checkout_attempts'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            });
        }
        Schema::create('listing_studio_billing_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('listing_studio_orders')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('listing_studio_subscriptions')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('plan_code', 30)->nullable();
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->uuid('checkout_key')->unique();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('amount_cents')->nullable();
            $table->string('currency', 3)->default('usd');
            $table->json('preview')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'client_id', 'request_key'], 'studio_billing_request_replay');
            $table->index(['client_id', 'status']);
        });
        Schema::create('listing_studio_billing_emails', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_request_id')->constrained('listing_studio_billing_requests')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('recipient');
            $table->string('status', 30)->default('queued');
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['billing_request_id', 'request_key'], 'studio_billing_email_replay');
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_billing_emails');
        Schema::dropIfExists('listing_studio_billing_requests');
        foreach (['listing_studio_checkout_attempts', 'listing_studio_orders', 'listing_studio_quotes', 'listing_studio_uploads'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropConstrainedForeignId('actor_id'));
        }
    }
};
