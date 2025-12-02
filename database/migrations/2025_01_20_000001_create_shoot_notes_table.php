<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->onDelete('cascade');
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            
            $table->enum('type', ['shoot', 'company', 'photographer', 'editing'])->default('shoot');
            $table->enum('visibility', ['internal', 'photographer_only', 'client_visible'])->default('internal');
            $table->text('content');
            
            $table->timestamps();
            
            $table->index(['shoot_id', 'type']);
            $table->index(['shoot_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_notes');
    }
};

