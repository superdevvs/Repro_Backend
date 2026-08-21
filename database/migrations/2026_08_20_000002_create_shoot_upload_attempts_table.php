<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_upload_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key', 191);
            $table->char('request_fingerprint', 64);
            $table->string('upload_type', 20);
            $table->string('upload_batch_id', 191)->nullable();
            $table->unsignedInteger('upload_batch_index')->nullable();
            $table->unsignedInteger('upload_batch_total')->nullable();
            $table->foreignId('shoot_service_id')->nullable()->constrained('shoot_service')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('result_file_ids')->nullable();
            $table->json('result_errors')->nullable();
            $table->json('result_payload')->nullable();
            $table->uuid('correlation_id');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['shoot_id', 'actor_id', 'idempotency_key'], 'shoot_upload_attempt_actor_key_unique');
            $table->index(['status', 'created_at']);
            $table->index(['shoot_id', 'upload_batch_id', 'upload_batch_index'], 'shoot_upload_attempt_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_upload_attempts');
    }
};
