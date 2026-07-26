<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('team_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('workflow_id', 64);
            $table->json('config');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->index(['team_id', 'updated_at']);
            $table->index(['team_id', 'workflow_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
