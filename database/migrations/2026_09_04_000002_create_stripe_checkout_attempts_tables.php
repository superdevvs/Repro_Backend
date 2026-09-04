<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stripe_checkout_attempts')) {
            Schema::create('stripe_checkout_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('scope', 64);
                $table->string('ui_mode', 32);
                $table->unsignedBigInteger('expected_amount_cents');
                $table->string('currency', 3);
                $table->string('status', 32)->default('creating');
                $table->string('request_fingerprint', 64);
                $table->string('idempotency_key', 255)->unique();
                $table->string('stripe_session_id')->nullable()->unique();
                $table->string('stripe_payment_intent_id')->nullable()->index();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('failure_message')->nullable();
                $table->timestamps();

                $table->index(['status', 'expires_at']);
                $table->index(['client_id', 'status']);
            });
        }

        if (! Schema::hasTable('stripe_checkout_attempt_items')) {
            Schema::create('stripe_checkout_attempt_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stripe_checkout_attempt_id')
                    ->constrained('stripe_checkout_attempts')
                    ->cascadeOnDelete();
                $table->foreignId('shoot_id')->constrained('shoots');
                $table->unsignedInteger('position');
                $table->unsignedBigInteger('expected_amount_cents');
                $table->json('allocation_payload')->nullable();
                $table->timestamps();

                $table->unique(
                    ['stripe_checkout_attempt_id', 'shoot_id'],
                    'stripe_attempt_items_attempt_shoot_unique'
                );
                $table->index(['shoot_id', 'stripe_checkout_attempt_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_checkout_attempt_items');
        Schema::dropIfExists('stripe_checkout_attempts');
    }
};
