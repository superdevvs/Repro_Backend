<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_ghost_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shoot_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_ghost_users');
    }
};
