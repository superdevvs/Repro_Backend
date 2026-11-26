<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tour analysis layer (Deploy 1, spec 4.4): one row per import source; survey_id is late-bound (no foreign key).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('iguide_lab_tour_captures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('analysis_id')->constrained('iguide_lab_tour_analyses')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases');
            $table->unsignedSmallInteger('position');
            $table->string('room_name', 200)->nullable(); $table->string('floor_name', 200)->nullable();
            $table->string('source_sha256', 64); $table->string('preview_sha256', 64); $table->boolean('exterior_hint')->default(false);
            $table->string('status', 24)->default('pending'); $table->string('error_code', 64)->nullable(); $table->uuid('survey_id')->nullable();
            $table->boolean('dispatch_allowed')->default(true); $table->unsignedTinyInteger('paid_survey_requests')->default(0);
            $table->longText('survey')->nullable(); $table->longText('applicability')->nullable(); $table->longText('candidates')->nullable();
            $table->string('defaults_version', 24)->nullable(); $table->longText('defaults_flags')->nullable();
            $table->timestamp('surveyed_at')->nullable(); $table->timestamps();
            $table->unique(['analysis_id', 'case_id']);
            $table->unique(['analysis_id', 'position']);
            $table->index(['survey_id']);
            $table->index(['project_id', 'case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iguide_lab_tour_captures');
    }
};
