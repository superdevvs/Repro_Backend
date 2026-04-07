<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_branding', 'show_map')) {
            return;
        }

        Schema::table('user_branding', function (Blueprint $table) {
            $table->boolean('show_map')->default(false)->after('instagram_url');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('user_branding', 'show_map')) {
            return;
        }

        Schema::table('user_branding', function (Blueprint $table) {
            $table->dropColumn('show_map');
        });
    }
};
