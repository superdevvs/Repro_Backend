<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tour analysis layer (Deploy 1, spec 4.5-4.7): runs, their reserved workflow chunks and their per-room targets.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('iguide_lab_tour_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('analysis_id')->constrained('iguide_lab_tour_analyses')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('request_hash', 64); $table->string('preset', 24);
            $table->string('status', 24)->default('creating');
            $table->unsignedInteger('selection_revision'); $table->string('selection_sha256', 64); $table->longText('estimate');
            $table->unsignedTinyInteger('chunk_size'); $table->string('error_code', 64)->nullable();
            $table->unsignedTinyInteger('consecutive_chunk_failures')->default(0); $table->unsignedSmallInteger('not_ready_ticks')->default(0);
            $table->timestamp('monitor_heartbeat_at')->nullable(); $table->uuid('monitor_token')->nullable(); $table->unsignedInteger('monitor_seq')->default(0);
            $table->timestamp('cancelled_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'request_id']);
            $table->index(['analysis_id', 'preset', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['status']);
        });
        Schema::create('iguide_lab_tour_run_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('iguide_lab_tour_runs')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->unsignedTinyInteger('position'); $table->uuid('request_id'); $table->longText('case_ids');
            $table->string('status', 24)->default('reserved'); $table->uuid('workflow_id')->nullable();
            $table->unsignedTinyInteger('start_attempts')->default(0); $table->string('error_code', 64)->nullable(); $table->longText('diagnostic')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'position']);
            $table->unique(['project_id', 'request_id']);
        });
        Schema::create('iguide_lab_tour_run_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('iguide_lab_tour_runs')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases');
            $table->unsignedSmallInteger('position'); $table->longText('candidate_ids'); $table->string('state', 24);
            $table->uuid('chunk_id')->nullable(); $table->uuid('workflow_id')->nullable(); $table->uuid('workflow_item_id')->nullable();
            $table->boolean('has_attempt')->default(false); $table->longText('reasons'); $table->timestamp('accepted_at')->nullable();
            $table->longText('acceptance_log')->default(new \Illuminate\Database\Query\Expression("('[]')")); $table->string('synced_fingerprint', 64)->nullable(); $table->timestamps();
            $table->unique(['run_id', 'case_id']);
            $table->index(['project_id', 'case_id', 'state']);
            $table->index(['workflow_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iguide_lab_tour_run_targets');
        Schema::dropIfExists('iguide_lab_tour_run_chunks');
        Schema::dropIfExists('iguide_lab_tour_runs');
    }
};
