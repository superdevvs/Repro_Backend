<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalShootRequestedJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bugfix preservation test — Property 2: Preservation.
 *
 * "Legacy Bookings Unaffected".
 *
 * CRITICAL (bugfix workflow semantics): every assertion in this file locks in the LEGACY
 * (pre-fix) behavior of `ExternalBookingController::bookShoot` for legacy-shaped payloads —
 * payloads that contain ONLY the existing fields, with at most a single preferred date+time,
 * and NONE of the new photographer / alternate-scheduling / service-assignment intent
 * (i.e. `isBugCondition(X)` is false). These tests MUST PASS on the current UNFIXED code:
 * passing establishes the baseline the additive fix must reproduce field-for-field.
 *
 * Methodology is observation-first: the booking is posted to the live endpoint, the created
 * shoot/pivot/activity-log/job-dispatch are read back, the exact values are captured, and the
 * handler is asserted to reproduce them. The same assertions must continue to hold after the
 * fix lands (the fix is purely additive over these legacy code paths).
 *
 * Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9
 */
class ExternalBookingShootSyncPreservationTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'external-booking-test-key';

    private const SERVICE_PRICE = 150.00;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.external_booking.api_key' => self::API_KEY]);

        // Observe the booking in isolation from the queued side-effects job, while still
        // being able to assert the job is dispatched (Requirement 3.9).
        Queue::fake();
    }

    // ---------------------------------------------------------------------
    // isBugCondition(X) — encoded from the design's formal specification, used here only as
    // a guard so every payload these tests exercise is genuinely legacy-shaped (NOT a bug
    // condition). If a payload ever trips this guard the test is invalid by construction.
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
            ->create(['price' => self::SERVICE_PRICE])
            ->all();
    }

    /**
     * Build a strictly legacy-shaped payload: only the existing request fields, no new
     * photographer / alternate / service-assignment keys.
     */
    private function basePayload(array $services, array $overrides = []): array
    {
        $servicePayload = array_map(
            fn (Service $s) => ['id' => $s->id, 'quantity' => 1],
            $services
        );

        return array_merge([
            'client_name' => 'Preservation Client',
            'client_email' => 'preserve-' . uniqid() . '@example.com',
            'client_phone' => '2025550123',
            'address' => '123 Preservation Ave',
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
        return $shoot->services->firstWhere('id', $serviceId)?->pivot;
    }

    private function assertNoAssignmentReviewNotification(int $shootId): void
    {
        $this->assertFalse(
            ShootActivityLog::query()
                ->where('shoot_id', $shootId)
                ->where('action', 'shoot_assignment_review')
                ->exists(),
            'Legacy bookings must NOT create a shoot_assignment_review notification.'
        );
    }

    // ---------------------------------------------------------------------
    // Deterministic observation cases
    // ---------------------------------------------------------------------

    /**
     * 3.1 — legacy single preferred date+time, no photographers, single service:
     * scheduled_at / scheduled_date / time are derived from the preferred values.
     */
    public function test_legacy_single_preferred_date_and_time_derives_schedule(): void
    {
        [$service] = $this->makeServices(1);

        $preferredDate = now()->addDays(5)->toDateString();
        $preferredTime = '10:30';

        $payload = $this->basePayload([$service], [
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
        ]);

        $this->assertFalse($this->isBugCondition($payload), 'precondition: must NOT be a bug condition');

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        // Observe & assert the derived shoot-level schedule (3.1).
        $this->assertSame($preferredDate, $shoot->scheduled_date?->toDateString());
        $this->assertSame($preferredTime, $shoot->time);
        $this->assertNotNull($shoot->scheduled_at);
        $this->assertStringStartsWith($preferredDate, $shoot->scheduled_at->format('Y-m-d H:i'));
        $this->assertStringEndsWith($preferredTime, $shoot->scheduled_at->format('Y-m-d H:i'));

        // Pivot schedule mirrors the shoot-level schedule for the single legacy service.
        $pivot = $this->pivotForService($shoot, $service->id);
        $this->assertStringStartsWith($preferredDate, (string) $pivot->scheduled_at);

        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);
        $this->assertNoAssignmentReviewNotification($shoot->id);
    }

    /**
     * 3.2 — payload with no scheduling: STATUS_REQUESTED with null scheduling fields.
     */
    public function test_no_scheduling_creates_requested_shoot_with_null_schedule(): void
    {
        [$service] = $this->makeServices(1);

        $payload = $this->basePayload([$service]); // no preferred_date / preferred_time

        $this->assertFalse($this->isBugCondition($payload), 'precondition: must NOT be a bug condition');

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);
        $this->assertNull($shoot->scheduled_at, 'no scheduling → scheduled_at null');
        $this->assertNull($shoot->scheduled_date, 'no scheduling → scheduled_date null');
        $this->assertNull($shoot->time, 'no scheduling → time null');

        $pivot = $this->pivotForService($shoot, $service->id);
        $this->assertNull($pivot->scheduled_at, 'no scheduling → pivot scheduled_at null');

        $this->assertNoAssignmentReviewNotification($shoot->id);
    }

    /**
     * 3.3 / 3.4 / 3.5 / 3.9 — existing-fields-only payload preserves client find/create,
     * pricing, service attachment with catalog prices, legacy service_id, property_details,
     * source, payment/product status, the shoot_requested activity log, and the
     * ProcessExternalShootRequestedJob dispatch.
     */
    public function test_existing_fields_only_preserves_client_pricing_service_source_status_activity_and_job(): void
    {
        $services = $this->makeServices(2);
        $email = 'preserve-existing-' . uniqid() . '@example.com';

        $payload = $this->basePayload($services, [
            'client_email' => $email,
            'source' => 'lovable',
            'sqft' => 2200,
            'bedrooms' => 4,
            'bathrooms' => 2.5,
            // legacy multi-service booking carries NO scheduling intent, so it is not a bug condition
        ]);

        $this->assertFalse($this->isBugCondition($payload), 'precondition: must NOT be a bug condition');

        $response = $this->postBooking($payload);
        $response->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.is_new_client', true)
            ->assertJsonPath('data.account_created', true);

        $shootId = (int) $response->json('data.shoot_id');
        $shoot = $this->loadShoot($shootId);

        // 3.3 — client find-or-create by email, role client.
        $client = User::query()->where('email', $email)->first();
        $this->assertNotNull($client, 'client must be found-or-created by email');
        $this->assertSame('client', $client->role);
        $this->assertSame($client->id, $shoot->client_id);

        // 3.4 — pricing computed and services attached with catalog prices.
        $expectedSubtotal = self::SERVICE_PRICE * count($services);
        $this->assertEqualsWithDelta($expectedSubtotal, (float) $shoot->base_quote, 0.001, 'base_quote = sum of catalog prices');
        $this->assertNotNull($shoot->total_quote);
        $this->assertGreaterThan(0, (float) $shoot->total_quote);
        foreach ($services as $service) {
            $pivot = $this->pivotForService($shoot, $service->id);
            $this->assertNotNull($pivot, 'each selected service is attached');
            $this->assertEqualsWithDelta(self::SERVICE_PRICE, (float) $pivot->price, 0.001, 'pivot carries the catalog price');
        }

        // 3.5 — legacy service_id, property_details, source, payment/product status.
        $this->assertSame($services[0]->id, $shoot->service_id, 'legacy service_id = first service');
        $this->assertSame('External (lovable)', $shoot->created_by);
        $this->assertSame('External (lovable)', $shoot->updated_by);
        $this->assertSame('unpaid', $shoot->payment_status);
        $this->assertSame(Shoot::PRODUCT_STATUS_HAS_PRODUCT, $shoot->product_status);
        $this->assertIsArray($shoot->property_details);
        $this->assertSame(2200, (int) ($shoot->property_details['sqft'] ?? null));
        $this->assertSame(4, (int) ($shoot->property_details['bedrooms'] ?? null));

        // 3.5 — shoot_requested activity log recorded.
        $this->assertTrue(
            ShootActivityLog::query()
                ->where('shoot_id', $shootId)
                ->where('action', 'shoot_requested')
                ->exists(),
            'shoot_requested activity log must be recorded'
        );

        // 3.9 — ProcessExternalShootRequestedJob dispatched (after commit), unchanged.
        Queue::assertPushed(
            ProcessExternalShootRequestedJob::class,
            fn (ProcessExternalShootRequestedJob $job) => $job->shootId === $shootId && $job->afterCommit === true
        );

        // 3.7 / 3.8 — no review notification for an existing-fields-only payload.
        $this->assertNoAssignmentReviewNotification($shootId);
    }

    /**
     * 3.6 — no photographer specified: none assigned, and no default/incorrect photographer.
     */
    public function test_no_photographer_specified_assigns_none(): void
    {
        $services = $this->makeServices(2);

        $payload = $this->basePayload($services); // no photographer fields at all

        $this->assertFalse($this->isBugCondition($payload), 'precondition: must NOT be a bug condition');

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        $this->assertNull($shoot->photographer_id, 'shoot-level photographer must stay null');
        foreach ($services as $service) {
            $pivot = $this->pivotForService($shoot, $service->id);
            $this->assertNull($pivot->photographer_id, 'no service pivot may be assigned a photographer');
        }
    }

    /**
     * 3.7 / 3.8 — existing-fields-only payload (preferred date+time only) is accepted,
     * processed without error, and creates NO shoot_assignment_review notification.
     */
    public function test_existing_fields_only_creates_no_assignment_review_notification(): void
    {
        [$service] = $this->makeServices(1);

        $payload = $this->basePayload([$service], [
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '11:00',
        ]);

        $this->assertFalse($this->isBugCondition($payload), 'precondition: must NOT be a bug condition');

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shootId = (int) $response->json('data.shoot_id');

        $this->assertNoAssignmentReviewNotification($shootId);
    }

    // ---------------------------------------------------------------------
    // Scoped property-based generator over the legacy (non-bug-condition) input domain.
    // ---------------------------------------------------------------------

    /**
     * Property 2: for ALL legacy-shaped (non-bug-condition) inputs, the created shoot is
     * field-for-field consistent with the legacy behavior. A seeded generator walks the
     * legacy subspace: existing fields only, ≤1 service-with-time, and either
     * (single service + preferred date+time), (single service + no scheduling), or
     * (multiple services + no scheduling at all).
     */
    public function test_property_legacy_payloads_unaffected(): void
    {
        mt_srand(20260222); // deterministic / reproducible

        $servicePool = $this->makeServices(3);

        $iterations = 8;
        $checked = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // shape: 0 = single service + preferred date+time
            //        1 = single service + no scheduling
            //        2 = multiple services + no scheduling
            $shape = mt_rand(0, 2);

            if ($shape === 0) {
                $services = array_slice($servicePool, 0, 1);
                $preferredDate = now()->addDays(3 + $i)->toDateString();
                $preferredTime = sprintf('%02d:%02d', 8 + ($i % 8), ($i % 2) * 30);
                $overrides = ['preferred_date' => $preferredDate, 'preferred_time' => $preferredTime];
            } elseif ($shape === 1) {
                $services = array_slice($servicePool, 0, 1);
                $preferredDate = null;
                $preferredTime = null;
                $overrides = [];
            } else {
                $services = array_slice($servicePool, 0, mt_rand(2, 3));
                $preferredDate = null;
                $preferredTime = null;
                $overrides = [];
            }

            $payload = $this->basePayload($services, $overrides);

            // Guard: only exercise genuinely legacy-shaped inputs.
            $this->assertFalse(
                $this->isBugCondition($payload),
                "generated payload must NOT be a bug condition [shape={$shape}]"
            );
            $checked++;

            $example = "shape={$shape}, services=" . count($services)
                . ', scheduled=' . ($preferredDate ? 'yes' : 'no');

            $response = $this->postBooking($payload);
            $this->assertSame(201, $response->status(), "booking should succeed for [$example]");

            $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

            // Always a requested shoot (3.2).
            $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status, "status must be requested for [$example]");

            // Legacy service_id = first selected service (3.5).
            $this->assertSame($services[0]->id, $shoot->service_id, "legacy service_id mismatch for [$example]");

            // Pricing: base_quote = sum of catalog prices; pivots carry catalog price (3.4).
            $expectedSubtotal = self::SERVICE_PRICE * count($services);
            $this->assertEqualsWithDelta(
                $expectedSubtotal,
                (float) $shoot->base_quote,
                0.001,
                "base_quote must equal the catalog subtotal for [$example]"
            );
            foreach ($services as $service) {
                $pivot = $this->pivotForService($shoot, $service->id);
                $this->assertNotNull($pivot, "service must be attached for [$example]");
                $this->assertEqualsWithDelta(
                    self::SERVICE_PRICE,
                    (float) $pivot->price,
                    0.001,
                    "pivot must carry the catalog price for [$example]"
                );
                // No photographer ever assigned for a legacy payload (3.6).
                $this->assertNull($pivot->photographer_id, "pivot photographer must be null for [$example]");
            }

            // Shoot-level photographer stays null (3.6).
            $this->assertNull($shoot->photographer_id, "shoot photographer must be null for [$example]");

            // Schedule derivation (3.1) vs null scheduling (3.2).
            if ($preferredDate !== null) {
                $this->assertSame($preferredDate, $shoot->scheduled_date?->toDateString(), "scheduled_date for [$example]");
                $this->assertSame($preferredTime, $shoot->time, "time for [$example]");
                $this->assertNotNull($shoot->scheduled_at, "scheduled_at must be derived for [$example]");
                $this->assertStringStartsWith($preferredDate, $shoot->scheduled_at->format('Y-m-d H:i'), "scheduled_at date for [$example]");
            } else {
                $this->assertNull($shoot->scheduled_at, "scheduled_at must be null for [$example]");
                $this->assertNull($shoot->scheduled_date, "scheduled_date must be null for [$example]");
                $this->assertNull($shoot->time, "time must be null for [$example]");
            }

            // shoot_requested logged, no review notification (3.5, 3.7, 3.8).
            $this->assertTrue(
                ShootActivityLog::query()
                    ->where('shoot_id', $shoot->id)
                    ->where('action', 'shoot_requested')
                    ->exists(),
                "shoot_requested activity log must exist for [$example]"
            );
            $this->assertNoAssignmentReviewNotification($shoot->id);
        }

        $this->assertGreaterThan(0, $checked, 'generator must exercise at least one legacy-shaped input');
    }
}
