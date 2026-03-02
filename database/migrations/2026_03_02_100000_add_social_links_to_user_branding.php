<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_branding', function (Blueprint $table) {
            $table->string('facebook_url', 255)->nullable()->after('custom_domain');
            $table->string('linkedin_url', 255)->nullable()->after('facebook_url');
            $table->string('instagram_url', 255)->nullable()->after('linkedin_url');
        });

        // Also add to tour_branding if it exists
        if (Schema::hasTable('tour_branding')) {
            Schema::table('tour_branding', function (Blueprint $table) {
                $table->string('facebook_url', 255)->nullable()->after('custom_domain');
                $table->string('linkedin_url', 255)->nullable()->after('facebook_url');
                $table->string('instagram_url', 255)->nullable()->after('linkedin_url');
            });
        }
    }

    public function down(): void
    {
        Schema::table('user_branding', function (Blueprint $table) {
            $table->dropColumn(['facebook_url', 'linkedin_url', 'instagram_url']);
        });

        if (Schema::hasTable('tour_branding')) {
            Schema::table('tour_branding', function (Blueprint $table) {
                $table->dropColumn(['facebook_url', 'linkedin_url', 'instagram_url']);
            });
        }
    }
};
