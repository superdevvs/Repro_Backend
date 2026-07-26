<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('media_ref');
            $table->enum('kind', ['source', 'output']);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_media');
    }
};
