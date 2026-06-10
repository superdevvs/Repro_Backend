<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedicated scheduled-reminder tracking table for the Payment_Reminder cadence (Req 12).
     *
     * Reminders are still dispatched as Message rows by DispatchScheduledMessages, but the
     * generic `messages` table cannot carry a (shoot_id, scheduled_date) uniqueness guarantee
     * without breaking every other message type that shares a shoot/date. This table is the
     * canonical home for the no-duplicate guard (Req 12.15): AutomationService upserts one row
     * per (shoot_id, scheduled_date), and the dispatcher records the send + links the Message.
     */
    public function up(): void
    {
        if (Schema::hasTable('payment_reminders')) {
            return;
        }

        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status')->default('pending'); // pending, sent, cancelled
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Req 12.15 — at most one Payment_Reminder per Shoot per scheduled reminder date.
            $table->unique(['shoot_id', 'scheduled_date'], 'payment_reminders_shoot_date_unique');
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminders');
    }
};
