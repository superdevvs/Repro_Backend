<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iguide_offline_upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 64);
            $table->string('original_filename');
            $table->unsignedBigInteger('size_bytes');
            $table->char('expected_sha256', 64)->nullable();
            $table->unsignedInteger('chunk_size_bytes');
            $table->unsignedInteger('total_chunks');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->string('status', 24);
            $table->text('error')->nullable();
            $table->boolean('retryable')->default(false);
            $table->foreignId('shoot_file_id')->nullable()->constrained('shoot_files')->nullOnDelete();
            $table->timestamp('last_activity_at');
            $table->timestamp('expires_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->uuid('assembly_token')->nullable();
            $table->timestamp('assembly_lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['shoot_id', 'idempotency_key'], 'iguide_upload_shoot_idempotency_unique');
            $table->index(['shoot_id', 'status'], 'iguide_upload_shoot_status_index');
            $table->index(['status', 'expires_at'], 'iguide_upload_status_expiry_index');
        });

        Schema::create('iguide_offline_upload_chunks', function (Blueprint $table) {
            $table->id();
            $table->uuid('upload_session_id');
            $table->unsignedInteger('chunk_index');
            $table->unsignedBigInteger('offset_bytes');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('storage_path');
            $table->timestamps();

            $table->foreign('upload_session_id', 'iguide_chunk_session_foreign')
                ->references('id')
                ->on('iguide_offline_upload_sessions')
                ->cascadeOnDelete();
            $table->unique(['upload_session_id', 'chunk_index'], 'iguide_chunk_session_index_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iguide_offline_upload_chunks');
        Schema::dropIfExists('iguide_offline_upload_sessions');
    }
};
