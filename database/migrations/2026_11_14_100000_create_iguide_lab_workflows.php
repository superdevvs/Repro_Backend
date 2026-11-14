<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('iguide_lab_workflows', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('request_hash', 64); $table->string('status', 24)->default('queued');
            $table->json('policy'); $table->timestamp('cancelled_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'request_id']);
        });
        Schema::create('iguide_lab_workflow_items', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('workflow_id')->constrained('iguide_lab_workflows')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases'); $table->unsignedSmallInteger('position');
            $table->string('preset', 40); $table->string('style', 40)->nullable(); $table->text('instructions');
            $table->json('source'); $table->string('status', 24)->default('queued'); $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->json('data'); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['workflow_id', 'position']);
        });
        Schema::create('iguide_lab_workflow_steps', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('item_id')->constrained('iguide_lab_workflow_items')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->string('step_key', 80); $table->string('kind', 24); $table->uuid('request_id');
            $table->string('status', 24)->default('reserved'); $table->uuid('child_id')->nullable();
            $table->json('input'); $table->string('input_sha256', 64); $table->json('data')->nullable(); $table->timestamps();
            $table->unique(['item_id', 'step_key']); $table->unique(['project_id', 'request_id']);
        });
        Schema::create('iguide_lab_workflow_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('step_id')->unique()->constrained('iguide_lab_workflow_steps')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('request_hash', 64); $table->string('status', 24)->default('queued');
            $table->json('data'); $table->json('result')->nullable(); $table->json('provider_attempts')->nullable();
            $table->string('provider_request_id')->nullable(); $table->json('usage')->nullable(); $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'request_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('iguide_lab_workflow_evaluations'); Schema::dropIfExists('iguide_lab_workflow_steps');
        Schema::dropIfExists('iguide_lab_workflow_items'); Schema::dropIfExists('iguide_lab_workflows');
    }
};
