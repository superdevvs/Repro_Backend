<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('iguide_lab_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('request_hash', 64);
            $table->string('source_kind', 32); $table->text('private_input');
            $table->string('status', 32)->default('queued'); $table->text('error')->nullable();
            $table->json('audit')->nullable(); $table->json('sources')->nullable();
            $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'request_id']); $table->index(['project_id', 'status']);
        });
        Schema::create('iguide_lab_import_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('project_id')->constrained('iguide_lab_projects')->cascadeOnDelete();
            $table->uuid('request_id'); $table->uuid('import_request_id'); $table->string('request_hash', 64);
            $table->string('filename'); $table->unsignedBigInteger('size_bytes'); $table->string('sha256', 64); $table->string('metadata_sha256', 64)->nullable();
            $table->string('status', 32)->default('uploading'); $table->json('chunks'); $table->text('error')->nullable(); $table->uuid('import_id')->nullable();
            $table->timestamp('expires_at'); $table->timestamps(); $table->unique(['project_id', 'request_id']); $table->unique(['project_id', 'import_request_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('iguide_lab_import_uploads'); Schema::dropIfExists('iguide_lab_imports'); }
};
