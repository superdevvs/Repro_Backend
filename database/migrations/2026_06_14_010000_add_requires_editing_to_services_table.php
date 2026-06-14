<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categories whose services are produced by external/automated pipelines
     * (drone pilots, CubiCasa/Matterport, virtual staging vendors) rather than by
     * the in-house photo/video editing lanes. Services in these categories are
     * NON-EDITING extras and must stay hidden from editors (QA #13).
     */
    private const NON_EDITING_CATEGORIES = [
        'Drone',
        'Floor Plans',
        '360/3D Tours',
        'Virtual Staging',
    ];

    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (!Schema::hasColumn('services', 'requires_editing')) {
                // Default true preserves current behaviour: existing services keep
                // flowing to editors unless explicitly flagged as a non-editing extra.
                $table->boolean('requires_editing')->default(true)->after('photographer_required');
            }
        });

        if (!Schema::hasColumn('services', 'requires_editing')) {
            return;
        }

        // Backfill: flag known non-editing extra categories so they never appear in
        // the editor task list, including on legacy shoots assigned at the shoot level.
        try {
            $categoryIds = DB::table('categories')
                ->whereIn('name', self::NON_EDITING_CATEGORIES)
                ->pluck('id');

            if ($categoryIds->isNotEmpty()) {
                DB::table('services')
                    ->whereIn('category_id', $categoryIds->all())
                    ->update(['requires_editing' => false]);
            }
        } catch (\Throwable $exception) {
            // Categories table may not exist yet in some bootstrap orders; the column
            // default (true) keeps the system safe until the seeder runs.
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'requires_editing')) {
                $table->dropColumn('requires_editing');
            }
        });
    }
};
