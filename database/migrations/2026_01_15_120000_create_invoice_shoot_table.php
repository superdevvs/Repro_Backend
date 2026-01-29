<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_shoot')) {
            return;
        }

        Schema::create('invoice_shoot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['invoice_id', 'shoot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_shoot');
    }
};
