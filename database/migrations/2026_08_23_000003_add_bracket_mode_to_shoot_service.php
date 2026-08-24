<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bracket size actually used for one service on one shoot.
 *
 * This is the execution record, not a preference and not a catalogue property. A
 * shoot_service row already carries photographer_id, scheduled_at and
 * workflow_status, so it is the photographer's execution assignment for that
 * service, which makes it the right grain: one shoot can hold Exterior at 5x by
 * photographer A and Interior at 3x by photographer B.
 *
 * NULL is meaningful. For a service that does not bracket it stays NULL forever;
 * for one that does, NULL means "not yet pinned" and resolves through the
 * photographer's preference, then the legacy shoot value, then 5.
 *
 * Backfill pins the current divisor ONLY where it could already be observable:
 * bracket-enabled services that already hold raw files. Anything already stacked
 * keeps the size it was stacked at. Rows with no raws stay NULL so they can pick up
 * the assigned photographer's preference instead of being frozen at a legacy value,
 * and non-bracket work is never stamped with a number it has no use for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shoot_service')) {
            return;
        }

        if (! Schema::hasColumn('shoot_service', 'bracket_mode')) {
            Schema::table('shoot_service', function (Blueprint $table) {
                $table->unsignedTinyInteger('bracket_mode')->nullable()->after('photographer_id');
            });
        }

        $canBackfill = Schema::hasTable('services')
            && Schema::hasColumn('services', 'uses_hdr_brackets')
            && Schema::hasTable('shoot_files')
            && Schema::hasColumn('shoot_files', 'shoot_service_id')
            && Schema::hasTable('shoots')
            && Schema::hasColumn('shoots', 'bracket_mode');

        if (! $canBackfill) {
            return;
        }

        DB::table('shoot_service')
            ->whereNull('shoot_service.bracket_mode')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('services')
                ->whereColumn('services.id', 'shoot_service.service_id')
                ->where('services.uses_hdr_brackets', true))
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('shoot_files')
                ->whereColumn('shoot_files.shoot_service_id', 'shoot_service.id')
                ->where('shoot_files.media_type', 'raw'))
            ->orderBy('id')
            ->select('id', 'shoot_id')
            ->chunkById(200, function ($items) {
                foreach ($items as $item) {
                    $legacy = DB::table('shoots')->where('id', $item->shoot_id)->value('bracket_mode');
                    $pinned = (int) ($legacy ?? 0);

                    DB::table('shoot_service')
                        ->where('id', $item->id)
                        ->update(['bracket_mode' => $pinned > 1 ? $pinned : 5]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('shoot_service') && Schema::hasColumn('shoot_service', 'bracket_mode')) {
            Schema::table('shoot_service', function (Blueprint $table) {
                $table->dropColumn('bracket_mode');
            });
        }
    }
};
