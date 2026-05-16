<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('direction')->index();
            $table->string('status')->index();
            $table->string('disposition')->nullable()->index();
            $table->string('from_phone')->nullable()->index();
            $table->string('to_phone')->nullable()->index();
            $table->foreignId('caller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('caller_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('assistant_id')->nullable()->index();
            $table->string('call_control_id')->nullable()->index();
            $table->string('telnyx_conversation_id')->nullable()->index();
            $table->foreignId('ai_chat_session_id')->nullable()->constrained('ai_chat_sessions')->nullOnDelete();
            $table->foreignId('related_shoot_id')->nullable()->constrained('shoots')->nullOnDelete();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('recording_url')->nullable();
            $table->boolean('recording_consent_given')->default(false);
            $table->longText('transcript')->nullable();
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->string('client_state')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_calls');
    }
};
