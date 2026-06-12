<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            if (!Schema::hasColumn('shoots', 'featured_homepage_title')) {
                $table->string('featured_homepage_title')->nullable()->after('is_featured');
            }
            if (!Schema::hasColumn('shoots', 'featured_homepage_location')) {
                $table->string('featured_homepage_location')->nullable()->after('featured_homepage_title');
            }
            if (!Schema::hasColumn('shoots', 'featured_homepage_subtitle')) {
                $table->string('featured_homepage_subtitle')->nullable()->after('featured_homepage_location');
            }
            if (!Schema::hasColumn('shoots', 'featured_homepage_cta_label')) {
                $table->string('featured_homepage_cta_label')->nullable()->after('featured_homepage_subtitle');
            }
            if (!Schema::hasColumn('shoots', 'featured_homepage_cta_href')) {
                $table->string('featured_homepage_cta_href')->nullable()->after('featured_homepage_cta_label');
            }
        });

        if (!Schema::hasTable('featured_shoot_images')) {
            Schema::create('featured_shoot_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shoot_id')->constrained('shoots')->cascadeOnDelete();
                $table->foreignId('shoot_file_id')->constrained('shoot_files')->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(1);
                $table->string('alt_text')->nullable();
                $table->string('focal_point', 32)->default('50% 50%');
                $table->string('variant_640_path')->nullable();
                $table->string('variant_1280_path')->nullable();
                $table->string('variant_1920_path')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->timestamps();

                $table->unique(['shoot_id', 'shoot_file_id'], 'featured_shoot_images_unique_file');
                $table->index(['shoot_id', 'sort_order'], 'featured_shoot_images_sort_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_shoot_images');

        Schema::table('shoots', function (Blueprint $table) {
            foreach ([
                'featured_homepage_title',
                'featured_homepage_location',
                'featured_homepage_subtitle',
                'featured_homepage_cta_label',
                'featured_homepage_cta_href',
            ] as $column) {
                if (Schema::hasColumn('shoots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
