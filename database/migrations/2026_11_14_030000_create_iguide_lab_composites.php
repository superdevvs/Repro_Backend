<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iguide_lab_composites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->string('label', 160);
            $table->string('status', 30)->default('completed');
            $table->string('preview_path');
            $table->string('native_path')->nullable();
            $table->json('manifest');
            $table->timestamps();
            $table->unique(['project_id', 'request_id']);
        });
        Schema::create('iguide_lab_composite_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('composite_id')->constrained('iguide_lab_composites')->cascadeOnDelete();
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
            $table->index(['composite_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iguide_lab_composite_reviews');
        Schema::dropIfExists('iguide_lab_composites');
    }
};
