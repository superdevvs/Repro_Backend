<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reel_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('provider')->default('fal');
            $table->json('selected_file_ids');
            $table->string('status')->default('queued');
            $table->json('outputs')->nullable();
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
        Schema::dropIfExists('ai_reel_jobs');
    }
};
