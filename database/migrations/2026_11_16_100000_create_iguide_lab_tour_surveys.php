<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tour analysis layer (Deploy 1, spec 4.1-4.2): the paid, cacheable survey unit and its write-first attempt ledger.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('iguide_lab_tour_surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases');
            $table->uuid('source_import_id');
            $table->string('case_source_sha256', 64); $table->string('preview_sha256', 64); $table->string('survey_version', 32);
            $table->unsignedTinyInteger('generation')->default(1);
            $table->string('status', 24)->default('queued'); $table->string('error_code', 64)->nullable(); $table->string('parse_error', 120)->nullable();
            $table->longText('protocol'); $table->longText('data'); $table->longText('result')->nullable(); $table->string('result_sha256', 64)->nullable();
            $table->string('parser_version', 40)->nullable(); $table->string('provider_request_id', 255)->nullable(); $table->longText('usage')->nullable();
            $table->unsignedSmallInteger('dispatch_count')->default(0); $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'case_id', 'preview_sha256', 'survey_version', 'generation'], 'iguide_lab_tour_surveys_identity_unique');
            $table->index(['status', 'dispatched_at']);
            $table->index(['project_id', 'case_id']);
        });
        Schema::create('iguide_lab_tour_survey_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('survey_id')->constrained('iguide_lab_tour_surveys')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_index'); $table->string('credential_source', 24); $table->string('requested_model', 40);
            $table->string('status', 24)->default('submitting'); $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_request_id', 255)->nullable(); $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at'); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['survey_id', 'attempt_index']);
            $table->index(['project_id']);
            $table->index(['started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iguide_lab_tour_survey_attempts');
        Schema::dropIfExists('iguide_lab_tour_surveys');
    }
};
