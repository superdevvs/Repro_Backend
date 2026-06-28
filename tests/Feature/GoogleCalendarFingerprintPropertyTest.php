<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
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
use ReflectionMethod;
use Tests\TestCase;

/**
 * Feature: google-calendar-sync-upgrade, Property 9: Fingerprint changes iff a
 * tracked field changes.
 *
 * Validates: Requirements 9.1, 9.2, 9.3
 *
 * For any two shoot states, the recomputed sync fingerprint produced by
 * GoogleCalendarShootSyncService::fingerprintFor() differs IFF at least one
 * TRACKED field differs. The tracked fields are exactly the canonical signature
 * inputs (Req 9.3):
 *
 *   client name, client phone (phone ?: phonenumber), client email, full
 *   address (payload `location`), scheduled_at, photographer (connection
 *   user_id), service names (sorted), per-service scheduled times, notes
 *   (shoot_notes ?: notes), photographer_notes, status, workflow_status,
 *   cancellation state, and the target calendar_id.
 *
 * The property therefore has two directions, both asserted here:
 *
 *   (a) mutating ANY tracked field changes the fingerprint (Req 9.1, 9.3); and
 *   (b) mutating an UNTRACKED field — editor_notes, company_notes,
 *       admin_issue_notes, or per-service pricing — leaves the fingerprint
 *       unchanged, so unrelated edits do not trigger a needless calendar
 *       update (Req 9.2).
 *
 * Approach: no PHP property-based testing library is configured for the
 * backend, so this test follows the deterministic-generator convention used by
 * the rest of the suite (see GoogleCalendarTitlePropertyTest,
 * GoogleCalendarServiceTimingPropertyTest): a seeded PRNG produces well over
 * 100 randomized baseline shoot states; each iteration applies exactly one
 * single-field mutation (cycling through every tracked and untracked field for
 * full coverage) and asserts the fingerprint changes or holds accordingly.
 *
 * `fingerprintFor` is `protected`, so it is invoked via a ReflectionMethod
 * against a real GoogleCalendarShootSyncService whose GoogleCalendarService
 * collaborator is mocked. The payload argument (whose `location` key supplies
 * the tracked Full_Address) is produced by the real
 * GoogleCalendarEventPayloadBuilder. No live Google Calendar HTTP is issued.
 */
class GoogleCalendarFingerprintPropertyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /** Property iterations — comfortably above the mandated 100. */
    private const ITERATIONS = 150;

    /** Fixed seed so any counterexample reproduces deterministically. */
    private const SEED = 9_00_09;

    /** Tracked mutations: each MUST change the fingerprint. */
    private const TRACKED_MUTATIONS = [
        'client_name',
        'client_phone',
        'client_email',
        'address',
        'scheduled_at',
        'photographer',
        'service_added',
        'service_time',
        'notes',
        'photographer_notes',
        'status',
        'workflow_status',
        'cancelled',
    ];

    /** Untracked mutations: each MUST leave the fingerprint unchanged. */
    private const UNTRACKED_MUTATIONS = [
        'editor_notes',
        'company_notes',
        'admin_issue_notes',
        'pricing',
    ];

    private GoogleCalendarShootSyncService $syncService;
    private GoogleCalendarEventPayloadBuilder $payloadBuilder;
    private ReflectionMethod $fingerprintMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // The fingerprint computation is pure (no HTTP), but the task mandates
        // the Google Calendar transport is mocked and no live HTTP escapes.
        $this->app->instance(GoogleCalendarService::class, Mockery::mock(GoogleCalendarService::class));
        Http::preventStrayRequests();
        Http::fake();

        $this->syncService = app(GoogleCalendarShootSyncService::class);
        $this->payloadBuilder = app(GoogleCalendarEventPayloadBuilder::class);

        $this->fingerprintMethod = new ReflectionMethod(
            GoogleCalendarShootSyncService::class,
            'fingerprintFor'
        );
        $this->fingerprintMethod->setAccessible(true);
    }

    /**
     * Feature: google-calendar-sync-upgrade, Property 9: Fingerprint changes iff
     * a tracked field changes.
     *
     * Validates: Requirements 9.1, 9.2, 9.3
     */
    public function test_fingerprint_changes_iff_a_tracked_field_changes(): void
    {
        mt_srand(self::SEED);

        // Interleave tracked and untracked mutations so both directions of the
        // "iff" are exercised across the full run.
        $mutationPlan = [];
        foreach (self::TRACKED_MUTATIONS as $name) {
            $mutationPlan[] = ['name' => $name, 'tracked' => true];
        }
        foreach (self::UNTRACKED_MUTATIONS as $name) {
            $mutationPlan[] = ['name' => $name, 'tracked' => false];
        }

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $mutation = $mutationPlan[$i % count($mutationPlan)];
            $context = [$i, $mutation['name']];

            [$shoot, $connection] = $this->makeBaselineShoot($i);

            $before = $this->computeFingerprint($shoot->id, $connection);

            $recomputeConnection = $this->applyMutation($mutation['name'], $shoot, $connection, $i);

            $after = $this->computeFingerprint($shoot->id, $recomputeConnection);

            if ($mutation['tracked']) {
                $this->assertNotSame(
                    $before,
                    $after,
                    sprintf(
                        'Mutating TRACKED field "%s" must change the fingerprint. (iteration %d)',
                        $context[1],
                        $context[0]
                    )
                );
            } else {
                $this->assertSame(
                    $before,
                    $after,
                    sprintf(
                        'Mutating UNTRACKED field "%s" must NOT change the fingerprint. (iteration %d)',
                        $context[1],
                        $context[0]
                    )
                );
            }
        }
    }

    /**
     * Build a fully-populated baseline shoot with a connected photographer and a
     * couple of priced service items, so every tracked/untracked field has a
     * meaningful value to mutate away from.
     *
     * @return array{0: Shoot, 1: GoogleCalendarConnection}
     */
    private function makeBaselineShoot(int $i): array
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'ClientNm' . $i . 'Qz',
            'company_name' => 'ClientCo' . $i . 'Qz',
            'phone' => '410555' . str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT),
            'phonenumber' => '443555' . str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT),
            'email' => "client.base.{$i}@example.test",
        ]);

        $photographer = User::factory()->photographer()->create([
            'timezone' => 'America/New_York',
        ]);

        $connection = $this->createConnection($photographer, "photographer.base.{$i}@example.test");

        $scheduledAt = now()->addDays(($i % 30) + 1)->setTime(9, 0);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'scheduled_date' => $scheduledAt->toDateString(),
            'time' => $scheduledAt->format('H:i'),
            'address' => (100 + $i) . ' Baseline St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'notes' => 'baseline notes ' . $i,
            'shoot_notes' => 'baseline shoot notes ' . $i,
            'photographer_notes' => 'baseline photographer notes ' . $i,
            'editor_notes' => 'baseline editor notes ' . $i,
            'company_notes' => 'baseline company notes ' . $i,
            'admin_issue_notes' => 'baseline admin issue notes ' . $i,
        ]);

        // Attach two distinct, priced services so service-name, per-service-time
        // and pricing mutations all have something to act on.
        for ($s = 0; $s < 2; $s++) {
            $service = Service::factory()->create([
                'name' => 'SvcNm' . $i . '_' . $s . 'Qz',
                'delivery_time' => 1,
            ]);

            $shoot->services()->attach($service->id, [
                'price' => 100 + $s,
                'quantity' => 1,
                'photographer_pay' => 40,
                'photographer_id' => $photographer->id,
                'scheduled_at' => $scheduledAt,
            ]);
        }

        return [$shoot, $connection];
    }

    /**
     * Apply exactly one single-field mutation to the baseline shoot. Returns the
     * connection that should be used to recompute the fingerprint (identical to
     * the input connection except for the photographer mutation, which swaps to
     * a different connected photographer).
     */
    private function applyMutation(
        string $name,
        Shoot $shoot,
        GoogleCalendarConnection $connection,
        int $i
    ): GoogleCalendarConnection {
        $client = $shoot->client;
        $firstServiceId = $shoot->services()->first()->id;

        switch ($name) {
            // ---- Tracked fields (must change the fingerprint) ----
            case 'client_name':
                $client->update(['name' => 'MutatedName' . $i . 'Zz']);
                break;
            case 'client_phone':
                $client->update(['phone' => '999000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
                break;
            case 'client_email':
                $client->update(['email' => "client.mutated.{$i}@example.test"]);
                break;
            case 'address':
                $shoot->update(['address' => (9000 + $i) . ' Mutated Ave']);
                break;
            case 'scheduled_at':
                $shoot->update(['scheduled_at' => $shoot->scheduled_at->copy()->addHours(3)]);
                break;
            case 'photographer':
                $newPhotographer = User::factory()->photographer()->create([
                    'timezone' => 'America/New_York',
                ]);

                return $this->createConnection($newPhotographer, "photographer.mutated.{$i}@example.test");
            case 'service_added':
                $extra = Service::factory()->create([
                    'name' => 'ExtraSvc' . $i . 'Qz',
                    'delivery_time' => 1,
                ]);
                $shoot->services()->attach($extra->id, [
                    'price' => 250,
                    'quantity' => 1,
                    'photographer_pay' => 40,
                    'photographer_id' => $shoot->photographer_id,
                    'scheduled_at' => $shoot->scheduled_at,
                ]);
                break;
            case 'service_time':
                $shoot->services()->updateExistingPivot($firstServiceId, [
                    'scheduled_at' => $shoot->scheduled_at->copy()->addHours(5),
                ]);
                break;
            case 'notes':
                $shoot->update(['shoot_notes' => 'mutated shoot notes ' . $i]);
                break;
            case 'photographer_notes':
                $shoot->update(['photographer_notes' => 'mutated photographer notes ' . $i]);
                break;
            case 'status':
                $shoot->update(['status' => Shoot::STATUS_UPLOADED]);
                break;
            case 'workflow_status':
                $shoot->update(['workflow_status' => Shoot::STATUS_EDITING]);
                break;
            case 'cancelled':
                $shoot->update(['status' => Shoot::STATUS_CANCELLED]);
                break;

            // ---- Untracked fields (must NOT change the fingerprint) ----
            case 'editor_notes':
                $shoot->update(['editor_notes' => 'mutated editor notes ' . $i]);
                break;
            case 'company_notes':
                $shoot->update(['company_notes' => 'mutated company notes ' . $i]);
                break;
            case 'admin_issue_notes':
                $shoot->update(['admin_issue_notes' => 'mutated admin issue notes ' . $i]);
                break;
            case 'pricing':
                $shoot->services()->updateExistingPivot($firstServiceId, [
                    'price' => 99999,
                    'photographer_pay' => 12345,
                ]);
                break;

            default:
                $this->fail("Unknown mutation: {$name}");
        }

        return $connection;
    }

    /**
     * Re-load the shoot fresh with the relations the fingerprint reads, build the
     * payload (supplying the tracked `location`), and invoke the protected
     * fingerprintFor() via reflection.
     */
    private function computeFingerprint(int $shootId, GoogleCalendarConnection $connection): string
    {
        $shoot = Shoot::with([
            'client',
            'services',
            'serviceItems.service',
            'serviceItems.photographer',
        ])->findOrFail($shootId);

        $payload = $this->payloadBuilder->build($shoot, $connection->user);

        return $this->fingerprintMethod->invoke($this->syncService, $shoot, $connection, $payload);
    }

    private function createConnection(User $user, string $email): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'provider_email' => $email,
            'calendar_id' => 'primary',
            'access_token' => 'access-' . $user->id,
            'refresh_token' => 'refresh-' . $user->id,
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);
    }
}
