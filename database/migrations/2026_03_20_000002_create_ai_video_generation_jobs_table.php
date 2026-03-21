<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_video_generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('preset_id')->constrained('ai_video_presets')->onDelete('cascade');
            $table->foreignId('start_frame_file_id')->nullable()->constrained('shoot_files')->onDelete('set null');
            $table->foreignId('end_frame_file_id')->nullable()->constrained('shoot_files')->onDelete('set null');
            $table->text('preset_prompt'); // Actual interpolated prompt sent to Higgsfield (for audit)
            $table->enum('aspect_ratio', ['horizontal', 'vertical', 'square', 'standard']);
            $table->enum('status', [
                'pending',
                'converting_aspect',
                'awaiting_approval',
                'generating',
                'completed',
                'failed',
                'cancelled',
            ])->default('pending');
            $table->string('higgsfield_video_request_id')->nullable();
            $table->string('original_start_frame_url');
            $table->string('original_end_frame_url')->nullable();
            $table->string('selected_start_frame_url')->nullable(); // Chosen vertical variant
            $table->string('selected_end_frame_url')->nullable(); // Chosen vertical variant
            $table->string('video_url')->nullable();
            $table->string('video_thumbnail_url')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['shoot_id', 'status']);
            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_video_generation_jobs');
    }
};
