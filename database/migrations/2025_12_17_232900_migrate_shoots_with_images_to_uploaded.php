<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Move scheduled shoots that have preview images to 'uploaded' status
     * since they already have photos uploaded.
     */
    public function up(): void
    {
        // Only update shoots that have actual files in shoot_files table
        $shootIdsWithFiles = DB::table('shoot_files')
            ->select('shoot_id')
            ->distinct()
            ->pluck('shoot_id')
            ->toArray();

        if (!empty($shootIdsWithFiles)) {
            DB::table('shoots')
                ->where('status', 'scheduled')
                ->whereIn('id', $shootIdsWithFiles)
                ->update([
                    'status' => 'uploaded',
                    'workflow_status' => 'uploaded'
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-way migration - don't revert
    }
};
