<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which upload intake lane, if any, a catalogue service can receive files through.
 *
 * A commercial catalogue entry is not automatically an upload target. Fees, travel,
 * digital enhancements and dedicated 3D-tour products are all legitimately bookable
 * and legitimately assignable to a photographer, yet none of them accept camera
 * output through the Raw Upload selector. Before this column the selector offered
 * every booked row, so a shoot booking a floor plan and virtual staging invented raw
 * expectations for both and let files be attached to a Matterport tour.
 *
 * The column is a capability, not a category and not a name pattern:
 *
 *   photo        capture arrives in the photo raw lane
 *   video        capture arrives in the video raw lane
 *   photo_video  one execution row legitimately receives both lanes
 *   none         never selectable as an upload target
 *
 * `none` is the default precisely because unknown capability must mean "not
 * selectable" rather than "probably photo". A new catalogue entry has to be opted
 * into a lane deliberately.
 *
 * This is independent of `uses_hdr_brackets`. Intake type decides which lane may
 * select the service; the bracket flag decides whether that service's photo lane
 * stacks exposures. Drone is the clearest case of the two being orthogonal: it is
 * genuine photo intake that does not bracket.
 *
 * Deliberately additive and idempotent. Production already carries
 * `services.uses_hdr_brackets`, `users.default_bracket_mode` and
 * `shoot_service.bracket_mode` from an earlier release whose application code was
 * rolled back while the columns were intentionally preserved, so every schema step
 * here has to tolerate partially-applied state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        if (Schema::hasColumn('services', 'upload_intake_type')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $column = $table->string('upload_intake_type', 20)->default('none');

            // Keep the capability columns adjacent when the sibling flag exists;
            // fresh databases that have not run the bracket migration still work.
            if (Schema::hasColumn('services', 'uses_hdr_brackets')) {
                $column->after('uses_hdr_brackets');
            }

            $table->index('upload_intake_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'upload_intake_type')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['upload_intake_type']);
            $table->dropColumn('upload_intake_type');
        });
    }
};
