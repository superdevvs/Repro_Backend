<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The existing user_activity_logs table (backing UserActivityLog) is reused as the
     * Audit_Log. It already records user_id, actor_user_id, event_type, title, description,
     * metadata, and occurred_at. The only missing fields needed by AuditLogService are a
     * polymorphic target so non-user targets (e.g. Shoot) can be audited, and a nullable
     * title so generic audit entries do not require a human-facing title.
     */
    public function up(): void
    {
        if (!Schema::hasTable('user_activity_logs')) {
            return;
        }

        Schema::table('user_activity_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('user_activity_logs', 'target_type')) {
                $table->string('target_type')->nullable()->after('actor_user_id');
            }
            if (!Schema::hasColumn('user_activity_logs', 'target_id')) {
                $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
                $table->index(['target_type', 'target_id'], 'user_activity_logs_target_index');
            }
        });

        if (Schema::hasColumn('user_activity_logs', 'title')) {
            Schema::table('user_activity_logs', function (Blueprint $table) {
                $table->string('title')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_activity_logs')) {
            return;
        }

        Schema::table('user_activity_logs', function (Blueprint $table) {
            if (Schema::hasColumn('user_activity_logs', 'target_id')) {
                try {
                    $table->dropIndex('user_activity_logs_target_index');
                } catch (\Throwable) {
                    // ignore if missing
                }
            }
            foreach (['target_id', 'target_type'] as $column) {
                if (Schema::hasColumn('user_activity_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
