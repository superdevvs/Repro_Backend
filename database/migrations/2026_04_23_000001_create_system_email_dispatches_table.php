<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_email_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('email_type');
            $table->string('email_alias');
            $table->unsignedInteger('email_version')->default(1);
            $table->string('category');
            $table->string('idempotency_key')->unique();
            $table->uuid('correlation_id');
            $table->string('recipient_email');
            $table->string('recipient_type')->nullable();
            $table->foreignId('related_account_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('related_shoot_id')->nullable()->constrained('shoots')->nullOnDelete();
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('send_source')->default('SYSTEM');
            $table->string('delivery_mode')->default('sync');
            $table->string('template_view');
            $table->string('template_version')->default('v1');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('payload_snapshot')->nullable();
            $table->json('transport_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['email_alias', 'recipient_email']);
            $table->index(['related_account_id', 'email_alias']);
            $table->index(['related_shoot_id', 'email_alias']);
            $table->index(['related_invoice_id', 'email_alias']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_email_dispatches');
    }
};
