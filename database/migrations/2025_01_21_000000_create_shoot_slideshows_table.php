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
        Schema::create('shoot_slideshows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->onDelete('cascade');
            $table->string('title');
            $table->enum('orientation', ['portrait', 'landscape'])->default('landscape');
            $table->string('transition')->default('fade');
            $table->integer('speed')->default(3); // seconds per slide
            $table->json('photo_urls')->nullable(); // Array of photo URLs used
            $table->json('photo_ids')->nullable(); // Array of shoot_file IDs
            $table->string('ayrshare_id')->nullable(); // Ayrshare slideshow ID
            $table->string('ayrshare_url')->nullable(); // Ayrshare slideshow URL
            $table->string('download_url')->nullable(); // Download URL for the slideshow
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->index('shoot_id');
            $table->index('ayrshare_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shoot_slideshows');
    }
};


