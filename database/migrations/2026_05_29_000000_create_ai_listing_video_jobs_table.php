<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_listing_video_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('provider')->default('fal');
            $table->json('selected_file_ids');
            $table->unsignedSmallInteger('target_seconds');
            $table->string('status')->default('queued');
            $table->unsignedSmallInteger('total_clips')->default(0);
            $table->unsignedSmallInteger('completed_clips')->default(0);
            $table->json('outputs')->nullable();
            $table->json('provider_request_ids')->nullable();
            $table->decimal('estimated_cost', 8, 2)->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['shoot_id', 'status']);
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_listing_video_jobs');
    }
};
