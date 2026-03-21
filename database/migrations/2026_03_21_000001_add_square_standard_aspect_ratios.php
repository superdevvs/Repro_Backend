<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SQLite stores enums as TEXT with a CHECK constraint.
     * We rebuild the table without the CHECK constraint so all aspect ratios are accepted.
     * Validation remains at the application level (controller + model).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=off');

            // Drop old indexes so they don't collide during rebuild
            DB::statement('DROP INDEX IF EXISTS ai_video_generation_jobs_shoot_id_status_index');
            DB::statement('DROP INDEX IF EXISTS ai_video_generation_jobs_user_id_index');
            DB::statement('DROP INDEX IF EXISTS ai_video_generation_jobs_status_index');

            // Rename original table
            DB::statement('ALTER TABLE ai_video_generation_jobs RENAME TO _aivgj_old');

            // Recreate without enum CHECK constraint — aspect_ratio and status are plain TEXT
            DB::statement('
                CREATE TABLE ai_video_generation_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    shoot_id INTEGER NOT NULL REFERENCES shoots(id) ON DELETE CASCADE,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    preset_id INTEGER NOT NULL REFERENCES ai_video_presets(id) ON DELETE CASCADE,
                    start_frame_file_id INTEGER REFERENCES shoot_files(id) ON DELETE SET NULL,
                    end_frame_file_id INTEGER REFERENCES shoot_files(id) ON DELETE SET NULL,
                    preset_prompt TEXT NOT NULL,
                    aspect_ratio VARCHAR(255) NOT NULL DEFAULT \'horizontal\',
                    status VARCHAR(255) NOT NULL DEFAULT \'pending\',
                    higgsfield_video_request_id VARCHAR(255),
                    original_start_frame_url VARCHAR(255) NOT NULL,
                    original_end_frame_url VARCHAR(255),
                    selected_start_frame_url VARCHAR(255),
                    selected_end_frame_url VARCHAR(255),
                    video_url VARCHAR(255),
                    video_thumbnail_url VARCHAR(255),
                    error_message TEXT,
                    retry_count INTEGER NOT NULL DEFAULT 0,
                    started_at DATETIME,
                    completed_at DATETIME,
                    created_at DATETIME,
                    updated_at DATETIME
                )
            ');

            // Restore indexes
            DB::statement('CREATE INDEX ai_video_generation_jobs_shoot_id_status_index ON ai_video_generation_jobs (shoot_id, status)');
            DB::statement('CREATE INDEX ai_video_generation_jobs_user_id_index ON ai_video_generation_jobs (user_id)');
            DB::statement('CREATE INDEX ai_video_generation_jobs_status_index ON ai_video_generation_jobs (status)');

            // Copy data
            DB::statement('INSERT INTO ai_video_generation_jobs SELECT * FROM _aivgj_old');

            // Drop backup
            DB::statement('DROP TABLE _aivgj_old');

            DB::statement('PRAGMA foreign_keys=on');
        } else {
            DB::statement("ALTER TABLE ai_video_generation_jobs MODIFY COLUMN aspect_ratio VARCHAR(20) NOT NULL DEFAULT 'horizontal'");
        }
    }

    public function down(): void
    {
        // No-op
    }
};
