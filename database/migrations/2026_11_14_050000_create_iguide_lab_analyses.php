<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iguide_lab_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('iguide_lab_cases')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('request_hash', 64); $table->string('source_sha256', 64);
            $table->json('settings'); $table->json('data')->nullable(); $table->json('proposal')->nullable(); $table->string('proposal_sha256', 64)->nullable();
            $table->string('status', 30)->default('queued'); $table->string('provider_request_id')->nullable();
            $table->json('provider_attempts')->nullable(); $table->json('usage')->nullable(); $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable(); $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'request_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('iguide_lab_analyses'); }
};
