<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a catalogue service's raws are captured as multi-exposure bracket stacks.
 *
 * This flag says only *whether* a deliverable brackets, never how many exposures.
 * The count is execution state and lives on the shoot-service assignment, because
 * two services on one shoot can be shot by different photographers at 5x and 3x.
 *
 * It replaces a runtime heuristic ("Photography category with a positive
 * photo_count") that wrongly marked Aerial Drone Photos as bracketed. After this
 * migration, runtime code reads the boolean and never inspects service names.
 *
 * The backfill therefore has to be data, not inference. It names the services that
 * bracket by primary key and verifies each one against the name and category it is
 * expected to have. A row that does not match is left at the safe default and
 * logged, so a catalogue that has diverged from this list is never silently
 * mislabelled by a name pattern.
 */
return new class extends Migration
{
    /**
     * Services known to use bracketed HDR capture, by id, with the identity each
     * id is expected to have at migration time.
     *
     * @var array<int, array{name: string, category: string}>
     */
    private const BRACKETED_SERVICES = [
        1 => ['name' => 'HDR Photography', 'category' => 'Photography'],
        6 => ['name' => 'Twilight Photography', 'category' => 'Photography'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        if (! Schema::hasColumn('services', 'uses_hdr_brackets')) {
            Schema::table('services', function (Blueprint $table) {
                $table->boolean('uses_hdr_brackets')->default(false)->after('photo_count');
            });
        }

        // Everything else stays false, which is the column default: floor plans,
        // virtual tours, video and drone do not stack exposures.
        foreach (self::BRACKETED_SERVICES as $id => $expected) {
            $service = DB::table('services')
                ->leftJoin('categories', 'categories.id', '=', 'services.category_id')
                ->where('services.id', $id)
                ->select('services.id', 'services.name', 'categories.name as category_name')
                ->first();

            if (! $service) {
                continue;
            }

            $nameMatches = trim((string) $service->name) === $expected['name'];
            $categoryMatches = trim((string) ($service->category_name ?? '')) === $expected['category'];

            if (! $nameMatches || ! $categoryMatches) {
                Log::warning('Skipped uses_hdr_brackets backfill: service identity does not match the recorded expectation.', [
                    'service_id' => $id,
                    'expected' => $expected,
                    'actual' => ['name' => $service->name, 'category' => $service->category_name],
                ]);

                continue;
            }

            DB::table('services')->where('id', $id)->update(['uses_hdr_brackets' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services') && Schema::hasColumn('services', 'uses_hdr_brackets')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropColumn('uses_hdr_brackets');
            });
        }
    }
};
