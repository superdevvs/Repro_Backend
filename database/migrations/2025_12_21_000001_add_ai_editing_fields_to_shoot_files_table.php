<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            if (!Schema::hasColumn('shoot_files', 'ai_editing_job_id')) {
                $table->foreignId('ai_editing_job_id')->nullable()->after('workflow_stage')
                      ->constrained('ai_editing_jobs')->onDelete('set null');
            }
            if (!Schema::hasColumn('shoot_files', 'is_ai_edited')) {
                $table->boolean('is_ai_edited')->default(false)->after('ai_editing_job_id');
            }
            if (!Schema::hasColumn('shoot_files', 'ai_editing_metadata')) {
                $table->json('ai_editing_metadata')->nullable()->after('is_ai_edited');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoot_files', function (Blueprint $table) {
            $table->dropForeign(['ai_editing_job_id']);
            $table->dropColumn(['ai_editing_job_id', 'is_ai_edited', 'ai_editing_metadata']);
        });
    }
};

