<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_subscription_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('subscription_id')->constrained('listing_studio_subscriptions')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('invoice_id');
            $table->string('recipient');
            $table->json('snapshot');
            $table->string('status')->default('queued')->index();
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_subscription_receipts');
    }
};
