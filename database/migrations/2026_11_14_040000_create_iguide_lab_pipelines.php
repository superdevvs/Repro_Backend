<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iguide_lab_pipelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->string('status', 30)->default('queued');
            $table->foreignUuid('child_run_id')->nullable()->constrained('iguide_lab_runs');
            $table->foreignUuid('composite_id')->nullable()->constrained('iguide_lab_composites');
            $table->uuid('support_request_id')->unique();
            $table->string('support_status', 30)->default('pending');
            $table->string('support_provider_request_id')->nullable();
            $table->json('support_metadata')->nullable();
            $table->json('data');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'request_id']);
        });
        Schema::create('iguide_lab_display_metadata', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->unique();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('composite_id')->constrained('iguide_lab_composites')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('request_hash', 64);
            $table->string('label', 160); $table->text('generated_detail_description'); $table->text('reason');
            $table->timestamps(); $table->unique(['project_id', 'request_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('iguide_lab_display_metadata'); Schema::dropIfExists('iguide_lab_pipelines'); }
};
