<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('request_id', 64)->nullable()->after('status');
            $table->foreignUuid('template_id')->nullable()->after('request_id')->constrained('templates')->nullOnDelete();
            $table->json('workflow_config')->nullable()->after('template_id');
            $table->json('brand_state')->nullable()->after('workflow_config');
            $table->unique(['created_by', 'request_id'], 'projects_creator_request_unique');
        });

        Schema::table('ai_listing_video_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('shoot_id')->nullable()->change();
            $table->json('source_media_refs')->nullable()->after('selected_file_ids');
            $table->json('workflow_config')->nullable()->after('source_media_refs');
            $table->json('brand_state')->nullable()->after('workflow_config');
        });

        Schema::table('ai_reel_jobs', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->nullable()->after('id')->constrained('projects')->nullOnDelete();
            $table->string('request_id', 64)->nullable()->after('project_id')->index();
            $table->unsignedBigInteger('shoot_id')->nullable()->change();
            $table->json('source_media_refs')->nullable()->after('selected_file_ids');
            $table->json('workflow_config')->nullable()->after('source_media_refs');
            $table->json('brand_state')->nullable()->after('workflow_config');
        });
    }

    public function down(): void
    {
        Schema::table('ai_reel_jobs', function (Blueprint $table): void {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['request_id']);
            $table->dropColumn(['project_id', 'request_id', 'source_media_refs', 'workflow_config', 'brand_state']);
            $table->unsignedBigInteger('shoot_id')->nullable(false)->change();
        });

        Schema::table('ai_listing_video_jobs', function (Blueprint $table): void {
            $table->dropColumn(['source_media_refs', 'workflow_config', 'brand_state']);
            $table->unsignedBigInteger('shoot_id')->nullable(false)->change();
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique('projects_creator_request_unique');
            $table->dropForeign(['template_id']);
            $table->dropColumn(['request_id', 'template_id', 'workflow_config', 'brand_state']);
        });
    }
};