<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            $table->unsignedInteger('content_version')->default(1);
            $table->unsignedBigInteger('source_file_id')->nullable()->index();
        });
        Schema::create('shoot_editing_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('scope', 24);
            $table->string('destination', 16);
            $table->string('workflow', 64);
            $table->text('instructions')->nullable();
            $table->char('input_hash', 64);
            $table->json('plan');
            $table->string('status', 24)->default('queued')->index();
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('shoot_editing_dispatch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispatch_id')->constrained('shoot_editing_dispatches')->cascadeOnDelete();
            $table->string('input_key', 96);
            $table->string('workflow');
            $table->json('sources');
            $table->unsignedBigInteger('shoot_service_id')->nullable();
            $table->string('lane', 16);
            $table->string('destination', 16);
            $table->foreignId('editor_id')->nullable()->constrained('users');
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('status', 24)->default('queued');
            $table->uuid('primary_version_id')->nullable();
            $table->text('return_url')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['dispatch_id', 'input_key']);
            $table->index(['editor_id', 'status']);
        });
        Schema::create('shoot_file_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            // Keep the record and bytes if the source is deleted while editing.
            $table->unsignedBigInteger('source_file_id')->nullable()->index();
            $table->unsignedBigInteger('target_file_id')->nullable()->index();
            $table->unsignedBigInteger('published_file_id')->nullable()->index();
            $table->unsignedInteger('expected_version');
            $table->unsignedInteger('version')->nullable();
            $table->string('request_key', 160)->unique();
            $table->char('sha256', 64)->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->json('snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('dispatch_item_id')->nullable()->index();
            $table->string('error_code', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['published_file_id', 'version']);
        });
        Schema::table('studio_workspaces', function (Blueprint $table) {
            $table->uuid('editing_dispatch_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('studio_workspaces', fn (Blueprint $table) => $table->dropColumn('editing_dispatch_id'));
        Schema::dropIfExists('shoot_file_versions');
        Schema::dropIfExists('shoot_editing_dispatch_items');
        Schema::dropIfExists('shoot_editing_dispatches');
        Schema::table('shoot_files', fn (Blueprint $table) => $table->dropColumn(['content_version', 'source_file_id']));
    }
};
