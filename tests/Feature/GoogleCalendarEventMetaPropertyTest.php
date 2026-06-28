<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\Services\Shoots\ShootMutationSupportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Property tests for the event metadata produced by
 * GoogleCalendarEventPayloadBuilder::build() — specifically the `colorId`,
 * `reminders`, and `start`/`end` keys.
 *
 *   - Feature: google-calendar-sync-upgrade, Property 7: colorId is a supported
 *     value determined by status (Validates: Requirements 6.1)
 *   - Feature: google-calendar-sync-upgrade, Property 6: Reminders are explicit
 *     24h and 30min popups (Validates: Requirements 5.1)
 *   - Feature: google-calendar-sync-upgrade, Property 5: End time equals start
 *     plus clamped duration (Validates: Requirements 4.1, 4.2)
 *
 * Approach: no PHP property-based testing library is configured for the backend,
 * so these tests follow the deterministic-generator convention used by the rest
 * of the suite (see GoogleCalendarTitlePropertyTest): a seeded PRNG produces well
 * over 100 randomized shoot states. External Google Calendar HTTP is mocked (the
 * builder issues none, but GoogleCalendarService is bound to a mock and stray HTTP
 * is blocked).
 */
class GoogleCalendarEventMetaPropertyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /** Property iterations — comfortably above the mandated 100. */
    private const ITERATIONS = 150;

    /** Statuses present in the builder's STATUS_COLOR_MAP and their expected colorId. */
    private const STATUS_COLOR_MAP = [
        'scheduled' => '9',
        'requested' => '5',
        'on_hold' => '5',
        'uploaded' => '2',
        'completed' => '2',
        'editing' => '7',
        'review' => '7',
        'ready' => '10',
        'delivered' => '2',
        'cancelled' => '11',
        'declined' => '11',
    ];

    /** Default colorId for statuses absent from STATUS_COLOR_MAP. */
    private const DEFAULT_COLOR_ID = '9';

    /** A spread of statuses NOT present in the color map (exercise the default). */
    private const UNKNOWN_STATUSES = [
        'pending', 'archived', 'rescheduled', 'in_progress', 'paid', 'unknown', '',
    ];

    private array $candidateTimezones = [
        'UTC',
        'America/New_York',
        'America/Los_Angeles',
        'Europe/London',
        'Asia/Kolkata',
        'Australia/Sydney',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The builder performs pure string/array construction and makes no HTTP
        // calls, but the task mandates the Google Calendar transport is mocked and
        // no live HTTP escapes. Bind a mock service and block stray calls.
        $this->app->instance(GoogleCalendarService::class, Mockery::mock(GoogleCalendarService::class));
        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Feature: google-calendar-sync-upgrade, Property 7: colorId is a supported
     * value determined by status.
     *
     * Validates: Requirements 6.1
     *
     * For any shoot, the event `colorId`:
     *   (a) is one of Google's supported values ("1"–"11");
     *   (b) is determined solely by the shoot status per STATUS_COLOR_MAP, with a
     *       default of "9" for statuses outside the map — independent of client,
     *       services, workflow_status, or any other shoot field.
     */
    public function test_color_id_is_supported_value_determined_by_status(): void
    {
        mt_srand(7_00_01);

        $builder = app(GoogleCalendarEventPayloadBuilder::class);
        $mappedStatuses = array_keys(self::STATUS_COLOR_MAP);
        $supported = array_map('strval', range(1, 11));

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Pick a status: roughly half from the map, half unknown, so both the
            // mapping table and the default fallback are exercised.
            if (mt_rand(0, 1) === 0) {
                $status = $mappedStatuses[mt_rand(0, count($mappedStatuses) - 1)];
            } else {
                $status = self::UNKNOWN_STATUSES[mt_rand(0, count(self::UNKNOWN_STATUSES) - 1)];
            }

            $expectedColorId = self::STATUS_COLOR_MAP[strtolower(trim($status))] ?? self::DEFAULT_COLOR_ID;

            $shoot = $this->makeShoot([
                'status' => $status,
                // workflow_status varies independently to prove it does NOT drive color.
                'workflow_status' => $mappedStatuses[mt_rand(0, count($mappedStatuses) - 1)],
                'service_count' => mt_rand(0, 3),
            ]);

            $payload = $builder->build($shoot->fresh(['services', 'client']), $shoot->photographer);
            $colorId = $payload['colorId'] ?? null;

            $context = sprintf('iteration %d, status=%s', $i, var_export($status, true));

            // (a) Always a supported Google colorId.
            $this->assertContains(
                $colorId,
                $supported,
                "[a] colorId must be one of Google's supported values 1-11. {$context}"
            );

            // (b) Determined solely by status per the map (default "9" otherwise).
            $this->assertSame(
                $expectedColorId,
                $colorId,
                "[b] colorId must be determined solely by shoot status per the color map. {$context}"
            );
        }
    }

    /**
     * Feature: google-calendar-sync-upgrade, Property 6: Reminders are explicit
     * 24h and 30min popups.
     *
     * Validates: Requirements 5.1
     *
     * For any built event, `reminders.useDefault` is false and
     * `reminders.overrides` contains exactly two popup entries at 1440 and 30
     * minutes — nothing more, nothing less, regardless of shoot state.
     */
    public function test_reminders_are_explicit_24h_and_30min_popups(): void
    {
        mt_srand(6_00_01);

        $builder = app(GoogleCalendarEventPayloadBuilder::class);
        $statuses = array_keys(self::STATUS_COLOR_MAP);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $shoot = $this->makeShoot([
                'status' => $statuses[mt_rand(0, count($statuses) - 1)],
                'service_count' => mt_rand(0, 3),
            ]);

            $payload = $builder->build($shoot->fresh(['services', 'client']), $shoot->photographer);
            $reminders = $payload['reminders'] ?? null;

            $context = sprintf('iteration %d', $i);

            $this->assertIsArray($reminders, "reminders must be present. {$context}");
            $this->assertArrayHasKey('useDefault', $reminders, "reminders.useDefault must exist. {$context}");
            $this->assertFalse($reminders['useDefault'], "reminders.useDefault must be false. {$context}");

            $this->assertArrayHasKey('overrides', $reminders, "reminders.overrides must exist. {$context}");
            $overrides = $reminders['overrides'];
            $this->assertIsArray($overrides, "reminders.overrides must be an array. {$context}");

            // Exactly two overrides — no more, no fewer.
            $this->assertCount(2, $overrides, "reminders.overrides must contain exactly two entries. {$context}");

            // Every override is a popup.
            foreach ($overrides as $override) {
                $this->assertSame('popup', $override['method'] ?? null, "every override must be a popup. {$context}");
            }

            // The exact set of popup minutes is {1440, 30}.
            $minutes = array_map(static fn ($o) => $o['minutes'] ?? null, $overrides);
            sort($minutes);
            $this->assertSame([30, 1440], $minutes, "popup overrides must be exactly 30 and 1440 minutes. {$context}");
        }
    }

    /**
     * Feature: google-calendar-sync-upgrade, Property 5: End time equals start
     * plus clamped duration.
     *
     * Validates: Requirements 4.1, 4.2
     *
     * For any schedulable shoot, the event end time equals the start time plus
     * `ShootMutationSupportService::calculateShootDurationFromShoot()`, a value
     * clamped to 60–240 minutes (when derived from services) and defaulting to 120
     * when no duration is derivable. The generator varies the configured
     * default/min/max so the clamp is exercised at both bounds.
     */
    public function test_end_time_equals_start_plus_clamped_duration(): void
    {
        mt_srand(5_00_01);

        $builder = app(GoogleCalendarEventPayloadBuilder::class);
        $support = app(ShootMutationSupportService::class);
        $statuses = array_keys(self::STATUS_COLOR_MAP);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Vary the duration configuration to exercise the 60–240 clamp at both
            // ends. Keep the safety bounds fixed at the documented 60/240; vary the
            // default into out-of-range territory so the clamp is observable when a
            // service falls back to the default.
            config([
                'availability.default_shoot_duration_minutes' => mt_rand(20, 360),
                'availability.min_shoot_duration_minutes' => 60,
                'availability.max_shoot_duration_minutes' => 240,
            ]);

            $serviceCount = mt_rand(0, 3);
            $shoot = $this->makeShoot([
                'status' => $statuses[mt_rand(0, count($statuses) - 1)],
                'service_count' => $serviceCount,
            ]);

            $fresh = $shoot->fresh(['services', 'client']);
            $photographer = $shoot->photographer;
            $timezone = $photographer?->timezone ?: config('app.timezone', 'UTC');

            // The single source of truth for the expected duration.
            $expectedMinutes = $support->calculateShootDurationFromShoot($fresh);

            $payload = $builder->build($fresh, $photographer);
            $start = Carbon::parse($payload['start']['dateTime']);
            $end = Carbon::parse($payload['end']['dateTime']);

            $context = sprintf(
                'iteration %d, serviceCount=%d, expectedMinutes=%d, tz=%s',
                $i,
                $serviceCount,
                $expectedMinutes,
                $timezone
            );

            // end == start + calculateShootDurationFromShoot() minutes (Req 4.1).
            // diffInMinutes() returns a float in this Carbon version; compare as int.
            $this->assertSame(
                $expectedMinutes,
                (int) $start->diffInMinutes($end),
                "[Req 4.1] end must equal start plus the estimated duration. {$context}"
            );
            $this->assertTrue($end->greaterThan($start), "end must be after start. {$context}");

            // When the duration is derived from services it is clamped to 60–240
            // (Req 4.2). The no-service path returns the configured default directly.
            if ($serviceCount > 0) {
                $this->assertGreaterThanOrEqual(60, $expectedMinutes, "[Req 4.2] derived duration must be >= 60. {$context}");
                $this->assertLessThanOrEqual(240, $expectedMinutes, "[Req 4.2] derived duration must be <= 240. {$context}");
            }
        }

        // Explicit 120-minute default case (Req 4.2): a shoot with no services and
        // the default configuration must produce a 120-minute event.
        config([
            'availability.default_shoot_duration_minutes' => 120,
            'availability.min_shoot_duration_minutes' => 60,
            'availability.max_shoot_duration_minutes' => 240,
        ]);

        $defaultShoot = $this->makeShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'service_count' => 0,
        ]);
        $fresh = $defaultShoot->fresh(['services', 'client']);

        $this->assertSame(
            120,
            $support->calculateShootDurationFromShoot($fresh),
            'default duration with no services must be 120 minutes.'
        );

        $payload = $builder->build($fresh, $defaultShoot->photographer);
        $start = Carbon::parse($payload['start']['dateTime']);
        $end = Carbon::parse($payload['end']['dateTime']);

        $this->assertSame(
            120,
            (int) $start->diffInMinutes($end),
            'default (no-service) event must span exactly 120 minutes.'
        );
    }

    /**
     * Build a persisted shoot with a client, photographer, optional services, and a
     * future schedule. Accepts overrides for status / workflow_status / service_count.
     *
     * @param  array{status?:string, workflow_status?:string, service_count?:int}  $opts
     */
    private function makeShoot(array $opts = []): Shoot
    {
        static $seq = 0;
        $seq++;

        $status = $opts['status'] ?? Shoot::STATUS_SCHEDULED;
        $workflowStatus = $opts['workflow_status'] ?? $status;
        $serviceCount = $opts['service_count'] ?? mt_rand(0, 3);

        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'MetaClient' . $seq,
        ]);

        $photographer = User::factory()->photographer()->create([
            'name' => 'MetaPhotog' . $seq,
            'timezone' => $this->candidateTimezones[mt_rand(0, count($this->candidateTimezones) - 1)],
        ]);

        $scheduledAt = now()->addDays(mt_rand(1, 60))->setTime(mt_rand(6, 19), [0, 15, 30, 45][mt_rand(0, 3)]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'status' => $status,
            'workflow_status' => $workflowStatus,
            'scheduled_at' => $scheduledAt,
            'scheduled_date' => $scheduledAt->toDateString(),
            'time' => $scheduledAt->format('H:i'),
        ]);

        for ($s = 0; $s < $serviceCount; $s++) {
            $service = Service::factory()->create([
                'name' => 'MetaSvc' . $seq . '_' . $s,
                'delivery_time' => 1,
            ]);
            $shoot->services()->attach($service->id, [
                'price' => 100,
                'quantity' => 1,
                'photographer_pay' => 40,
                'photographer_id' => $photographer->id,
            ]);
        }

        return $shoot;
    }
}
