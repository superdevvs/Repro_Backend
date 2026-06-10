<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('service_areas')) {
            Schema::create('service_areas', function (Blueprint $table) {
                $table->id();
                $table->enum('kind', ['region', 'state', 'area']);
                $table->string('value');
                $table->string('label')->nullable();
                $table->timestamps();

                $table->index(['kind', 'value']);
            });
        }

        if (!Schema::hasTable('photographer_service_areas')) {
            Schema::create('photographer_service_areas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('service_area_id')->constrained('service_areas')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'service_area_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('photographer_service_areas');
        Schema::dropIfExists('service_areas');
    }
};
