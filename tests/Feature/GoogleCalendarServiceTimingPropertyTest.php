<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Feature: google-calendar-sync-upgrade, Property 8: Per-service timing block
 * appears iff schedules differ.
 *
 * Validates: Requirements 7.1, 7.2
 *
 * For any shoot, the description produced by
 * GoogleCalendarEventPayloadBuilder::build() contains the `Service Timing:`
 * block IFF the shoot's service items resolve to MORE THAN ONE distinct
 * effective scheduled_at value, where the effective schedule for a service item
 * is its own `scheduled_at` falling back to the shoot's `scheduled_at`
 * (item `scheduled_at` ?? shoot `scheduled_at`). When the block is present it
 * lists exactly one line per service item; otherwise the block is omitted
 * entirely (Req 7.1, 7.2).
 *
 * Approach: no PHP property-based testing library is configured for the
 * backend, so this test follows the deterministic-generator convention used by
 * the rest of the suite (see GoogleCalendarTitlePropertyTest,
 * CubiCasaPerShootIdempotencyPropertyTest): a seeded PRNG produces well over
 * 100 randomized shoot states spanning 0-4 service items whose per-item
 * schedules are sometimes null (fall back to the shoot time), sometimes equal
 * to the shoot time, and sometimes distinct alternate times. External Google
 * Calendar HTTP is mocked (the builder issues none, but the
 * GoogleCalendarService is bound to a mock and stray HTTP is blocked).
 */
class GoogleCalendarServiceTimingPropertyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /** Property iterations — comfortably above the mandated 100. */
    private const ITERATIONS = 150;

    /** Fixed seed so any counterexample reproduces deterministically. */
    private const SEED = 8_00_08;

    protected function setUp(): void
    {
        parent::setUp();

        // The builder performs pure string/array construction and makes no HTTP
        // calls, but the task mandates the Google Calendar transport is mocked
        // and no live HTTP escapes. Bind a mock service and block stray calls.
        $this->app->instance(GoogleCalendarService::class, Mockery::mock(GoogleCalendarService::class));
        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Feature: google-calendar-sync-upgrade, Property 8: Per-service timing
     * block appears iff schedules differ.
     *
     * Validates: Requirements 7.1, 7.2
     */
    public function test_service_timing_block_appears_iff_schedules_differ(): void
    {
        mt_srand(self::SEED);

        $builder = app(GoogleCalendarEventPayloadBuilder::class);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $photographer = User::factory()->photographer()->create([
                'timezone' => 'America/New_York',
            ]);

            $client = User::factory()->create([
                'role' => 'client',
                'name' => 'ClientNm' . $i . 'Qz',
            ]);

            // Shoot start time — the fallback used when an item has no schedule.
            $shootTime = now()->addDays(mt_rand(1, 30))->setTime(mt_rand(7, 12), [0, 15, 30, 45][mt_rand(0, 3)]);

            $shoot = Shoot::factory()->create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
                'status' => Shoot::STATUS_SCHEDULED,
                'scheduled_at' => $shootTime,
                'scheduled_date' => $shootTime->toDateString(),
                'time' => $shootTime->format('H:i'),
            ]);

            // Candidate effective times. Index 0 is the shoot time itself; every
            // other index is a distinct alternate time (different hour offset),
            // guaranteeing each candidate maps to a unique ISO timestamp.
            $candidates = [
                0 => $shootTime->copy(),
                1 => $shootTime->copy()->addHours(2),
                2 => $shootTime->copy()->addHours(4),
                3 => $shootTime->copy()->addHours(6),
                4 => $shootTime->copy()->addHours(8),
            ];

            // Generate 0-4 service items. Each item chooses a candidate index;
            // index 0 may be expressed either as an explicit shoot-time schedule
            // or as a null schedule that falls back to the shoot time — both
            // resolve to the same effective time.
            $itemCount = mt_rand(0, 4);
            $chosenIndexes = [];

            for ($s = 0; $s < $itemCount; $s++) {
                $index = mt_rand(0, 4);
                $chosenIndexes[] = $index;

                $service = Service::factory()->create([
                    'name' => 'SvcNm' . $i . '_' . $s . 'Qz',
                    'delivery_time' => 1,
                ]);

                // For the shoot-time candidate, randomly use a null pivot
                // scheduled_at (exercising the ?? shoot fallback) or an explicit
                // value. Alternate candidates are always explicit.
                $scheduledAt = null;
                if ($index !== 0) {
                    $scheduledAt = $candidates[$index];
                } elseif (mt_rand(0, 1) === 1) {
                    $scheduledAt = $candidates[0];
                }

                $shoot->services()->attach($service->id, [
                    'price' => 100,
                    'quantity' => 1,
                    'photographer_pay' => 40,
                    'photographer_id' => $photographer->id,
                    'scheduled_at' => $scheduledAt,
                ]);
            }

            // Expected distinct effective schedule count: each chosen candidate
            // index maps to a unique timestamp, so the distinct count equals the
            // number of unique chosen indexes.
            $distinctCount = count(array_unique($chosenIndexes));
            $expectedPresent = $distinctCount > 1;

            $payload = $builder->build($shoot->fresh(['services', 'client', 'serviceItems']), $photographer);
            $description = $payload['description'] ?? '';

            $context = sprintf(
                'iteration %d, itemCount=%d, indexes=[%s], distinct=%d',
                $i,
                $itemCount,
                implode(',', $chosenIndexes),
                $distinctCount
            );

            $blockPresent = str_contains($description, 'Service Timing:');

            // Core property: block present iff > 1 distinct effective schedule.
            $this->assertSame(
                $expectedPresent,
                $blockPresent,
                "Service Timing block presence must equal (distinct effective schedules > 1). {$context}"
            );

            // When present, the block lists exactly one line per service item.
            if ($blockPresent) {
                $lineCount = $this->countServiceTimingLines($description);

                $this->assertSame(
                    $itemCount,
                    $lineCount,
                    "Service Timing block must list one line per service item. {$context}"
                );
            }
        }
    }

    /**
     * Extract the "Service Timing:" section from a description (sections are
     * separated by a blank line) and count its "- " bullet lines.
     */
    private function countServiceTimingLines(string $description): int
    {
        $sections = preg_split('/\n\n/', $description) ?: [];

        foreach ($sections as $section) {
            if (str_starts_with($section, 'Service Timing:')) {
                $lines = preg_split('/\n/', $section) ?: [];

                return count(array_filter(
                    $lines,
                    static fn ($line) => str_starts_with($line, '- ')
                ));
            }
        }

        return 0;
    }
}
