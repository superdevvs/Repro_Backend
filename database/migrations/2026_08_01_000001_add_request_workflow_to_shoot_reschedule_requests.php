<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the reschedule request a real request instead of a receipt.
 *
 * A1.docx item 4: the client-facing buttons read "Request to reschedule", but
 * `ShootRescheduleRequestController::store` created the row already `approved`
 * and applied the new date immediately. The table already separates the
 * requested values (`requested_date` / `requested_time`) from the shoot's
 * confirmed ones and already has the three statuses, so only two things were
 * missing:
 *
 *  - `applied_at`, so approval can be made idempotent. Without it a second
 *    approval re-applies the change and re-sends notifications.
 *  - `original_time`, so the confirmed time is snapshotted alongside
 *    `original_date` and a rejected request leaves a complete audit trail of
 *    what was being changed away from.
 *
 * `review_notes` records why a request was rejected so the requester is told
 * something more useful than "rejected".
 *
 * Purely additive: no existing column changes type, and existing rows keep
 * working because every new column is nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shoot_reschedule_requests')) {
            return;
        }

        Schema::table('shoot_reschedule_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('shoot_reschedule_requests', 'original_time')) {
                $table->string('original_time')->nullable()->after('original_date');
            }

            if (! Schema::hasColumn('shoot_reschedule_requests', 'applied_at')) {
                $table->timestamp('applied_at')->nullable()->after('reviewed_at');
            }

            if (! Schema::hasColumn('shoot_reschedule_requests', 'review_notes')) {
                $table->text('review_notes')->nullable()->after('reason');
            }
        });

        $this->backfillAppliedAt();
    }

    public function down(): void
    {
        if (! Schema::hasTable('shoot_reschedule_requests')) {
            return;
        }

        Schema::table('shoot_reschedule_requests', function (Blueprint $table) {
            foreach (['original_time', 'applied_at', 'review_notes'] as $column) {
                if (Schema::hasColumn('shoot_reschedule_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Treat historical approved rows as already applied.
     *
     * Every pre-existing request was created `approved` and applied on the spot,
     * so without this backfill the new idempotency guard would consider them
     * unapplied and re-apply an old date the first time someone re-approved one.
     */
    private function backfillAppliedAt(): void
    {
        if (! Schema::hasColumn('shoot_reschedule_requests', 'applied_at')) {
            return;
        }

        DB::table('shoot_reschedule_requests')
            ->where('status', 'approved')
            ->whereNull('applied_at')
            ->update([
                'applied_at' => DB::raw('COALESCE(reviewed_at, updated_at, created_at)'),
            ]);
    }
};
