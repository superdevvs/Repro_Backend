<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('team_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('shoot_id')->nullable()->constrained('shoots')->nullOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->enum('source_type', ['shoot', 'upload']);
            $table->string('workflow_id', 64);
            $table->string('status', 32)->default('draft');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->index(['team_id', 'updated_at']);
            $table->index(['team_id', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
