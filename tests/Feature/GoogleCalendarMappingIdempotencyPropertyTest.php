<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\Services\GoogleCalendar\GoogleCalendarShootSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Feature: google-calendar-sync-upgrade, Property 10: One mapping per
 * shoot/photographer (no duplicates).
 *
 * Validates: Requirements 10.1, 10.2, 10.3
 *
 * For any shoot processed any number of times without underlying changes,
 * exactly one GoogleCalendarEventMapping exists per
 * (shoot_id, shoot_service_id, user_id), and a matching fingerprint produces no
 * additional create call. Concretely, update-or-create is idempotent:
 *
 *   - The FIRST sync of a fresh shoot has no existing mapping, so the
 *     Calendar_Sync creates a Calendar_Event (createEvent called once) and
 *     stores a new Event_Mapping (Req 10.1, 10.3).
 *   - The SECOND sync of the unchanged shoot recomputes an identical
 *     Sync_Fingerprint, matches the stored mapping, and skips HTTP entirely:
 *     no second createEvent, no updateEvent, no deleteEvent (Req 10.1, 10.2).
 *   - After both syncs exactly one mapping row exists for
 *     (shoot_id, null, user_id) — the legacy whole-shoot mapping — with a
 *     stable, non-null fingerprint.
 *
 * Approach: no PHP property-based testing library is configured for the
 * backend, so this test follows the deterministic-generator convention used by
 * the rest of the suite (see GoogleCalendarTitlePropertyTest): a seeded PRNG
 * produces well over 100 randomized shoot states spanning client identity,
 * contact details, syncable statuses (including cancelled), notes presence, and
 * a spread of service counts. For each state the shoot is synced twice and the
 * idempotency invariants above are asserted. The Google Calendar transport
 * (GoogleCalendarService) is mocked so no live HTTP is issued.
 */
class GoogleCalendarMappingIdempotencyPropertyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /** Property iterations — comfortably above the mandated 100. */
    private const ITERATIONS = 120;

    /** Fixed seed so any counterexample reproduces deterministically. */
    private const SEED = 10_03_10;

    /**
     * Statuses that remain syncable under isSyncable() (cancelled is kept-and-
     * updated; requested / declined / on_hold / hold_on are excluded as they are
     * non-syncable and would delete rather than create).
     */
    private const SYNCABLE_STATUSES = [
        Shoot::STATUS_SCHEDULED,
        Shoot::STATUS_UPLOADED,
        Shoot::STATUS_EDITING,
        Shoot::STATUS_REVIEW,
        Shoot::STATUS_READY,
        Shoot::STATUS_DELIVERED,
        Shoot::STATUS_CANCELLED,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.calendar.base_url' => 'https://www.googleapis.com/calendar/v3',
            'services.google.calendar.dashboard_url' => 'https://reprodashboard.com',
        ]);

        // The transport is mocked per-iteration, but block any stray live HTTP.
        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Feature: google-calendar-sync-upgrade, Property 10: One mapping per
     * shoot/photographer (no duplicates).
     *
     * Validates: Requirements 10.1, 10.2, 10.3
     */
    public function test_double_sync_is_idempotent_with_a_single_mapping(): void
    {
        mt_srand(self::SEED);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // --- Vary the client identity / contact details across iterations.
            $identityCase = mt_rand(0, 2);
            [$clientName, $clientCompany] = match ($identityCase) {
                0 => ['Client Nm' . $i, 'Client Co' . $i],
                1 => ['', 'Client Co' . $i],
                default => ['', ''],
            };
            $clientPhone = mt_rand(0, 1) === 1 ? '410-555-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) : null;
            $clientEmail = mt_rand(0, 1) === 1 ? "client{$i}@example.com" : null;

            $client = User::factory()->create([
                'role' => 'client',
                'name' => $clientName,
                'company_name' => $clientCompany,
                'phone' => $clientPhone,
                'email' => $clientEmail ?: "fallback{$i}@example.com",
            ]);

            $photographer = User::factory()->photographer()->create([
                'timezone' => 'America/New_York',
            ]);

            // --- Active connection so the sync proceeds (Req 10 preconditions).
            GoogleCalendarConnection::create([
                'user_id' => $photographer->id,
                'provider_email' => "photographer{$i}@example.com",
                'calendar_id' => 'primary',
                'access_token' => "access-token-{$i}",
                'refresh_token' => "refresh-token-{$i}",
                'token_expires_at' => now()->addHour(),
                'sync_enabled' => true,
            ]);

            // --- Vary status and notes presence.
            $status = self::SYNCABLE_STATUSES[mt_rand(0, count(self::SYNCABLE_STATUSES) - 1)];
            $shootNotes = mt_rand(0, 1) === 1 ? "Gate code {$i}. Side entrance." : null;
            $scheduledAt = now()->addDays(mt_rand(1, 30))->setTime(mt_rand(7, 18), [0, 15, 30, 45][mt_rand(0, 3)]);

            $shoot = Shoot::factory()->create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
                'status' => $status,
                'workflow_status' => $status,
                'scheduled_at' => $scheduledAt,
                'scheduled_date' => $scheduledAt->toDateString(),
                'time' => $scheduledAt->format('H:i'),
                'shoot_notes' => $shootNotes,
            ]);

            // --- Attach 1-3 services WITHOUT a per-item scheduled_at so the legacy
            //     whole-shoot path is taken (a single whole-shoot mapping).
            $serviceCount = mt_rand(1, 3);
            for ($s = 0; $s < $serviceCount; $s++) {
                $service = Service::factory()->create([
                    'name' => "Svc {$i}-{$s}",
                    'delivery_time' => 1,
                ]);
                $shoot->services()->attach($service->id, [
                    'price' => 100,
                    'quantity' => 1,
                    'photographer_pay' => 40,
                    'photographer_id' => $photographer->id,
                ]);
            }

            // --- Per-iteration mocked transport with call counters.
            $createCalls = 0;
            $updateCalls = 0;
            $deleteCalls = 0;

            $calendarService = Mockery::mock(GoogleCalendarService::class);
            $calendarService->shouldReceive('createEvent')
                ->andReturnUsing(function () use (&$createCalls, $i) {
                    $createCalls++;
                    return ['id' => "google-event-{$i}-{$createCalls}"];
                });
            $calendarService->shouldReceive('updateEvent')
                ->andReturnUsing(function ($connection, $eventId, $payload) use (&$updateCalls) {
                    $updateCalls++;
                    return ['id' => $eventId];
                });
            $calendarService->shouldReceive('deleteEvent')
                ->andReturnUsing(function () use (&$deleteCalls) {
                    $deleteCalls++;
                });

            $syncService = new GoogleCalendarShootSyncService(
                $calendarService,
                app(GoogleCalendarEventPayloadBuilder::class)
            );

            $context = sprintf(
                'iteration %d, identityCase=%d, status=%s, services=%d',
                $i,
                $identityCase,
                $status,
                $serviceCount
            );

            // --- First sync: creates exactly one event + mapping.
            $syncService->syncShoot($shoot->id);

            $this->assertSame(1, $createCalls, "first sync must create exactly one event. {$context}");
            $this->assertSame(0, $updateCalls, "first sync must not update. {$context}");
            $this->assertSame(0, $deleteCalls, "first sync must not delete. {$context}");

            $mappingsAfterFirst = GoogleCalendarEventMapping::query()
                ->where('shoot_id', $shoot->id)
                ->whereNull('shoot_service_id')
                ->where('user_id', $photographer->id)
                ->get();

            $this->assertCount(
                1,
                $mappingsAfterFirst,
                "exactly one whole-shoot mapping must exist after the first sync. {$context}"
            );

            $fingerprintAfterFirst = $mappingsAfterFirst->first()->sync_fingerprint;
            $this->assertNotEmpty($fingerprintAfterFirst, "stored fingerprint must be non-empty. {$context}");

            // --- Second sync of identical state: fingerprint matches, HTTP skipped.
            $syncService->syncShoot($shoot->id);

            $this->assertSame(1, $createCalls, "second sync of unchanged state must NOT create again. {$context}");
            $this->assertSame(0, $updateCalls, "second sync of unchanged state must NOT update. {$context}");
            $this->assertSame(0, $deleteCalls, "second sync of unchanged state must NOT delete. {$context}");

            // --- Still exactly one mapping for (shoot_id, null, user_id), same fingerprint.
            $mappingsAfterSecond = GoogleCalendarEventMapping::query()
                ->where('shoot_id', $shoot->id)
                ->whereNull('shoot_service_id')
                ->where('user_id', $photographer->id)
                ->get();

            $this->assertCount(
                1,
                $mappingsAfterSecond,
                "exactly one whole-shoot mapping must exist after the second sync (no duplicates). {$context}"
            );

            $this->assertSame(
                $fingerprintAfterFirst,
                $mappingsAfterSecond->first()->sync_fingerprint,
                "re-running identical state must yield an identical fingerprint. {$context}"
            );

            // Also guard against duplicates across the full mapping key for this shoot.
            $this->assertSame(
                1,
                GoogleCalendarEventMapping::query()->where('shoot_id', $shoot->id)->count(),
                "exactly one total mapping row must exist for the shoot. {$context}"
            );
        }
    }
}
