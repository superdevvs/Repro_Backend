<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table): void {
            if (!Schema::hasColumn('ai_chat_sessions', 'channel')) {
                $table->string('channel', 16)->default('WEB');
                $table->index('channel');
            }
            if (!Schema::hasColumn('ai_chat_sessions', 'phone_e164')) {
                $table->string('phone_e164', 32)->nullable();
                $table->index('phone_e164');
            }
            if (!Schema::hasColumn('ai_chat_sessions', 'contact_id')) {
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->index('contact_id');
            }
            if (!Schema::hasColumn('ai_chat_sessions', 'last_inbound_at')) {
                $table->timestamp('last_inbound_at')->nullable();
            }
        });

        // Note: ai_chat_sessions.user_id is intentionally left NOT NULL.
        // Unidentified SMS senders never get an AiChatSession; the SMS agent service
        // returns a static identification prompt and only creates a session once a
        // verified user_id is resolved.
    }

    public function down(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table): void {
            foreach (['last_inbound_at', 'contact_id', 'phone_e164', 'channel'] as $col) {
                if (Schema::hasColumn('ai_chat_sessions', $col)) {
                    if (in_array($col, ['phone_e164', 'channel', 'contact_id'], true)) {
                        try { $table->dropIndex([$col]); } catch (\Throwable $e) {}
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
