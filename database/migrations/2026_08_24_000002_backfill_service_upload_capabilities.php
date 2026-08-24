<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The one authoritative source of catalogue upload capability.
 *
 * Every row below was read out of the live catalogue and classified explicitly. This
 * is data, not inference: nothing here parses a service name at runtime, and nothing
 * derives a lane from a category. "3D Matterport" is not excluded because it is
 * called Matterport; it is excluded because its classified intake is `none`.
 *
 * Each entry is verified against the name and category the id is expected to have.
 * A row whose identity has drifted is skipped and logged rather than reclassified,
 * because mislabelling a service silently changes both who can upload to it and what
 * raw count it is judged against. The predecessor of this migration shipped local-dev
 * identities ("HDR Photography" / "Photography"), every check failed, and the whole
 * catalogue silently stayed unbracketed in production. The guard was right; its
 * expectations were not. These expectations come from the production catalogue.
 *
 * Three independent fields are set here, and they are deliberately not derived from
 * one another:
 *
 *   intake       which upload lane may select this service, or none
 *   brackets     whether its photo lane is captured as exposure stacks
 *   photo_count  final photos contracted, where that number is genuinely fixed
 *
 * `photo_count` is the canonical expected-final-count field. `quantity` is booking
 * quantity and is never a photo count: it is 1 on essentially every booked row, so
 * reading it as a count is what produced the fictional "5 raw files" expectations for
 * floor plans and virtual staging. Counts are filled in only where the product
 * genuinely fixes them. Variable or unconfigured products are left unspecified on
 * purpose so the UI can say "not set" instead of inventing a denominator.
 */
return new class extends Migration
{
    /**
     * Verified production catalogue classification.
     *
     * @var array<int, array{
     *     name: string,
     *     category: string,
     *     intake: string,
     *     brackets: bool,
     *     photo_count?: int
     * }>
     */
    private const CATALOGUE = [
        // --- Photos: explicit fixed-count HDR capture -------------------------
        1 => ['name' => '25 HDR Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        // Variable count by design; deliberately left unspecified.
        2 => ['name' => 'HDR Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        // Flash capture, not exposure stacking.
        3 => ['name' => '25 Flash Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => false],
        4 => ['name' => '35 HDR Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        5 => ['name' => '15 HDR -Rental Listings only', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        6 => ['name' => '10 Exterior HDR Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        7 => ['name' => '45 HDR Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        8 => ['name' => '55 HDR Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        9 => ['name' => 'Interior reshoot 10 images', 'category' => 'Unassigned', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 10],
        10 => ['name' => 'LocalSTR - 40 Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        11 => ['name' => 'Weather/Limited Exteriors Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        32 => ['name' => 'Twilight Photos - 3 Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        33 => ['name' => 'Twilight Photos - 5 Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        34 => ['name' => 'Twilight Photos - 10 Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],
        35 => ['name' => 'Amenities Photos', 'category' => 'Photos', 'intake' => 'photo', 'brackets' => true],

        // --- Commercial: photo intake, open-ended capture method --------------
        // Hourly commercial work has no fixed deliverable count and no guaranteed
        // bracketed capture, so no HDR expectation is manufactured for it. If
        // bracketed commercial work is needed later it belongs on the execution row
        // as an explicit per-shoot decision, not as a catalogue-wide assumption.
        31 => ['name' => 'Commercial Photography (Hourly)', 'category' => 'Commercial', 'intake' => 'photo', 'brackets' => false],

        // --- Video-only capture ----------------------------------------------
        12 => ['name' => 'Walkthrough Video', 'category' => 'Video', 'intake' => 'video', 'brackets' => false],
        13 => ['name' => 'Basic: Social media/vertical video', 'category' => 'Video', 'intake' => 'video', 'brackets' => false],
        14 => ['name' => 'Ultimate: Social media/vertical Video', 'category' => 'Video', 'intake' => 'video', 'brackets' => false],
        36 => ['name' => 'Luxury Highlight Video', 'category' => 'Video', 'intake' => 'video', 'brackets' => false],
        37 => ['name' => 'Social Media Vertical Video - Basic', 'category' => 'Video', 'intake' => 'video', 'brackets' => false],
        38 => ['name' => 'Social Media Vertical Video - Enhanced', 'category' => 'Video', 'intake' => 'video', 'brackets' => false],
        39 => ['name' => 'Social Media Vertical Video - Ultimate', 'category' => 'Video', 'intake' => 'video', 'brackets' => false],

        // --- Drone / elevated: real photo capture that does not bracket -------
        // Aerial work is genuine media capture, unlike a dedicated tour product, so
        // it stays selectable. It is not bracketed: there is no evidence in the file
        // record of AEB stacks arriving through this workflow. Counts move to
        // photo_count because the real numbers were previously stranded in
        // services.quantity, which never reaches the payload as a count.
        15 => ['name' => '10-12 Drone Photos Package', 'category' => 'Drone', 'intake' => 'photo', 'brackets' => false, 'photo_count' => 10],
        43 => ['name' => '6-7 Drone/Aerials Photo Set', 'category' => 'Drone', 'intake' => 'photo', 'brackets' => false, 'photo_count' => 7],
        47 => ['name' => 'Elevated Photos - 5 Photos', 'category' => 'Drone', 'intake' => 'photo', 'brackets' => false, 'photo_count' => 5],

        // --- Drone bundles: one execution row, both lanes ---------------------
        // Each description fixes an aerial photo set plus an edited video, so the
        // single booked row legitimately serves both intake lanes.
        44 => ['name' => 'Drone Silver Package', 'category' => 'Drone', 'intake' => 'photo_video', 'brackets' => false, 'photo_count' => 7],
        45 => ['name' => 'Drone Gold Package', 'category' => 'Drone', 'intake' => 'photo_video', 'brackets' => false, 'photo_count' => 12],
        46 => ['name' => 'Drone Platinum Package', 'category' => 'Drone', 'intake' => 'photo_video', 'brackets' => false, 'photo_count' => 20],

        // --- Mixed photo + video products ------------------------------------
        // The tour portion of 22 continues through the dedicated iGuide workflow and
        // is not represented as an intake lane here.
        22 => ['name' => 'HDR Photo + Video + iGuide', 'category' => 'Video', 'intake' => 'photo_video', 'brackets' => true],
        26 => ['name' => '40 HDR + 1 Min Vertical Video *', 'category' => 'Video', 'intake' => 'photo_video', 'brackets' => true, 'photo_count' => 40],
        // Count stays unspecified until it is actually configured.
        53 => ['name' => 'HDR Photos & Video', 'category' => 'Photos & Video', 'intake' => 'photo_video', 'brackets' => true],

        // --- Bundles whose tour/floor-plan portion is not an intake lane ------
        // These carry real HDR photo work, so the booked row supplies photo intake.
        // The Matterport, iGuide and floor-plan portions are delivered by their own
        // workflows and must not create raw expectations.
        54 => ['name' => 'HDR Photos & 3D Matterport', 'category' => 'Packages', 'intake' => 'photo', 'brackets' => true],
        55 => ['name' => 'HDR Photos, Video & 3D Matterport', 'category' => 'Packages', 'intake' => 'photo_video', 'brackets' => true],
        56 => ['name' => 'HDR Photos & Premium iGuide', 'category' => 'Packages', 'intake' => 'photo', 'brackets' => true],
        57 => ['name' => 'HDR Photos, Video & Premium iGuide', 'category' => 'Packages', 'intake' => 'photo_video', 'brackets' => true],
        58 => ['name' => '30 HDR Photos + floor plans', 'category' => 'Photos & Floor plans', 'intake' => 'photo', 'brackets' => true, 'photo_count' => 30],

        // --- Dedicated 3D tour products: never an upload target --------------
        // Their booked associations stay exactly as they are; provider eligibility,
        // ingestion, public tours and generated floor-plan media all depend on them.
        19 => ['name' => 'Premium iGuide with Floor plans', 'category' => '3D/360 Tours', 'intake' => 'none', 'brackets' => false],
        40 => ['name' => '3D Matterport w/ 2D Floor plans', 'category' => '3D/360 Tours', 'intake' => 'none', 'brackets' => false],
        42 => ['name' => 'Zillow 3D Home Tour', 'category' => '3D/360 Tours', 'intake' => 'none', 'brackets' => false],

        // --- Floor plans: an output/provider deliverable, not camera capture ---
        // Completed floorplan media keeps arriving and staying visible; the booked
        // service simply is not a raw capture target. Booking and photographer
        // assignment are unaffected.
        17 => ['name' => '2D Floor plans', 'category' => 'Floor Plans', 'intake' => 'none', 'brackets' => false],

        // --- Enhancements: transformations of existing media ------------------
        18 => ['name' => 'Virtual Staging (per image)', 'category' => 'Virtual Staging', 'intake' => 'none', 'brackets' => false],
        30 => ['name' => 'Blue Sky Replacement', 'category' => 'Addons', 'intake' => 'none', 'brackets' => false],
        48 => ['name' => 'Boundary Lines - Photos', 'category' => 'Addons', 'intake' => 'none', 'brackets' => false],
        49 => ['name' => 'Boundary Lines - Video', 'category' => 'Addons', 'intake' => 'none', 'brackets' => false],
        50 => ['name' => 'Boundary Lines - Photos & Video', 'category' => 'Addons', 'intake' => 'none', 'brackets' => false],
        51 => ['name' => 'Green Grass Enhancement', 'category' => 'Addons', 'intake' => 'none', 'brackets' => false],
        52 => ['name' => 'Digital Twilight/Dusk', 'category' => 'Addons', 'intake' => 'none', 'brackets' => false],
        65 => ['name' => 'TV imaging', 'category' => 'Digital Enhancements', 'intake' => 'none', 'brackets' => false],

        // --- Fees, travel and test rows --------------------------------------
        27 => ['name' => 'On site Cancellation/Reschedule Fee', 'category' => 'Addons', 'intake' => 'none', 'brackets' => false],
        28 => ['name' => 'Rush Fee Photos', 'category' => 'Addons', 'intake' => 'none', 'brackets' => false],
        29 => ['name' => 'Travel Fee', 'category' => 'Addons', 'intake' => 'none', 'brackets' => false],
        61 => ['name' => 'Travel fee - 60 miles', 'category' => 'Travel', 'intake' => 'none', 'brackets' => false],
        62 => ['name' => 'Travel fee - 90 Miles', 'category' => 'Travel', 'intake' => 'none', 'brackets' => false],
        63 => ['name' => 'Travel fee - 100 miles', 'category' => 'Travel', 'intake' => 'none', 'brackets' => false],
        64 => ['name' => 'Travel Fee - 120 miles', 'category' => 'Travel', 'intake' => 'none', 'brackets' => false],
        60 => ['name' => 'Test Charge', 'category' => 'Test', 'intake' => 'none', 'brackets' => false],
        66 => ['name' => 'Test', 'category' => 'Test', 'intake' => 'none', 'brackets' => false],
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
                || trim((string) ($service->category_name ?? '')) === $expected['category'];

            if (! $nameMatches || ! $categoryMatches) {
                $skipped[] = [
                    'service_id' => $id,
                    'expected' => ['name' => $expected['name'], 'category' => $expected['category']],
                    'actual' => ['name' => $service->name, 'category' => $service->category_name],
                ];

                continue;
            }

            $update = ['upload_intake_type' => $expected['intake']];

            if ($hasBracketFlag) {
                $update['uses_hdr_brackets'] = $expected['brackets'];
            }

            // Only fill counts the product genuinely fixes. Absence here means
            // "unspecified", which downstream code must surface as unknown rather
            // than substitute with booking quantity.
            if ($hasPhotoCount && array_key_exists('photo_count', $expected)) {
                $update['photo_count'] = $expected['photo_count'];
            }

            DB::table('services')->where('id', $id)->update($update);
            $applied++;
        }

        Log::info('Service upload capability backfill complete.', [
            'applied' => $applied,
            'skipped_count' => count($skipped),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Capability classification is data, and the column drop in the schema migration
     * already removes it. Reverting to a guessed prior state would be less accurate
     * than leaving the recorded classification in place.
     */
    public function down(): void
    {
        // Intentionally irreversible.
    }
};
