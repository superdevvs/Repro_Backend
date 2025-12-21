<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Convert all 'completed' status values to 'uploaded' as we're removing 'completed' as a status.
     */
    public function up(): void
    {
        // Update status column
        DB::table('shoots')
            ->where('status', 'completed')
            ->update(['status' => 'uploaded']);

        // Update workflow_status column
        DB::table('shoots')
            ->where('workflow_status', 'completed')
            ->update(['workflow_status' => 'uploaded']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This is a one-way migration - we don't want to revert to 'completed'
        // as it's being removed from the system
    }
};
