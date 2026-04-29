<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photographer_equipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photographer_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('serial_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->string('status')->default('pending_verification');
            $table->timestamp('verification_requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['photographer_id', 'status']);
        });

        Schema::create('photographer_equipment_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('photographer_equipments')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index(['equipment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photographer_equipment_photos');
        Schema::dropIfExists('photographer_equipments');
    }
};
