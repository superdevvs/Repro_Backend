<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_media_albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->onDelete('cascade');
            $table->foreignId('photographer_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->enum('source', ['dropbox', 'local'])->default('dropbox');
            $table->string('folder_path');
            $table->string('cover_image_path')->nullable();
            $table->boolean('is_watermarked')->default(false);
            
            $table->timestamps();
            
            $table->index(['shoot_id', 'photographer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_media_albums');
    }
};

