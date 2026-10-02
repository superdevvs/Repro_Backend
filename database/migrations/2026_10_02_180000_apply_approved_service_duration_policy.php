<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SNAPSHOTS = 'service_duration_policy_snapshots';

    /** Reviewed active catalogue identities only; historical and test SKUs are excluded. */
    private const POLICY = [
        1 => ['name' => '25 HDR Photos', 'minutes' => 30],
        2 => ['name' => 'HDR Photos', 'minutes' => 30],
        3 => ['name' => '25 Flash Photos', 'minutes' => 45],
        4 => ['name' => '35 HDR Photos', 'minutes' => 45],
        5 => ['name' => '15 HDR -Rental Listings only', 'minutes' => 30],
        6 => ['name' => '10 Exterior HDR Photos', 'minutes' => 15],
        7 => ['name' => '45 HDR Photos', 'minutes' => 60],
        8 => ['name' => '55 HDR Photos', 'minutes' => 75],
        12 => ['name' => 'Walkthrough Video', 'minutes' => 45],
        13 => ['name' => 'Basic Social media/vertical video', 'minutes' => 30],
        15 => ['name' => '10-12 Drone/Aerial Photos', 'minutes' => 30],
        17 => ['name' => '2D Floor plans', 'minutes' => 15],
        18 => ['name' => 'Virtual Staging (per image)', 'minutes' => 0],
        19 => ['name' => 'Premium iGuide with Floor plans', 'minutes' => 30],
        31 => ['name' => '30 BnB Photos', 'minutes' => 60],
        35 => ['name' => 'Amenities Photos', 'minutes' => 30],
        36 => ['name' => 'Luxury Highlight Video', 'minutes' => 75],
        38 => ['name' => 'Enhanced Social Media Vertical Video', 'minutes' => 45],
        39 => ['name' => 'Ultimate Social Media Vertical Video', 'minutes' => 60],
        43 => ['name' => '6-7 Drone/Aerials Photos', 'minutes' => 30],
        44 => ['name' => 'Drone Silver Package', 'minutes' => 45],
        45 => ['name' => 'Drone Gold Package', 'minutes' => 60],
        46 => ['name' => 'Drone Platinum Package', 'minutes' => 75],
        48 => ['name' => 'Boundary Lines - Photos', 'minutes' => 0],
        49 => ['name' => 'Boundary Lines - Video', 'minutes' => 0],
        50 => ['name' => 'Boundary Lines - Photos & Video', 'minutes' => 0],
        51 => ['name' => 'Green Grass Enhancement', 'minutes' => 0],
        52 => ['name' => 'Digital Twilight/Dusk', 'minutes' => 0],
        53 => ['name' => 'HDR Photos & Video', 'minutes' => 75],
        54 => ['name' => 'HDR Photos & 3D Matterport', 'minutes' => 75],
        55 => ['name' => 'HDR Photos, Video & 3D Matterport', 'minutes' => 120],
        56 => ['name' => 'HDR Photos & Premium iGuide', 'minutes' => 60],
        57 => ['name' => 'HDR Photos, Video & Premium iGuide', 'minutes' => 105],
        58 => ['name' => '30 HDR Photos + Floor plans', 'minutes' => 60],
        61 => ['name' => 'Travel fee - 60 miles', 'minutes' => 0],
        62 => ['name' => 'Travel fee - 90 Miles', 'minutes' => 0],
        63 => ['name' => 'Travel fee - 100 miles', 'minutes' => 0],
        64 => ['name' => 'Travel Fee - 120 miles', 'minutes' => 0],
        65 => ['name' => 'TV imaging', 'minutes' => 0],
        69 => ['name' => '40 HDR Photos + 1 Min Vertical Video', 'minutes' => 90],
        70 => ['name' => 'Rush Photo editing fee', 'minutes' => 0],
        71 => ['name' => '10 Interior Photos Reshoot', 'minutes' => 15],
        72 => ['name' => '30 HDR Photos + 2D Floor plans', 'minutes' => 60],
        73 => ['name' => '3 Twilight Photos', 'minutes' => 15],
        74 => ['name' => '5 Twilight Photos', 'minutes' => 15],
        75 => ['name' => '10 Twilight Photos', 'minutes' => 30],
        76 => ['name' => '65 HDR Photos', 'minutes' => 90],
        77 => ['name' => '10 HDR Photos', 'minutes' => 15],
        78 => ['name' => '5 HDR Photos', 'minutes' => 15],
        79 => ['name' => '10 Flash Photos', 'minutes' => 15],
        80 => ['name' => '3D Matterport w/ Floor plans', 'minutes' => 45],
        81 => ['name' => 'Zillow 3D w/Floor plans', 'minutes' => 30],
        82 => ['name' => '5 Elevated Photos', 'minutes' => 15],
        83 => ['name' => 'HDR Photos & Premium iGuide', 'minutes' => 60],
        84 => ['name' => 'HDR Photos, Video & Premium iGuide', 'minutes' => 105],
        85 => ['name' => 'Zillow SHOWCASE Premium', 'minutes' => 90],
        86 => ['name' => 'ZILLOW SHOWCASE Standard', 'minutes' => 60],
        87 => ['name' => 'Virtual staging (per image)', 'minutes' => 0],
        90 => ['name' => 'Onsite Cancellation/hold fee', 'minutes' => 0],
        91 => ['name' => 'HDR Photos + Video + iGuide*', 'minutes' => 105],
        92 => ['name' => 'HDR Photos + iGuide*', 'minutes' => 60],
        93 => ['name' => 'HDR Photos + Video + Matterport*', 'minutes' => 120],
        94 => ['name' => 'HDR Photos + 3D Matterport*', 'minutes' => 75],
        174 => ['name' => 'Agent on camera', 'minutes' => 15],
        238 => ['name' => 'Comp Green Grass', 'minutes' => 0],
    ];

    private const HDR_PHOTO_SERVICES = [1, 2, 4, 7, 8, 76];
    private const HDR_MINUTES = [25 => 30, 35 => 45, 45 => 60, 55 => 75, 65 => 90];

    public function up(): void
    {
        if (! Schema::hasTable(self::SNAPSHOTS)) {
            Schema::create(self::SNAPSHOTS, function (Blueprint $table): void {
                $table->id();
                $table->string('source_table');
                $table->unsignedBigInteger('source_id');
                $table->text('before_json');
                $table->text('applied_json');
                $table->timestamp('applied_at');
                $table->unique(['source_table', 'source_id']);
            });
        }

        DB::transaction(function (): void {
            foreach (self::POLICY as $id => $policy) {
                $service = DB::table('services')->where('id', $id)->first();
                if (! $service || $service->is_migration_only
                    || strtolower(trim($service->name)) !== strtolower($policy['name'])) {
                    continue;
                }

                $base = $policy['minutes'];
                $ranges = DB::table('service_sqft_ranges')->where('service_id', $id)
                    ->orderBy('sqft_from')->orderBy('id')->get();
                // Measure old increments before applying either catalogue or tier changes.
                $oldBaseline = $ranges->first()?->duration
                    ?? $service->shoot_duration_minutes
                    ?? 60;
                $serviceFields = ['shoot_duration_minutes' => $base];
                if ($base === 0) {
                    // A fee/digital extra must not obtain a photographer block through a fallback.
                    $serviceFields['photographer_required'] = 0;
                }
                $this->applyOnce('services', $id, $serviceFields);

                $previousMinutes = $base;
                foreach ($ranges as $index => $range) {
                    if ($base === 0) {
                        $minutes = 0;
                    } elseif (in_array($id, self::HDR_PHOTO_SERVICES, true)) {
                        $minutes = max($base, self::HDR_MINUTES[(int) $range->photo_count] ?? $base);
                    } else {
                        $oldMinutes = $range->duration ?? $oldBaseline;
                        $minutes = $base + max($index * 15, (int) $oldMinutes - (int) $oldBaseline);
                    }
                    // Do not reduce the time for a larger tier with inconsistent old data.
                    $minutes = max($previousMinutes, $minutes);
                    $previousMinutes = $minutes;
                    $this->applyOnce('service_sqft_ranges', (int) $range->id, ['duration' => $minutes]);
                }
            }
        });
    }

    private function applyOnce(string $table, int $id, array $values): void
    {
        if (DB::table(self::SNAPSHOTS)->where('source_table', $table)->where('source_id', $id)->exists()) {
            return;
        }
        $row = DB::table($table)->where('id', $id)->first();
        $before = [];
        foreach ($values as $field => $value) {
            $before[$field] = $row->{$field};
        }
        DB::table(self::SNAPSHOTS)->insert([
            'source_table' => $table, 'source_id' => $id,
            'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
            'applied_json' => json_encode($values, JSON_THROW_ON_ERROR),
            'applied_at' => now()->toDateTimeString(),
        ]);
        // No model hooks or unrelated timestamps/prices/payouts/booking snapshots are changed.
        DB::table($table)->where('id', $id)->update($values);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::SNAPSHOTS)) {
            return;
        }
        DB::transaction(function (): void {
            foreach (DB::table(self::SNAPSHOTS)->orderByDesc('id')->get() as $snapshot) {
                if (! in_array($snapshot->source_table, ['services', 'service_sqft_ranges'], true)) {
                    continue;
                }
                $row = DB::table($snapshot->source_table)->where('id', $snapshot->source_id)->first();
                if (! $row) {
                    continue;
                }
                $applied = json_decode($snapshot->applied_json, true, 512, JSON_THROW_ON_ERROR);
                $before = json_decode($snapshot->before_json, true, 512, JSON_THROW_ON_ERROR);
                // Undo only fields still carrying this migration's value; preserve later edits.
                $restore = [];
                foreach ($applied as $field => $value) {
                    if ($row->{$field} !== null && (string) $row->{$field} === (string) $value) {
                        $restore[$field] = $before[$field];
                    }
                }
                if ($restore !== []) {
                    DB::table($snapshot->source_table)->where('id', $snapshot->source_id)->update($restore);
                }
            }
        });
        Schema::drop(self::SNAPSHOTS);
    }
};
