<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable latitude/longitude to shoots.
 *
 * Supports Option B photographer service-radius enforcement (config:
 * availability.radius_enforcement): the assignment-path radius gate
 * (AssignServicePhotographerAction) needs a resolvable shoot coordinate to compute the
 * photographer-to-shoot distance. Columns are nullable and default null, so existing rows and
 * historical behavior are unaffected when radius enforcement is OFF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('zip');
            }
            if (!Schema::hasColumn('shoots', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (Schema::hasColumn('shoots', 'longitude')) {
                $table->dropColumn('longitude');
            }
            if (Schema::hasColumn('shoots', 'latitude')) {
                $table->dropColumn('latitude');
            }
        });
    }
};
