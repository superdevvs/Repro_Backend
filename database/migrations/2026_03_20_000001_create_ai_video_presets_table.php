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
        Schema::create('ai_video_presets', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description');
            $table->string('icon')->default('Film'); // Lucide icon name
            $table->string('category')->default('Lighting'); // Lighting, Movement, Staging, Specialty
            $table->text('prompt_template'); // Hidden prompt sent to Higgsfield
            $table->tinyInteger('max_frames')->default(2); // 1 or 2
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_video_presets');
    }
};
