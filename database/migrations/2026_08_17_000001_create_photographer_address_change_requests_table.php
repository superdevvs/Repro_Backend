<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('photographer_address_change_requests')) {
            return;
        }

        Schema::create('photographer_address_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('street_address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 50)->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photographer_address_change_requests');
    }
};
