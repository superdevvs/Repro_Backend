<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_transcript_recoveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('voice_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recording_id', 100);
            $table->string('source_call_control_id');
            $table->unsignedBigInteger('source_event_id');
            $table->string('idempotency_key', 128);
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('attempt');
            $table->string('model')->default('openai/whisper-large-v3-turbo');
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['voice_call_id', 'idempotency_key']);
            $table->index(['voice_call_id', 'recording_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_transcript_recoveries');
    }
};
