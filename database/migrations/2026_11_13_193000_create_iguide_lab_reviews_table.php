<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iguide_lab_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('iguide_lab_runs')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->string('verdict', 20);
            $table->json('checks');
            $table->text('notes');
            $table->string('pipeline', 30);
            $table->string('scope', 30);
            $table->string('batch_id', 100)->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'request_id']);
            $table->index(['run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iguide_lab_reviews');
    }
};
