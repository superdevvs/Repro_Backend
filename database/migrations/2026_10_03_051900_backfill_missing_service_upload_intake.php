<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue rows added or renamed after 2026_08_24_000002 stayed at the column
 * default `none`, so photographers assigned only to those services could not
 * upload (e.g. service 82 "5 Elevated Photos" on shoot #638).
 *
 * Same rules as the original backfill: explicit id+name(+category) guards, no
 * runtime name inference.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{name: string, category?: string, intake: string, brackets: bool, photo_count?: int}>
     */
    private const CATALOGUE = [
        69 => ['name' => '40 HDR Photos + 1 Min Vertical Video', 'category' => 'VYBE', 'intake' => 'photo_video', 'brackets' => true, 'photo_count' => 40],
        71 => ['name' => '10 Interior Photos Reshoot', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 10],
        72 => ['name' => '30 HDR Photos + 2D Floor plans', 'category' => 'VYBE', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 30],
        73 => ['name' => '3 Twilight Photos', 'category' => 'Twilight Photos', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 3],
        74 => ['name' => '5 Twilight Photos', 'category' => 'Twilight Photos', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 5],
        75 => ['name' => '10 Twilight Photos', 'category' => 'Twilight Photos', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 10],
        76 => ['name' => '65 HDR Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 65],
        77 => ['name' => '10 HDR Photos', 'category' => 'Addons', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 10],
        78 => ['name' => '5 HDR Photos', 'category' => 'Addons', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 5],
        79 => ['name' => '10 Flash Photos', 'category' => 'Addons', 'intake' => 'photo', 'brackets' => false, 'photo_count' => 10],
        // Replaces retired catalogue id 47 ("Elevated Photos - 5 Photos" / Drone).
        82 => ['name' => '5 Elevated Photos', 'category' => 'Elevated Photos', 'intake' => 'photo', 'brackets' => false, 'photo_count' => 5],
        91 => ['name' => 'HDR Photos + Video + iGuide*', 'category' => 'Old Packages', 'intake' => 'photo_video', 'brackets' => true],
        92 => ['name' => 'HDR Photos + iGuide*', 'category' => 'Old Packages', 'intake' => 'photo', 'brackets' => true],
        93 => ['name' => 'HDR Photos + Video + Matterport*', 'category' => 'Old Packages', 'intake' => 'photo_video', 'brackets' => true],
        94 => ['name' => 'HDR Photos + 3D Matterport*', 'category' => 'Old Packages', 'intake' => 'photo', 'brackets' => true],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'upload_intake_type')) {
            return;
        }

        $hasBracketFlag = Schema::hasColumn('services', 'uses_hdr_brackets');
        $hasPhotoCount = Schema::hasColumn('services', 'photo_count');
        $hasCategories = Schema::hasTable('categories');
        $applied = 0;
        $skipped = [];

        foreach (self::CATALOGUE as $id => $expected) {
            $query = DB::table('services')->where('services.id', $id);
            if ($hasCategories) {
                $query->leftJoin('categories', 'categories.id', '=', 'services.category_id')
                    ->select('services.id', 'services.name', 'categories.name as category_name');
            } else {
                $query->select('services.id', 'services.name', DB::raw('null as category_name'));
            }

            $service = $query->first();
            if (! $service) {
                continue;
            }

            $nameMatches = trim((string) $service->name) === $expected['name'];
            $categoryMatches = ! $hasCategories
                || ! array_key_exists('category', $expected)
                || trim((string) ($service->category_name ?? '')) === $expected['category'];

            if (! $nameMatches || ! $categoryMatches) {
                $skipped[] = [
                    'service_id' => $id,
                    'expected' => ['name' => $expected['name'], 'category' => $expected['category'] ?? null],
                    'actual' => ['name' => $service->name, 'category' => $service->category_name ?? null],
                ];
                continue;
            }

            $update = ['upload_intake_type' => $expected['intake']];
            if ($hasBracketFlag) {
                $update['uses_hdr_brackets'] = $expected['brackets'];
            }
            if ($hasPhotoCount && array_key_exists('photo_count', $expected)) {
                $update['photo_count'] = $expected['photo_count'];
            }

            DB::table('services')->where('id', $id)->update($update);
            $applied++;
        }

        Log::info('Missing service upload intake backfill complete.', [
            'applied' => $applied,
            'skipped_count' => count($skipped),
            'skipped' => $skipped,
        ]);
    }

    public function down(): void
    {
        // Irreversible data correction; left intentionally empty.
    }
};
