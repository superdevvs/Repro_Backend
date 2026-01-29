<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Migrate all shoots with 'review' status:
     * - If admin_verified_at is set -> move to 'delivered'
     * - Otherwise -> move to 'editing'
     */
    public function up(): void
    {
        // Migrate review status shoots to delivered if admin_verified_at is set
        DB::table('shoots')
            ->where('workflow_status', 'review')
            ->whereNotNull('admin_verified_at')
            ->update([
                'workflow_status' => 'delivered',
                'status' => 'delivered',
                'updated_at' => now(),
            ]);

        // Migrate remaining review status shoots to editing
        DB::table('shoots')
            ->where('workflow_status', 'review')
            ->update([
                'workflow_status' => 'editing',
                'status' => 'editing',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     * Note: This cannot fully reverse as we don't know which shoots were originally in review.
     * We'll move delivered shoots that have editing_completed_at back to review as a best guess.
     */
    public function down(): void
    {
        // Move delivered shoots with editing_completed_at back to review (best guess)
        DB::table('shoots')
            ->where('workflow_status', 'delivered')
            ->whereNotNull('editing_completed_at')
            ->whereNotNull('submitted_for_review_at')
            ->update([
                'workflow_status' => 'review',
                'status' => 'review',
                'updated_at' => now(),
            ]);
    }
};
