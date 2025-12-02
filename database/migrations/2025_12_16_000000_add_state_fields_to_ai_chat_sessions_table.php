<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            // Check if columns already exist (for safety)
            // Note: SQLite doesn't support ->after(), so columns will be added at the end
            
            // Engine: 'rules' | 'openai'
            if (!Schema::hasColumn('ai_chat_sessions', 'engine')) {
                $table->string('engine')->default('rules');
            }
            
            // Intent: 'book_shoot', 'manage_booking', etc.
            if (!Schema::hasColumn('ai_chat_sessions', 'intent')) {
                $table->string('intent')->nullable();
            }
            
            // State data for rule-based flows (keeping state_data for backward compatibility)
            if (!Schema::hasColumn('ai_chat_sessions', 'state_data')) {
                $table->json('state_data')->nullable();
            }
            
            // State: alternative name for state_data (for future use)
            if (!Schema::hasColumn('ai_chat_sessions', 'state')) {
                $table->json('state')->nullable();
            }
            
            // Step: current step in the flow
            if (!Schema::hasColumn('ai_chat_sessions', 'step')) {
                $table->string('step')->nullable();
            }
            
            // Meta field for suggestions, stats, misc data
            if (!Schema::hasColumn('ai_chat_sessions', 'meta')) {
                $table->json('meta')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('ai_chat_sessions', 'meta')) {
                $table->dropColumn('meta');
            }
            if (Schema::hasColumn('ai_chat_sessions', 'state')) {
                $table->dropColumn('state');
            }
            if (Schema::hasColumn('ai_chat_sessions', 'step')) {
                $table->dropColumn('step');
            }
            if (Schema::hasColumn('ai_chat_sessions', 'state_data')) {
                $table->dropColumn('state_data');
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

