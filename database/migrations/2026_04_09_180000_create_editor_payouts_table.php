<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('editor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shoot_id')->constrained('shoots')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('service_name');
            $table->unsignedInteger('quantity_snapshot')->default(1);
            $table->decimal('rate_snapshot', 10, 2)->default(0);
            $table->decimal('payout_amount', 10, 2)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payout_batch_id')->nullable();
            $table->timestamps();

            $table->unique(['editor_id', 'shoot_id', 'service_id'], 'editor_payouts_editor_shoot_service_unique');
            $table->index(['editor_id', 'is_paid', 'completed_at'], 'editor_payouts_editor_paid_completed_idx');
            $table->index(['payout_batch_id'], 'editor_payouts_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_payouts');
    }
};
