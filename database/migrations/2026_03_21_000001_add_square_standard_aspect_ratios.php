<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SQLite doesn't support ALTER COLUMN or ENUM types.
     * We recreate the table with the updated column definition.
     */
    public function up(): void
    {
        // For SQLite: drop the CHECK constraint by recreating the column
        // Laravel's change() with DBAL handles this, but simplest approach
        // is to use Schema::table with a raw approach that works on SQLite

        // SQLite stores enums as text with CHECK constraints.
        // We need to rebuild the table to change the constraint.
        if (DB::getDriverName() === 'sqlite') {
            // Disable foreign keys temporarily
            DB::statement('PRAGMA foreign_keys=off');

            // Rename existing table
            DB::statement('ALTER TABLE ai_video_generation_jobs RENAME TO ai_video_generation_jobs_backup');

            // Get the create SQL and modify the enum check
            Schema::create('ai_video_generation_jobs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shoot_id')->constrained('shoots')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('preset_id')->constrained('ai_video_presets')->onDelete('cascade');
                $table->foreignId('start_frame_file_id')->nullable()->constrained('shoot_files')->onDelete('set null');
                $table->foreignId('end_frame_file_id')->nullable()->constrained('shoot_files')->onDelete('set null');
                $table->text('preset_prompt');
                $table->string('aspect_ratio')->default('horizontal'); // No enum constraint, validated at app level
                $table->string('status')->default('pending');
                $table->string('higgsfield_video_request_id')->nullable();
                $table->string('original_start_frame_url');
                $table->string('original_end_frame_url')->nullable();
                $table->string('selected_start_frame_url')->nullable();
                $table->string('selected_end_frame_url')->nullable();
                $table->string('video_url')->nullable();
                $table->string('video_thumbnail_url')->nullable();
                $table->text('error_message')->nullable();
                $table->integer('retry_count')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['shoot_id', 'status']);
                $table->index('user_id');
                $table->index('status');
            });

            // Copy data from backup
            DB::statement('INSERT INTO ai_video_generation_jobs SELECT * FROM ai_video_generation_jobs_backup');

            // Drop backup
            DB::statement('DROP TABLE ai_video_generation_jobs_backup');

            // Re-enable foreign keys
            DB::statement('PRAGMA foreign_keys=on');
        } else {
            // MySQL/PostgreSQL: simple ALTER
            DB::statement("ALTER TABLE ai_video_generation_jobs MODIFY COLUMN aspect_ratio VARCHAR(20) NOT NULL DEFAULT 'horizontal'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: the column now accepts any string, which is a superset
    }
};
