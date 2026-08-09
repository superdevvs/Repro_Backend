<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the delivery media order so every downstream consumer agrees.
 *
 * `shoot_files.sort_order` is the live, editable arrangement an admin builds in
 * the media tab. That alone is not enough for delivery: the ZIP archive, the
 * Dropbox cache hand-off, the Bright MLS manifest and the client email are all
 * produced by independent async jobs, so a reorder landing mid-finalize would
 * otherwise let them disagree about the sequence.
 *
 *  - media_order_version           bumped on every saved manual reorder. Feeds the
 *                                  archive cache signature so a reorder always
 *                                  invalidates a previously generated ZIP.
 *  - delivery_media_order          snapshot of ordered shoot_file ids taken when
 *                                  the shoot is finalized. The single source of
 *                                  truth every delivery job reads.
 *  - delivery_media_order_version  media_order_version at snapshot time, so a
 *                                  later reorder is detectable.
 *  - delivery_media_order_at       when the snapshot was taken (audit/debug).
 *
 * All nullable / defaulted: shoots finalized before this migration simply have no
 * snapshot and fall back to live sort_order ordering.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shoots')) {
            return;
        }

        Schema::table('shoots', function (Blueprint $table) {
            if (! Schema::hasColumn('shoots', 'media_order_version')) {
                $table->unsignedInteger('media_order_version')->default(0);
            }

            if (! Schema::hasColumn('shoots', 'delivery_media_order')) {
                $table->json('delivery_media_order')->nullable();
            }

            if (! Schema::hasColumn('shoots', 'delivery_media_order_version')) {
                $table->unsignedInteger('delivery_media_order_version')->nullable();
            }

            if (! Schema::hasColumn('shoots', 'delivery_media_order_at')) {
                $table->timestamp('delivery_media_order_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shoots')) {
            return;
        }

        Schema::table('shoots', function (Blueprint $table) {
            foreach ([
                'media_order_version',
                'delivery_media_order',
                'delivery_media_order_version',
                'delivery_media_order_at',
            ] as $column) {
                if (Schema::hasColumn('shoots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
