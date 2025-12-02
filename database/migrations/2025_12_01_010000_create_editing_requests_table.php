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
        Schema::create('editing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->nullable()->constrained('shoots')->nullOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->string('tracking_code')->unique();
            $table->string('summary');
            $table->text('details')->nullable();
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->enum('status', ['open', 'in_progress', 'completed'])->default('open');
            $table->string('target_team')->default('editor');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('editing_requests');
    }
};

