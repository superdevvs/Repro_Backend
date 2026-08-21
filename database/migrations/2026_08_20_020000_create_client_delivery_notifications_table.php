<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_delivery_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shoot_id')->constrained('shoots')->cascadeOnDelete();
            $table->uuid('delivery_event_key');
            $table->timestamp('delivered_at');
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'delivery_event_key'], 'client_delivery_event_unique');
            $table->index(['user_id', 'seen_at', 'delivered_at'], 'client_delivery_unseen_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_delivery_notifications');
    }
};
