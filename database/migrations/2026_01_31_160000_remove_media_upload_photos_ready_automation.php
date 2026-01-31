<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Remove MEDIA_UPLOAD_COMPLETE automation that sends Photos Ready email.
     * Photos Ready should only be sent when shoot is finalized (SHOOT_COMPLETED).
     */
    public function up(): void
    {
        // Delete any automation rules with MEDIA_UPLOAD_COMPLETE trigger
        // that are linked to shoot-ready template (Photos Ready)
        DB::table('automation_rules')
            ->where('trigger_type', 'MEDIA_UPLOAD_COMPLETE')
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Can re-seed if needed
    }
};
