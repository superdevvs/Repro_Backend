<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->string('package_name')->nullable();
            $table->json('package_services_included')->nullable();
            $table->unsignedInteger('expected_final_count')->nullable();
            $table->unsignedInteger('bracket_mode')->nullable();
            $table->unsignedInteger('expected_raw_count')->nullable();
            $table->unsignedInteger('raw_photo_count')->default(0);
            $table->unsignedInteger('edited_photo_count')->default(0);
            $table->unsignedInteger('raw_missing_count')->default(0);
            $table->unsignedInteger('edited_missing_count')->default(0);
            $table->boolean('missing_raw')->default(false);
            $table->boolean('missing_final')->default(false);
            $table->string('hero_image')->nullable();
            $table->string('weather_summary')->nullable();
            $table->string('weather_temperature')->nullable();
        });

        Schema::table('shoot_files', function (Blueprint $table) {
            $table->boolean('is_cover')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->unsignedSmallInteger('bracket_group')->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->text('flag_reason')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn([
                'package_name',
                'package_services_included',
                'expected_final_count',
                'bracket_mode',
                'expected_raw_count',
                'raw_photo_count',
                'edited_photo_count',
                'raw_missing_count',
                'edited_missing_count',
                'missing_raw',
                'missing_final',
                'hero_image',
                'weather_summary',
                'weather_temperature',
            ]);
        });

        Schema::table('shoot_files', function (Blueprint $table) {
            $table->dropColumn([
                'is_cover',
                'is_favorite',
                'bracket_group',
                'sequence',
                'flag_reason',
                'metadata',
            ]);
        });
    }
};

