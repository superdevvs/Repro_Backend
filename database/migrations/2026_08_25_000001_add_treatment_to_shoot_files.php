<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-file treatment request, held separately from `media_type`.
 *
 * Virtual staging, green grass and twilight/dusk are post-capture treatments asked
 * for on one individual frame. They are not a capture identity and not a service:
 * an Exterior HDR frame marked for virtual staging is still an Exterior HDR raw of
 * its booked service.
 *
 * They previously rode on `media_type`, which is a single scalar. Writing
 * `virtual_staging` there overwrote `raw`, and every raw-scoped predicate keys on
 * that value — bracket stacking (`AutoStackRawFilesAction`, the pre-batch raw
 * scope in `UploadShootFilesAction`), the restack safety guard in
 * `BracketModeResolver`, the Photos tab filter, and the MLS delivery whitelist.
 * A treated frame silently dropped out of all of them.
 *
 * A separate nullable column keeps `media_type` at `raw` so none of those
 * predicates change behaviour, while the treatment travels with the file.
 * `shoot_service_id` was never involved: it is written from its own request field
 * and stays the sole record of service ownership.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shoot_files', 'treatment')) {
            return;
        }

        Schema::table('shoot_files', function (Blueprint $table) {
            // Nullable: the overwhelming majority of frames request no treatment.
            // One value per file, matching the mutually exclusive toggle in raw
            // staging. Deliberately not a JSON set: that would be a tagging system,
            // and nothing in the product asks for two treatments on one frame yet.
            $table->string('treatment', 32)->nullable()->after('media_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shoot_files', 'treatment')) {
            return;
        }

        Schema::table('shoot_files', function (Blueprint $table) {
            $table->dropColumn('treatment');
        });
    }
};
