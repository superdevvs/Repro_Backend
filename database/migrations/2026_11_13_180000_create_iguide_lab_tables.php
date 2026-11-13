<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iguide_lab_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedInteger('max_runs')->default(60);
            $table->unsignedInteger('used_runs')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('iguide_lab_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->string('label');
            $table->string('room_id')->nullable();
            $table->string('pano_id')->nullable();
            $table->text('tour_url')->nullable();
            $table->string('projection', 24);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('source_sha256', 64);
            $table->string('source_path');
            $table->string('source_mime', 40);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('iguide_lab_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->string('model_id');
            $table->string('provider', 20);
            $table->string('preset', 30);
            $table->string('style', 30)->nullable();
            $table->text('prompt');
            $table->json('options');
            $table->string('status', 30)->default('queued');
            $table->string('provider_request_id')->nullable();
            $table->json('usage')->nullable();
            $table->string('output_path')->nullable();
            $table->unsignedInteger('output_width')->nullable();
            $table->unsignedInteger('output_height')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'request_id']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iguide_lab_runs');
        Schema::dropIfExists('iguide_lab_cases');
        Schema::dropIfExists('iguide_lab_projects');
    }
};
