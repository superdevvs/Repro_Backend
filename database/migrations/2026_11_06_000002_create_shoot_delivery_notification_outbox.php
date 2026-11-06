<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shoot_delivery_notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained('shoots')->cascadeOnDelete();
            $table->string('deduplication_key', 64)->unique();
            $table->string('operation_key', 100);
            $table->string('cycle_key', 100);
            $table->boolean('is_full_order_delivery');
            $table->json('service_item_ids');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_delivery_notification_outbox');
    }
};
