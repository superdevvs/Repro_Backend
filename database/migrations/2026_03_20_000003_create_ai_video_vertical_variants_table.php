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
        Schema::create('ai_video_vertical_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_generation_job_id')->constrained('ai_video_generation_jobs')->onDelete('cascade');
            $table->enum('frame_type', ['start', 'end']);
            $table->tinyInteger('variant_index'); // 0, 1, 2
            $table->string('higgsfield_request_id');
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('image_url')->nullable(); // Result URL from Higgsfield
            $table->boolean('is_selected')->default(false);
            $table->timestamps();

            $table->index(['video_generation_job_id', 'frame_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_video_vertical_variants');
    }
};
