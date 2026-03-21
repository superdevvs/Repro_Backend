<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The previous migration renamed ai_video_generation_jobs to _aivgj_old,
     * which caused SQLite to update the FK in ai_video_vertical_variants to
     * reference _aivgj_old instead of ai_video_generation_jobs.
     * This migration rebuilds the table with the correct FK reference.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return; // Only needed for SQLite
        }

        DB::statement('PRAGMA foreign_keys=off');

        // Drop old indexes
        DB::statement('DROP INDEX IF EXISTS ai_video_vertical_variants_video_generation_job_id_frame_type_index');

        // Rename broken table
        DB::statement('ALTER TABLE ai_video_vertical_variants RENAME TO _aivv_old');

        // Recreate with correct FK
        DB::statement('
            CREATE TABLE ai_video_vertical_variants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                video_generation_job_id INTEGER NOT NULL REFERENCES ai_video_generation_jobs(id) ON DELETE CASCADE,
                frame_type VARCHAR(255) NOT NULL DEFAULT \'start\',
                variant_index INTEGER NOT NULL DEFAULT 0,
                higgsfield_request_id VARCHAR(255) NOT NULL,
                status VARCHAR(255) NOT NULL DEFAULT \'pending\',
                image_url VARCHAR(255),
                is_selected INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME
            )
        ');

        // Restore index
        DB::statement('CREATE INDEX ai_video_vertical_variants_video_generation_job_id_frame_type_index ON ai_video_vertical_variants (video_generation_job_id, frame_type)');

        // Copy existing data if any
        DB::statement('INSERT INTO ai_video_vertical_variants SELECT * FROM _aivv_old');

        // Drop backup
        DB::statement('DROP TABLE _aivv_old');

        DB::statement('PRAGMA foreign_keys=on');
    }

    public function down(): void
    {
        // No-op
    }
};
