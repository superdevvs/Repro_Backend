<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_service_earning_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            // The original booked identity survives deletion; intentionally not a foreign key.
            $table->unsignedBigInteger('shoot_service_id');
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->date('scheduled_date')->index();
            $table->timestamp('earned_at');
            $table->decimal('amount', 12, 2);
            $table->json('snapshot');
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('shoot_service_id', 'service_earned_pay_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_service_earning_snapshots');
    }
};
