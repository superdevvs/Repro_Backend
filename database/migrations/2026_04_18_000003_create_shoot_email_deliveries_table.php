<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->string('recipient_type');
            $table->string('status');
            $table->string('source');
            $table->string('reason_code')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->foreignId('last_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(['shoot_id', 'event_type', 'recipient_type'], 'shoot_email_deliveries_unique_event');
            $table->index(['event_type', 'recipient_type', 'status'], 'shoot_email_deliveries_status_idx');
            $table->index(['recipient_user_id', 'status'], 'shoot_email_deliveries_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_email_deliveries');
    }
};
