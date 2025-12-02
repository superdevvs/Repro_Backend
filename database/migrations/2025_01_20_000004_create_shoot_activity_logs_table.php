<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->string('action'); // e.g., 'shoot_scheduled_email', 'payment_done', 'media_uploaded', etc.
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // Store additional context like email recipients, amounts, etc.
            
            $table->timestamps();
            
            $table->index(['shoot_id', 'action']);
            $table->index(['shoot_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_activity_logs');
    }
};

