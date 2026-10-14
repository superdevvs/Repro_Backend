<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_line_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_number', 20)->unique();
            $table->string('mode', 24)->default('staff_voicemail');
            $table->string('recipient_role')->nullable();
            $table->json('recipient_ids')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->json('weekly_hours');
            $table->string('office_greeting')->nullable();
            $table->string('closed_greeting')->nullable();
            $table->timestamps();
        });
        Schema::create('voice_voicemails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_call_id')->unique()->constrained('voice_calls')->cascadeOnDelete();
            $table->string('phone_number', 20)->index();
            $table->json('recipient_ids');
            $table->string('state', 24)->default('answering');
            $table->string('greeting_kind', 16);
            $table->string('recording_id', 100)->nullable()->unique();
            $table->string('recording_path')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('failure')->nullable();
            $table->timestamp('recording_started_at')->nullable();
            $table->timestamp('saved_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_voicemails');
        Schema::dropIfExists('voice_line_policies');
    }
};
