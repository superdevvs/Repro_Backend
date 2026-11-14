<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iguide_lab_cases', fn (Blueprint $table) => $table->json('native_source')->nullable());
        Schema::create('iguide_lab_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases');
            $table->foreignUuid('derived_case_id')->nullable()->constrained('iguide_lab_cases');
            $table->uuid('request_id'); $table->string('request_hash', 64); $table->string('source_sha256', 64);
            $table->json('view'); $table->json('data')->nullable(); $table->string('status', 30)->default('queued');
            $table->text('error')->nullable(); $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable();
            $table->timestamps(); $table->unique(['project_id', 'request_id']);
        });
        Schema::create('iguide_lab_masks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases');
            $table->string('target_kind', 20); $table->uuid('target_id');
            $table->uuid('request_id'); $table->string('request_hash', 64);
            $table->string('source_sha256', 64); $table->string('uploaded_sha256', 64); $table->string('sha256', 64);
            $table->string('path'); $table->string('provider_path')->nullable(); $table->string('provider_sha256', 64)->nullable();
            $table->unsignedInteger('width'); $table->unsignedInteger('height'); $table->json('statistics');
            $table->timestamp('reviewed_at'); $table->timestamps(); $table->unique(['project_id', 'request_id']);
            $table->index(['target_kind', 'target_id']);
        });
        Schema::create('iguide_lab_compositions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases');
            $table->string('target_kind', 20); $table->uuid('target_id');
            $table->foreignUuid('run_id')->constrained('iguide_lab_runs');
            $table->foreignUuid('mask_id')->constrained('iguide_lab_masks');
            $table->foreignUuid('composite_id')->nullable()->constrained('iguide_lab_composites');
            $table->uuid('request_id'); $table->string('request_hash', 64); $table->string('status', 30)->default('queued');
            $table->json('data'); $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable();
            $table->timestamps(); $table->unique(['project_id', 'request_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('iguide_lab_compositions'); Schema::dropIfExists('iguide_lab_masks'); Schema::dropIfExists('iguide_lab_sections');
        Schema::table('iguide_lab_cases', fn (Blueprint $table) => $table->dropColumn('native_source'));
    }
};
