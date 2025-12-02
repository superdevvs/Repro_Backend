<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained('ai_chat_sessions')->onDelete('cascade');
            $table->enum('sender', ['user', 'assistant', 'system'])->default('user');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('chat_session_id');
            $table->index('sender');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};






