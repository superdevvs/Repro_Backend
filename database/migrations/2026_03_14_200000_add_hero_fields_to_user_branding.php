<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_branding', function (Blueprint $table) {
            $table->string('hero_headline')->nullable()->after('about');
            $table->text('hero_subtitle')->nullable()->after('hero_headline');
            $table->string('hero_image')->nullable()->after('hero_subtitle');
        });
    }

    public function down(): void
    {
        Schema::table('user_branding', function (Blueprint $table) {
            $table->dropColumn(['hero_headline', 'hero_subtitle', 'hero_image']);
        });
    }
};
