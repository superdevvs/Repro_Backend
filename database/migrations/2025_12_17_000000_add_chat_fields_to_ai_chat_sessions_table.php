<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            // Note: SQLite doesn't support ->after(), so columns will be added at the end
            if (!Schema::hasColumn('ai_chat_sessions', 'engine')) {
                $table->string('engine')->default('rules');
            }
            if (!Schema::hasColumn('ai_chat_sessions', 'intent')) {
                $table->string('intent')->nullable();
            }
            if (!Schema::hasColumn('ai_chat_sessions', 'step')) {
                $table->string('step')->nullable();
            }
            if (!Schema::hasColumn('ai_chat_sessions', 'state_data')) {
                $table->json('state_data')->nullable();
            }
            if (!Schema::hasColumn('ai_chat_sessions', 'state')) {
                $table->json('state')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('ai_chat_sessions', 'state')) {
                $table->dropColumn('state');
            }
            if (Schema::hasColumn('ai_chat_sessions', 'state_data')) {
                $table->dropColumn('state_data');
            }
            if (Schema::hasColumn('ai_chat_sessions', 'step')) {
                $table->dropColumn('step');
            }
            if (Schema::hasColumn('ai_chat_sessions', 'intent')) {
                $table->dropColumn('intent');
            }
            if (Schema::hasColumn('ai_chat_sessions', 'engine')) {
                $table->dropColumn('engine');
            }
        });
    }
};
