<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_follow_up_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_call_id')->unique()->constrained('voice_calls')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('related_shoot_id')->nullable()->constrained('shoots')->nullOnDelete();
            $table->string('title', 160);
            $table->timestamp('due_at');
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['assigned_to_user_id', 'status', 'due_at']);
        });

        Schema::create('voice_wrap_up_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_call_id')->constrained('voice_calls')->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->json('request_payload');
            $table->string('sms_status', 20)->default('draft');
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->json('response_json')->nullable();
            $table->timestamps();
            $table->unique(['voice_call_id', 'idempotency_key'], 'voice_wrap_up_operation_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_wrap_up_operations');
        Schema::dropIfExists('voice_follow_up_tasks');
    }
};
