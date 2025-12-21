<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Move legacy uploaded-style statuses into the unified "completed" status.
     */
    public function up(): void
    {
        $legacyStatuses = [
            'uploaded',
            'raw_uploaded',
            'photos_uploaded',
            'raw_upload_pending',
            'raw_issue',
        ];

        DB::table('shoots')
            ->whereIn('status', $legacyStatuses)
            ->update([
                'status' => 'completed',
                'workflow_status' => 'completed',
                'updated_at' => now(),
            ]);
    }

    /**
     * No safe automatic rollback for mass status migration.
     */
    public function down(): void
    {
        // Intentionally left empty.
    }
};
