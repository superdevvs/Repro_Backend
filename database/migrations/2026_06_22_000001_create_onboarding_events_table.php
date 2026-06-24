<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role', 50);
            $table->string('onboarding_key', 100);
            $table->unsignedInteger('version')->nullable();
            $table->string('event_type', 50); // started, step_viewed, step_back, completed, skipped, replayed, help_opened, help_message
            $table->integer('step_index')->nullable();
            $table->string('step_target', 100)->nullable();
            $table->string('session_uuid', 64)->index();
            $table->string('source', 100)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('user_id');
            $table->index(['role', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_events');
    }
};
