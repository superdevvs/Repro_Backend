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
 * Feature: google-calendar-sync-upgrade, Property 3: Internal shoot link is
 * always the final line.
 *
 * Validates: Requirements 3.11
 *
 * For any shoot, the last line of the description produced by
 * GoogleCalendarEventPayloadBuilder::build() (the `description` key, derived
 * from buildDescription() -> buildShootUrl()) is exactly:
 *
 *     View shoot: {base}/shoots/{shoot_id}
 *
 * where {base} is the configured dashboard base URL, read from
 * config('services.google.calendar.dashboard_url', 'https://reprodashboard.com')
 * with any trailing slash trimmed (rtrim '/'). The property must hold:
 *
 *   (a) for the default configured base URL (https://reprodashboard.com);
 *   (b) for an overridden base URL, including one carrying a trailing slash,
 *       which must be trimmed so the link never contains a double slash before
 *       "/shoots/";
 *   (c) regardless of the shoot's other content (cancellation channel, client
 *       identity, attached services, presence/absence of notes) — none of which
 *       may displace the link from the final position.
 *
 * Approach: no PHP property-based testing library is configured for the
 * backend, so this test follows the deterministic-generator convention used by
 * the rest of the suite (see GoogleCalendarTitlePropertyTest,
 * CubiCasaPerShootIdempotencyPropertyTest): a seeded PRNG produces well over
 * 100 randomized shoot states, and each is checked under both the default and a
 * randomly chosen (sometimes trailing-slash) overridden base URL. External
 * Google Calendar HTTP is mocked (the builder issues none, but the
 * GoogleCalendarService is bound to a mock and stray HTTP is blocked).
 */
class GoogleCalendarInternalLinkPropertyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /** Property iterations — comfortably above the mandated 100. */
    private const ITERATIONS = 150;

    /** Fixed seed so any counterexample reproduces deterministically. */
    private const SEED = 3_00_03;

    /** The default base URL configured for the integration. */
    private const DEFAULT_BASE = 'https://reprodashboard.com';

    /** A spread of statuses, including the cancellation channel. */
    private const STATUSES = [
        Shoot::STATUS_REQUESTED,
        Shoot::STATUS_SCHEDULED,
        Shoot::STATUS_UPLOADED,
        Shoot::STATUS_EDITING,
        Shoot::STATUS_REVIEW,
        Shoot::STATUS_READY,
        Shoot::STATUS_DELIVERED,
        Shoot::STATUS_ON_HOLD,
        Shoot::STATUS_DECLINED,
        Shoot::STATUS_CANCELLED,
    ];

    /**
     * Override base URLs the generator may apply. Some carry a trailing slash
     * to exercise the rtrim('/') contract; the link must never double the slash.
     */
    private const OVERRIDE_BASES = [
        'https://staging.example.com',
        'https://staging.example.com/',
        'https://dashboard.test',
        'https://dashboard.test/',
        'http://localhost:8080',
        'http://localhost:8080/',
    ];

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
     * Feature: google-calendar-sync-upgrade, Property 3: Internal shoot link is
     * always the final line.
     *
     * Validates: Requirements 3.11
     */
    public function test_internal_shoot_link_is_always_the_final_line(): void
    {
        mt_srand(self::SEED);

        $builder = app(GoogleCalendarEventPayloadBuilder::class);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // --- Decide the configured base URL for this iteration.
            // 0: default config; 1: overridden config (possibly trailing slash).
            $useOverride = mt_rand(0, 1) === 1;
            if ($useOverride) {
                $rawBase = self::OVERRIDE_BASES[mt_rand(0, count(self::OVERRIDE_BASES) - 1)];
                config(['services.google.calendar.dashboard_url' => $rawBase]);
            } else {
                $rawBase = self::DEFAULT_BASE;
                config(['services.google.calendar.dashboard_url' => $rawBase]);
            }

            // The contracted base: configured value with any trailing slash trimmed.
            $expectedBase = rtrim($rawBase, '/');

            // --- Generate a client identity (name / company / both empty).
            $identityCase = mt_rand(0, 2);
            [$clientName, $clientCompany] = match ($identityCase) {
                0 => ['ClientNm' . $i . 'Qz', 'ClientCo' . $i . 'Qz'],
                1 => ['', 'ClientCo' . $i . 'Qz'],
                default => ['', ''],
            };

            // Randomly include/exclude contact details so phone/email lines vary.
            $client = User::factory()->create([
                'role' => 'client',
                'name' => $clientName,
                'company_name' => $clientCompany,
                // users.email is NOT NULL and unique, so always supply a unique address.
                // Phone presence still varies to exercise the optional contact line.
                'phone' => mt_rand(0, 1) === 1 ? '555-01' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) : '',
                'email' => "client{$i}@example.test",
            ]);

            $photographer = User::factory()->photographer()->create([
                'timezone' => 'America/New_York',
            ]);

            // --- Cancellation channel varies (cancelled adds a status line, but
            //     must not displace the link from the final position).
            $status = self::STATUSES[mt_rand(0, count(self::STATUSES) - 1)];
            $workflowStatus = self::STATUSES[mt_rand(0, count(self::STATUSES) - 1)];

            $scheduledAt = now()->addDays(mt_rand(1, 30))->setTime(mt_rand(7, 18), [0, 15, 30, 45][mt_rand(0, 3)]);

            // --- Notes presence varies so the named sections render text or "Not provided".
            $shoot = Shoot::factory()->create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
                'status' => $status,
                'workflow_status' => $workflowStatus,
                'scheduled_at' => $scheduledAt,
                'scheduled_date' => $scheduledAt->toDateString(),
                'time' => $scheduledAt->format('H:i'),
                'shoot_notes' => mt_rand(0, 1) === 1 ? 'Gate code 1234. Lockbox on rail.' : '',
                'notes' => mt_rand(0, 1) === 1 ? 'Park in the driveway.' : '',
                'photographer_notes' => mt_rand(0, 1) === 1 ? 'Bring wide lens.' : '',
            ]);

            // --- Attach 0-3 services so the services block length varies.
            $serviceCount = mt_rand(0, 3);
            for ($s = 0; $s < $serviceCount; $s++) {
                $service = Service::factory()->create([
                    'name' => 'SvcNm' . $i . '_' . $s . 'Qz',
                    'delivery_time' => 1,
                ]);
                $shoot->services()->attach($service->id, [
                    'price' => 100,
                    'quantity' => 1,
                    'photographer_pay' => 40,
                    'photographer_id' => $photographer->id,
                ]);
            }

            $payload = $builder->build($shoot->fresh(['services', 'client']), $photographer);
            $description = $payload['description'] ?? '';

            $context = sprintf(
                'iteration %d, base=%s, identityCase=%d, status=%s, workflow=%s, services=%d',
                $i,
                $rawBase,
                $identityCase,
                $status,
                $workflowStatus,
                $serviceCount
            );

            $expectedLink = "View shoot: {$expectedBase}/shoots/{$shoot->id}";

            // The description must be non-empty (the link guarantees at least one line).
            $this->assertNotSame('', $description, "description must not be empty. {$context}");

            // (a)/(b)/(c) The LAST line is exactly the contracted internal link.
            $lines = explode("\n", $description);
            $lastLine = end($lines);

            $this->assertSame(
                $expectedLink,
                $lastLine,
                "[a/b/c] the last line must be the internal shoot link. {$context}"
            );

            // (b) The trimmed base must never produce a double slash before "/shoots/".
            $this->assertStringContainsString(
                "{$expectedBase}/shoots/{$shoot->id}",
                $lastLine,
                "[b] link must use the trimmed base without a doubled slash. {$context}"
            );
            $this->assertStringNotContainsString(
                "//shoots/{$shoot->id}",
                $lastLine,
                "[b] link must not contain a doubled slash before /shoots/. {$context}"
            );

            // (c) The link appears exactly once and as the final line (no trailing content).
            $this->assertSame(
                1,
                substr_count($description, 'View shoot: '),
                "[c] the internal link prefix must appear exactly once. {$context}"
            );
            $this->assertStringEndsWith(
                $expectedLink,
                $description,
                "[c] the description must end with the internal link. {$context}"
            );
        }
    }
}
