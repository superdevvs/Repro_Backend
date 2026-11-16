<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tour analysis layer (Deploy 1, spec 4.3-4.3a): one shared record per (project, import) and every requestId it answered.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('iguide_lab_tour_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('import_id')->constrained('iguide_lab_imports')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('request_hash', 64); $table->string('priority_preset', 24);
            $table->string('status', 24)->default('queued'); $table->unsignedInteger('record_revision')->default(0); $table->json('selections');
            $table->timestamp('monitor_heartbeat_at')->nullable(); $table->uuid('monitor_token')->nullable();
            $table->unsignedInteger('monitor_seq')->default(0); $table->boolean('monitor_idle')->default(false);
            $table->timestamp('cancelled_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'request_id']);
            $table->unique(['project_id', 'import_id']);
            $table->index(['project_id', 'created_at']);
            $table->index(['status']);
        });
        Schema::create('iguide_lab_tour_analysis_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('analysis_id')->constrained('iguide_lab_tour_analyses')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('request_hash', 64); $table->timestamps();
            $table->unique(['project_id', 'request_id']);
            $table->index(['analysis_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iguide_lab_tour_analysis_requests');
        Schema::dropIfExists('iguide_lab_tour_analyses');
    }
};
