<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('iguide_lab_declutter_removal_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('step_id')->unique()->constrained('iguide_lab_workflow_steps')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('request_hash', 64); $table->string('status', 24)->default('queued');
            $table->json('data'); $table->json('result')->nullable(); $table->string('result_sha256', 64)->nullable();
            $table->json('provider_attempts')->nullable(); $table->string('provider_request_id')->nullable();
            $table->json('usage')->nullable(); $table->string('error_code', 100)->nullable();
            $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'request_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('iguide_lab_declutter_removal_plans'); }
};
