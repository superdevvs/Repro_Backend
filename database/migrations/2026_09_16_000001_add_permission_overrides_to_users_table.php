<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user tri-state overrides layered on top of the role map:
            // {"allow": [permissionId, ...], "deny": [permissionId, ...]}
            $table->json('permission_overrides')->nullable()->after('secondary_roles');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permission_overrides');
        });
    }
};
