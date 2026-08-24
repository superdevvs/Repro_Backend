<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a catalogue service's raws are captured as multi-exposure bracket stacks.
 *
 * This flag says only *whether* a deliverable brackets, never how many exposures.
 * The count is execution state and lives on the shoot-service assignment, because
 * two services on one shoot can be shot by different photographers at 5x and 3x.
 *
 * It replaces a runtime heuristic ("Photography category with a positive
 * photo_count") that wrongly marked drone photography as bracketed. After this
 * migration, runtime code reads the boolean and never inspects service names.
 *
 * This migration is deliberately schema-only. An earlier version also carried a
 * backfill list, but its expected service identities were local-development
 * assumptions ("HDR Photography" in category "Photography") that matched nothing in
 * the real catalogue ("25 HDR Photos" in category "Photos"). Every identity check
 * failed, so the flag stayed false on every row and derived raw expectations shipped
 * wrong. Catalogue capability data now lives in exactly one authoritative place,
 * 2026_08_24_000002_backfill_service_upload_capabilities, keyed to verified
 * production identities.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        if (! Schema::hasColumn('services', 'uses_hdr_brackets')) {
            Schema::table('services', function (Blueprint $table) {
                $table->boolean('uses_hdr_brackets')->default(false)->after('photo_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services') && Schema::hasColumn('services', 'uses_hdr_brackets')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropColumn('uses_hdr_brackets');
            });
        }
    }
};
