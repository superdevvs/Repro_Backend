<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable external-booking columns to shoots.
 *
 * Supports the external-booking-shoot-sync fix: external bookings now carry preferred and
 * alternate scheduling plus one or more requested photographers. These columns preserve the
 * raw external payload (provenance), the normalized requested photographers, the generated
 * mapping warnings, the alternate schedule, and the auto-mapping status so ambiguous bookings
 * can be reviewed.
 *
 * All columns are nullable for full backward compatibility (Requirements 2.15, 2.16, 3.9):
 * existing rows and legacy external payloads are unaffected. Each add is wrapped in a
 * Schema::hasColumn guard so the migration is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'alternate_scheduled_date')) {
                $table->date('alternate_scheduled_date')->nullable();
            }
            if (!Schema::hasColumn('shoots', 'alternate_time')) {
                $table->string('alternate_time')->nullable();
            }
            if (!Schema::hasColumn('shoots', 'alternate_scheduled_at')) {
                $table->dateTime('alternate_scheduled_at')->nullable();
            }
            if (!Schema::hasColumn('shoots', 'requested_photographers')) {
                $table->json('requested_photographers')->nullable();
            }
            if (!Schema::hasColumn('shoots', 'external_booking_payload')) {
                $table->json('external_booking_payload')->nullable();
            }
            if (!Schema::hasColumn('shoots', 'external_booking_warnings')) {
                $table->json('external_booking_warnings')->nullable();
            }
            if (!Schema::hasColumn('shoots', 'external_booking_mapping_status')) {
                $table->string('external_booking_mapping_status')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (Schema::hasColumn('shoots', 'external_booking_mapping_status')) {
                $table->dropColumn('external_booking_mapping_status');
            }
            if (Schema::hasColumn('shoots', 'external_booking_warnings')) {
                $table->dropColumn('external_booking_warnings');
            }
            if (Schema::hasColumn('shoots', 'external_booking_payload')) {
                $table->dropColumn('external_booking_payload');
            }
            if (Schema::hasColumn('shoots', 'requested_photographers')) {
                $table->dropColumn('requested_photographers');
            }
            if (Schema::hasColumn('shoots', 'alternate_scheduled_at')) {
                $table->dropColumn('alternate_scheduled_at');
            }
            if (Schema::hasColumn('shoots', 'alternate_time')) {
                $table->dropColumn('alternate_time');
            }
            if (Schema::hasColumn('shoots', 'alternate_scheduled_date')) {
                $table->dropColumn('alternate_scheduled_date');
            }
        });
    }
};
