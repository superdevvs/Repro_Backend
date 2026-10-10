<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_read_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context', 100);
            $table->unsignedBigInteger('last_seen_at')->nullable();
            $table->json('read_ids');
            $table->timestamps();
            $table->unique(['user_id', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_read_states');
    }
};
