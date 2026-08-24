<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photographer's preferred bracket size.
 *
 * Preference only. It seeds the execution value on a shoot-service when that
 * photographer is assigned, and is never read again afterwards: changing a
 * preference must not retroactively re-divide stacks on shoots already assigned.
 *
 * NULL means "no stated preference" and resolves to 5, so no backfill is needed
 * and every existing photographer keeps today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'default_bracket_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedTinyInteger('default_bracket_mode')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'default_bracket_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('default_bracket_mode');
            });
        }
    }
};
