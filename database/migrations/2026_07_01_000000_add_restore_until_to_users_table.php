<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // 14-day restore deadline for the account lifecycle:
            // Active -> Deleted (restorable until restore_until) -> Purged/Anonymized.
            if (!Schema::hasColumn('users', 'restore_until')) {
                $table->timestamp('restore_until')->nullable()->after('password_reset_required');
            }
        });

        // Backfill existing soft-deleted accounts onto the new lifecycle.
        if (Schema::hasColumn('users', 'restore_until') && Schema::hasColumn('users', 'deleted_at')) {
            // Within window or not: restore_until = deleted_at + 14 days.
            DB::table('users')
                ->whereNotNull('deleted_at')
                ->whereNull('restore_until')
                ->update([
                    'restore_until' => DB::raw("datetime(deleted_at, '+14 days')"),
                ]);

            // Fallback: soft-deleted rows with no deleted_at timestamp (data anomaly) —
            // give them a fresh 14-day window from now so they are never purged without
            // a chance to be reviewed/restored. Documented fallback.
            DB::table('users')
                ->whereNull('deleted_at')
                ->where('account_status', 'deleted')
                ->whereNull('restore_until')
                ->update([
                    'restore_until' => DB::raw("datetime('now', '+14 days')"),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'restore_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('restore_until');
            });
        }
    }
};
