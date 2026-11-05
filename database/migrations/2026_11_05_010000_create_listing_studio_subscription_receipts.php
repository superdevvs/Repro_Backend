<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Support\MigrationForeignId;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_studio_subscription_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            MigrationForeignId::matching($table, 'subscription_id', 'listing_studio_subscriptions')->constrained('listing_studio_subscriptions')->cascadeOnDelete();
            MigrationForeignId::matching($table, 'client_id', 'users')->constrained('users')->cascadeOnDelete();
            MigrationForeignId::matching($table, 'message_id', 'messages')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('invoice_id');
            $table->string('recipient');
            $table->json('snapshot');
            $table->string('status')->default('queued')->index();
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'invoice_id'], 'ls_receipt_subscription_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_studio_subscription_receipts');
    }
};
