<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DIGITAL_ENHANCEMENT_CATEGORY = 'Digital Enhancements';

    /**
     * Catalogue services that are digital extras, not on-site capture.
     * IDs are preserved; only category (and Virtual Staging editing) changes.
     *
     * @var array<int, array{name: string, from: string, requires_editing: bool}>
     */
    private const SERVICES = [
        18 => ['name' => 'Virtual Staging (per image)', 'from' => 'Virtual Staging', 'requires_editing' => true],
        30 => ['name' => 'Blue Sky Replacement', 'from' => 'Addons', 'requires_editing' => true],
        48 => ['name' => 'Boundary Lines - Photos', 'from' => 'Addons', 'requires_editing' => true],
        49 => ['name' => 'Boundary Lines - Video', 'from' => 'Addons', 'requires_editing' => true],
        50 => ['name' => 'Boundary Lines - Photos & Video', 'from' => 'Addons', 'requires_editing' => true],
        51 => ['name' => 'Green Grass Enhancement', 'from' => 'Addons', 'requires_editing' => true],
        52 => ['name' => 'Digital Twilight/Dusk', 'from' => 'Addons', 'requires_editing' => true],
        65 => ['name' => 'TV imaging', 'from' => 'Digital Enhancements', 'requires_editing' => true],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasTable('categories')) {
            return;
        }

        $categoryId = DB::table('categories')
            ->where('name', self::DIGITAL_ENHANCEMENT_CATEGORY)
            ->value('id');

        if (! $categoryId) {
            return;
        }

        foreach (self::SERVICES as $id => $expected) {
            $row = DB::table('services')->where('id', $id)->first();
            if (! $row || (string) $row->name !== $expected['name']) {
                continue;
            }

            $updates = [
                'category_id' => $categoryId,
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('services', 'requires_editing')) {
                $updates['requires_editing'] = $expected['requires_editing'];
            }

            DB::table('services')->where('id', $id)->update($updates);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasTable('categories')) {
            return;
        }

        foreach (self::SERVICES as $id => $expected) {
            $row = DB::table('services')->where('id', $id)->first();
            if (! $row || (string) $row->name !== $expected['name']) {
                continue;
            }

            $fromCategoryId = DB::table('categories')->where('name', $expected['from'])->value('id');
            if (! $fromCategoryId) {
                continue;
            }

            $updates = [
                'category_id' => $fromCategoryId,
                'updated_at' => now(),
            ];

            if ($id === 18 && Schema::hasColumn('services', 'requires_editing')) {
                $updates['requires_editing'] = false;
            }

            DB::table('services')->where('id', $id)->update($updates);
        }
    }
};
