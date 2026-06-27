<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bugfix exploration test — Property 1: Bug Condition.
 *
 * "Conservative, Lossless External Booking Mapping".
 *
 * CRITICAL (bugfix workflow semantics): every assertion in this file encodes the
 * EXPECTED (post-fix) behavior. On the current UNFIXED `ExternalBookingController::bookShoot`
 * these tests MUST FAIL — the failures are the counterexamples that prove the bug exists.
 * Once the additive fix lands (request schema + columns + mapping pipeline + notification),
 * the SAME tests will pass.
 *
 * The input domain is `(S services × P photographers × preferred/alternate presence ×
 * explicit assignments)`. A seeded generator (`test_property_*`) walks this domain and the
 * deterministic `test_case_*` methods pin concrete failing examples for reproducibility.
 *
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 2.3, 2.5, 2.10, 2.12, 2.15, 2.16, 2.18, 2.19
 */
class ExternalBookingShootSyncBugConditionTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'external-booking-test-key';

    /** Allowed mapping-status values (2.16). */
    private const MAPPING_STATUSES = ['fully_mapped', 'partially_mapped', 'needs_review'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.external_booking.api_key' => self::API_KEY]);

        // The bug must be observed in isolation from the queued side-effects job.
        Queue::fake();
    }

    // ---------------------------------------------------------------------
    // isBugCondition(X) — encoded from the design's formal specification.
    // ---------------------------------------------------------------------

    private function isBugCondition(array $payload): bool
    {
        $photographers = $this->normalizedRequestedPhotographers($payload);
        $services = $payload['services'] ?? [];

        $hasPhotographers = count($photographers) > 0;
        $hasExplicitAssignments = !empty($payload['service_assignments']);
        $hasAlternate = !empty($payload['alternate_date']) || !empty($payload['alternate_time']);
        $multiServiceSchedulingIntent = count($services) > 1
            && (!empty($payload['preferred_date']) || !empty($payload['alternate_date']));
        $preferredDateNoTime = !empty($payload['preferred_date']) && empty($payload['preferred_time']);

        return $hasPhotographers
            || $hasExplicitAssignments
            || $hasAlternate
            || $multiServiceSchedulingIntent
            || $preferredDateNoTime;
    }

    private function normalizedRequestedPhotographers(array $payload): array
    {
        $ids = [];

        foreach (['selected_photographers', 'requested_photographers'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        foreach (['selected_photographer_id', 'photographer_id'] as $key) {
            if (!empty($payload[$key])) {
                $ids[] = (int) $payload[$key];
            }
        }

        return array_values(array_unique($ids));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeServices(int $count): array
    {
        return Service::factory()
            ->count($count)
            ->create(['price' => 150.00])
            ->all();
    }

    private function makePhotographers(int $count): array
    {
        return User::factory()
            ->count($count)
            ->photographer()
            ->create()
            ->all();
    }

    private function basePayload(array $services, array $overrides = []): array
    {
        $servicePayload = array_map(
            fn (Service $s) => ['id' => $s->id, 'quantity' => 1],
            $services
        );

        return array_merge([
            'client_name' => 'Bug Condition Client',
            'client_email' => 'bug-' . uniqid() . '@example.com',
            'client_phone' => '2025550123',
            'address' => '123 Bug Condition Ave',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => $servicePayload,
            'source' => 'lovable',
        ], $overrides);
    }

    private function postBooking(array $payload)
    {
        return $this->withHeaders(['X-API-Key' => self::API_KEY])
            ->postJson('/api/external/book-shoot', $payload);
    }

    private function loadShoot(int $shootId): Shoot
    {
        return Shoot::with('services')->findOrFail($shootId);
    }

    private function pivotForService(Shoot $shoot, int $serviceId)
    {
        $service = $shoot->services->firstWhere('id', $serviceId);

        return $service?->pivot;
    }

    // ---------------------------------------------------------------------
    // Deterministic concrete sub-cases (pinned counterexamples)
    // ---------------------------------------------------------------------

    /**
     * S=1, P=1 → pivot photographer_id equals the selected id (2.3).
     * Fails today: pivot photographer_id is null (selection discarded).
     */
    public function test_case_single_photographer_single_service_assigns_pivot(): void
    {
        [$service] = $this->makeServices(1);
        [$photographer] = $this->makePhotographers(1);

        $payload = $this->basePayload([$service], [
            'preferred_date' => now()->addDays(5)->toDateString(),
            'preferred_time' => '10:00',
            'selected_photographers' => [$photographer->id],
        ]);

        $this->assertTrue($this->isBugCondition($payload), 'precondition: must be a bug condition');

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));
        $pivot = $this->pivotForService($shoot, $service->id);

        $this->assertNotNull($pivot, 'service must be attached to the shoot');
        $this->assertEquals(
            $photographer->id,
            $pivot->photographer_id,
            'COUNTEREXAMPLE: single photographer + single service must assign pivot photographer_id (got null on unfixed code).'
        );
        $this->assertEquals(Shoot::STATUS_REQUESTED, $shoot->status); // 2.18
    }

    /**
     * S=1, P>1 → no pivot assignment, requested_photographers persisted, warning recorded (2.5).
     * Fails today: requested_photographers / warnings columns absent.
     */
    public function test_case_multiple_photographers_one_service_records_request_and_warning(): void
    {
        [$service] = $this->makeServices(1);
        [$p1, $p2] = $this->makePhotographers(2);

        $payload = $this->basePayload([$service], [
            'preferred_date' => now()->addDays(5)->toDateString(),
            'preferred_time' => '10:00',
            'selected_photographers' => [$p1->id, $p2->id],
        ]);

        $this->assertTrue($this->isBugCondition($payload));

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));
        $pivot = $this->pivotForService($shoot, $service->id);

        $this->assertNull($pivot->photographer_id, 'ambiguous multi-photographer must stay unassigned');

        $requested = $shoot->getAttribute('requested_photographers');
        $this->assertNotNull(
            $requested,
            'COUNTEREXAMPLE: requested_photographers must be persisted for multi-photographer requests (column/field absent today).'
        );
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], array_map('intval', (array) $requested));

        $warnings = (array) $shoot->getAttribute('external_booking_warnings');
        $this->assertContains(
            'Multiple photographers were requested for one service. Please review manually.',
            $warnings,
            'COUNTEREXAMPLE: required warning was not recorded.'
        );
    }

    /**
     * S=2, preferred + alternate → the alternate persists ONLY on the shoot's
     * `alternate_scheduled_at`; it is never mapped onto service #2 (2.10).
     *
     * New contract (shoot-alternate-date-field, Task 1.1): the auto-mapper no longer
     * applies the alternate date to the second service. Services #2..N are left
     * unscheduled (pivot `scheduled_at` is null) while the alternate is preserved on
     * the shoot itself. The first service still reflects the preferred date.
     */
    public function test_case_preferred_and_alternate_two_services_persists_alternate_on_shoot(): void
    {
        $services = $this->makeServices(2);

        $preferredDate = now()->addDays(7)->toDateString();
        $alternateDate = now()->addDays(8)->toDateString();

        $payload = $this->basePayload($services, [
            'preferred_date' => $preferredDate,
            'preferred_time' => '09:00',
            'alternate_date' => $alternateDate,
            'alternate_time' => '13:00',
        ]);

        $this->assertTrue($this->isBugCondition($payload));

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        // The alternate is NOT applied to service #2 — it is left unscheduled.
        $secondPivot = $this->pivotForService($shoot, $services[1]->id);
        $this->assertNull(
            $secondPivot->scheduled_at,
            'Under the new contract the alternate must NOT be mapped onto service #2 (it stays unscheduled).'
        );

        // The alternate persists on the shoot itself.
        $this->assertNotNull(
            $shoot->getAttribute('alternate_scheduled_at'),
            'alternate_scheduled_at must be persisted on the shoot.'
        );
        $this->assertStringStartsWith(
            $alternateDate,
            (string) $shoot->getAttribute('alternate_scheduled_at'),
            'alternate_scheduled_at must equal the alternate date.'
        );

        // The first service still reflects the preferred date.
        $firstPivot = $this->pivotForService($shoot, $services[0]->id);
        $this->assertStringStartsWith(
            $preferredDate,
            (string) $firstPivot->scheduled_at,
            'first service must still be scheduled at the preferred date.'
        );
    }

    /**
     * Preferred date only, no time → time / scheduled_at / pivot scheduled_at all null (2.12).
     * Fails today: a fabricated `00:00` time is stored.
     */
    public function test_case_preferred_date_without_time_does_not_fabricate_midnight(): void
    {
        [$service] = $this->makeServices(1);

        $preferredDate = now()->addDays(9)->toDateString();

        $payload = $this->basePayload([$service], [
            'preferred_date' => $preferredDate,
            // no preferred_time
        ]);

        $this->assertTrue($this->isBugCondition($payload));

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));
        $pivot = $this->pivotForService($shoot, $service->id);

        $this->assertNull($shoot->time, 'COUNTEREXAMPLE: time must be null when no preferred_time given (fabricated 00:00 today).');
        $this->assertNull($shoot->scheduled_at, 'COUNTEREXAMPLE: scheduled_at must be null when no preferred_time given (fabricated midnight today).');
        $this->assertNull($pivot->scheduled_at, 'COUNTEREXAMPLE: pivot scheduled_at must be null when no preferred_time given.');
    }

    /**
     * Any external booking → external_booking_payload + external_booking_mapping_status set (2.15, 2.16).
     * Fails today: both columns are absent.
     */
    public function test_case_payload_and_mapping_status_persisted(): void
    {
        [$service] = $this->makeServices(1);
        [$photographer] = $this->makePhotographers(1);

        $payload = $this->basePayload([$service], [
            'preferred_date' => now()->addDays(4)->toDateString(),
            'preferred_time' => '14:30',
            'selected_photographers' => [$photographer->id],
        ]);

        $this->assertTrue($this->isBugCondition($payload));

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        $this->assertNotNull(
            $shoot->getAttribute('external_booking_payload'),
            'COUNTEREXAMPLE: external_booking_payload must be persisted (column absent today).'
        );
        $this->assertContains(
            $shoot->getAttribute('external_booking_mapping_status'),
            self::MAPPING_STATUSES,
            'COUNTEREXAMPLE: external_booking_mapping_status must be one of the allowed values (column absent today).'
        );
    }

    /**
     * needsReview(shoot) → a `shoot_assignment_review` activity log row exists (2.19).
     * Fails today: no review notification is ever created.
     */
    public function test_case_needs_review_creates_assignment_review_activity_log(): void
    {
        [$service] = $this->makeServices(1);
        [$p1, $p2] = $this->makePhotographers(2);

        // Multiple photographers for one service => unambiguously needs review.
        $payload = $this->basePayload([$service], [
            'preferred_date' => now()->addDays(6)->toDateString(),
            'preferred_time' => '11:15',
            'selected_photographers' => [$p1->id, $p2->id],
        ]);

        $this->assertTrue($this->isBugCondition($payload));

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shootId = (int) $response->json('data.shoot_id');

        $this->assertTrue(
            ShootActivityLog::query()
                ->where('shoot_id', $shootId)
                ->where('action', 'shoot_assignment_review')
                ->exists(),
            'COUNTEREXAMPLE: a shoot_assignment_review activity log row must exist when the booking needs review (none created today).'
        );
    }

    // ---------------------------------------------------------------------
    // Scoped property-based generator over the input domain.
    // ---------------------------------------------------------------------

    /**
     * Property 1: for ALL bug-condition inputs, the created shoot maps conservatively and
     * losslessly. A seeded generator walks
     * `(S services × P photographers × preferred/alternate presence)`.
     */
    public function test_property_conservative_lossless_mapping_over_generated_bug_inputs(): void
    {
        mt_srand(20260222); // deterministic / reproducible

        $servicePool = $this->makeServices(3);
        $photographerPool = $this->makePhotographers(3);
        $photographerIds = array_map(fn (User $u) => $u->id, $photographerPool);

        $iterations = 8;
        $checked = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $s = mt_rand(1, 3);
            $p = mt_rand(0, 3);
            $withAlternate = (bool) mt_rand(0, 1);
            $withPreferredTime = (bool) mt_rand(0, 1);

            $services = array_slice($servicePool, 0, $s);
            $selectedPhotographers = array_slice($photographerIds, 0, $p);

            $overrides = [
                'preferred_date' => now()->addDays(3 + $i)->toDateString(),
            ];
            if ($withPreferredTime) {
                $overrides['preferred_time'] = sprintf('%02d:00', 8 + ($i % 8));
            }
            if ($withAlternate) {
                $overrides['alternate_date'] = now()->addDays(20 + $i)->toDateString();
                $overrides['alternate_time'] = '15:30';
            }
            if ($p > 0) {
                $overrides['selected_photographers'] = $selectedPhotographers;
            }

            $payload = $this->basePayload($services, $overrides);

            if (!$this->isBugCondition($payload)) {
                continue; // only exercise the bug-condition subspace
            }
            $checked++;

            $example = "S={$s}, P={$p}, alternate=" . ($withAlternate ? 'yes' : 'no')
                . ', preferredTime=' . ($withPreferredTime ? 'yes' : 'no');

            $response = $this->postBooking($payload);
            $this->assertSame(201, $response->status(), "booking should succeed for [$example]");

            $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

            // Invariant: status is always requested (2.18).
            $this->assertEquals(Shoot::STATUS_REQUESTED, $shoot->status, "status must be requested for [$example]");

            // Invariant: provenance + mapping status persisted (2.15, 2.16).
            $this->assertNotNull(
                $shoot->getAttribute('external_booking_payload'),
                "COUNTEREXAMPLE [$example]: external_booking_payload must be persisted."
            );
            $this->assertContains(
                $shoot->getAttribute('external_booking_mapping_status'),
                self::MAPPING_STATUSES,
                "COUNTEREXAMPLE [$example]: mapping status must be set."
            );

            // Invariant: requested photographers are never lost (2.5, 2.7).
            if ($p > 0) {
                $requested = array_map('intval', (array) $shoot->getAttribute('requested_photographers'));
                $this->assertEqualsCanonicalizing(
                    $selectedPhotographers,
                    $requested,
                    "COUNTEREXAMPLE [$example]: requested_photographers must be preserved."
                );
            }

            // Conservative photographer assignment.
            $firstPivot = $this->pivotForService($shoot, $services[0]->id);
            if ($s === 1 && $p === 1) {
                $this->assertEquals(
                    $selectedPhotographers[0],
                    $firstPivot->photographer_id,
                    "COUNTEREXAMPLE [$example]: S=1,P=1 must assign the photographer to the service."
                );
            }
            if ($p > 1) {
                $this->assertNull(
                    $firstPivot->photographer_id,
                    "COUNTEREXAMPLE [$example]: ambiguous photographer set must leave the pivot unassigned."
                );
            }

            // No fabricated photographer: any assigned pivot id must be one that was requested.
            foreach ($shoot->services as $svc) {
                if (!is_null($svc->pivot->photographer_id)) {
                    $this->assertContains(
                        (int) $svc->pivot->photographer_id,
                        $selectedPhotographers,
                        "COUNTEREXAMPLE [$example]: fabricated photographer assignment detected."
                    );
                }
            }

            // No fabricated midnight: preferred date without time => null schedule (2.12).
            if (!$withPreferredTime) {
                $this->assertNull(
                    $shoot->scheduled_at,
                    "COUNTEREXAMPLE [$example]: scheduled_at must be null when no preferred time was provided."
                );
                $this->assertNull(
                    $shoot->time,
                    "COUNTEREXAMPLE [$example]: time must be null when no preferred time was provided."
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'generator must exercise at least one bug-condition input');
    }
}
