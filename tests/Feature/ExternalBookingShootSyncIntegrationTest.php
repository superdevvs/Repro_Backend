<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalShootRequestedJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\User;
use App\Services\ExternalBooking\MappingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for the full POST /api/external/book-shoot flow (Task 8).
 *
 * These exercise the controller end to end through the real mapping pipeline (DTO →
 * normalizer → auto-mapper → warning builder → notification service) and the persistence
 * layer, asserting the created shoot, the `shoot_service` pivot rows, the new metadata
 * columns, and — when review is needed — the `shoot_assignment_review` `ShootActivityLog`
 * row. They also assert the dashboard notifications endpoint surfaces the review
 * notification to admins/superadmins with the correct `action_type`/`action_payload`, and
 * that a legacy existing-fields-only payload behaves exactly as before with NO notification.
 *
 * Photographer cases (S=services, P=photographers):
 *   A  S=1,P=1  -> pivot photographer set + legacy shoot photographer set, fully mapped
 *   B  S=1,P=0  -> unassigned (legacy, no review)
 *   C  S=1,P=2  -> unassigned, requested_photographers persisted, warning, needs review
 *   D  S=2,P=1  -> unassigned by default, warning, needs review
 *   E  S=2,P=2  -> all null, warning, needs review
 *
 * Schedule cases:
 *   1  S=1, preferred date+time          -> shoot + pivot scheduled
 *   2  S=3, preferred + alternate        -> s1 preferred, s2 alternate, s3 unscheduled
 *   3  S=2, preferred only               -> s1 preferred, s2 unscheduled
 *   4  explicit service_assignments      -> applied per service
 *
 * Validates: Requirements 2.17, 2.19, 2.20, 2.21, 2.22, 3.7
 */
class ExternalBookingShootSyncIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'external-booking-test-key';

    private const SERVICE_PRICE = 150.00;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.external_booking.api_key' => self::API_KEY]);

        // Isolate the booking from the queued side-effects job (still assertable below).
        Queue::fake();
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
            'client_name' => 'Integration Client',
            'client_email' => 'integration-' . uniqid() . '@example.com',
            'client_phone' => '2025550123',
            'address' => '123 Integration Ave',
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

    private function reviewLogFor(int $shootId): ?ShootActivityLog
    {
        return ShootActivityLog::query()
            ->where('shoot_id', $shootId)
            ->where('action', 'shoot_assignment_review')
            ->first();
    }

    private function assertReviewNotificationCreated(int $shootId): ShootActivityLog
    {
        $log = $this->reviewLogFor($shootId);
        $this->assertNotNull($log, "a shoot_assignment_review log must exist for shoot #{$shootId}");

        return $log;
    }

    private function assertNoReviewNotification(int $shootId): void
    {
        $this->assertNull(
            $this->reviewLogFor($shootId),
            "no shoot_assignment_review log should exist for shoot #{$shootId}"
        );
    }

    // =====================================================================
    // Photographer cases A–E
    // =====================================================================

    /** Case A — 1 service + 1 photographer: pivot + legacy photographer set, fully mapped. */
    public function test_case_a_single_service_single_photographer_assigns_pivot_and_legacy(): void
    {
        [$service] = $this->makeServices(1);
        [$photographer] = $this->makePhotographers(1);

        $payload = $this->basePayload([$service], [
            'preferred_date' => now()->addDays(5)->toDateString(),
            'preferred_time' => '10:00',
            'selected_photographers' => [$photographer->id],
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));
        $pivot = $this->pivotForService($shoot, $service->id);

        $this->assertSame($photographer->id, (int) $pivot->photographer_id, 'pivot photographer must be the selected one');
        $this->assertSame($photographer->id, (int) $shoot->photographer_id, 'legacy shoot photographer must be set in case A');
        $this->assertSame(MappingResult::STATUS_FULLY_MAPPED, $shoot->external_booking_mapping_status);
        $this->assertEqualsCanonicalizing([$photographer->id], array_map('intval', (array) $shoot->requested_photographers));
        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);
        $this->assertNoReviewNotification($shoot->id); // unambiguous -> no review
    }

    /** Case B — 1 service + 0 photographers: unassigned, no review. */
    public function test_case_b_single_service_no_photographer_leaves_unassigned(): void
    {
        [$service] = $this->makeServices(1);

        $payload = $this->basePayload([$service], [
            'preferred_date' => now()->addDays(5)->toDateString(),
            'preferred_time' => '09:30',
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));
        $pivot = $this->pivotForService($shoot, $service->id);

        $this->assertNull($pivot->photographer_id, 'no photographer requested -> pivot unassigned');
        $this->assertNull($shoot->photographer_id, 'no photographer requested -> legacy unassigned');
        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);
        $this->assertNoReviewNotification($shoot->id);
    }

    /** Case C — 1 service + 2 photographers: unassigned, requested persisted, warning, needs review. */
    public function test_case_c_single_service_multiple_photographers_needs_review(): void
    {
        [$service] = $this->makeServices(1);
        [$p1, $p2] = $this->makePhotographers(2);

        $payload = $this->basePayload([$service], [
            'preferred_date' => now()->addDays(6)->toDateString(),
            'preferred_time' => '11:15',
            'selected_photographers' => [$p1->id, $p2->id],
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));
        $pivot = $this->pivotForService($shoot, $service->id);

        $this->assertNull($pivot->photographer_id, 'ambiguous multi-photographer -> unassigned');
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], array_map('intval', (array) $shoot->requested_photographers));
        $this->assertContains(
            'Multiple photographers were requested for one service. Please review manually.',
            (array) $shoot->external_booking_warnings
        );
        $this->assertContains($shoot->external_booking_mapping_status, [
            MappingResult::STATUS_PARTIALLY_MAPPED,
            MappingResult::STATUS_NEEDS_REVIEW,
        ]);
        $this->assertReviewNotificationCreated($shoot->id);
    }

    /** Case D — 2 services + 1 photographer: unassigned by default, warning, needs review. */
    public function test_case_d_multiple_services_single_photographer_needs_review(): void
    {
        $services = $this->makeServices(2);
        [$photographer] = $this->makePhotographers(1);

        $payload = $this->basePayload($services, [
            'preferred_date' => now()->addDays(7)->toDateString(),
            'preferred_time' => '12:00',
            'selected_photographers' => [$photographer->id],
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        foreach ($services as $service) {
            $this->assertNull(
                $this->pivotForService($shoot, $service->id)->photographer_id,
                'no eligibility resolver -> every pivot stays unassigned in case D'
            );
        }
        $this->assertNull($shoot->photographer_id, 'legacy photographer not set in case D');
        $this->assertEqualsCanonicalizing([$photographer->id], array_map('intval', (array) $shoot->requested_photographers));
        $this->assertContains(
            'A single photographer was requested for multiple services. Assignment left for manual review.',
            (array) $shoot->external_booking_warnings
        );
        $this->assertReviewNotificationCreated($shoot->id);
    }

    /** Case E — 2 services + 2 photographers: all null, warning, needs review. */
    public function test_case_e_multiple_services_multiple_photographers_needs_review(): void
    {
        $services = $this->makeServices(2);
        [$p1, $p2] = $this->makePhotographers(2);

        $payload = $this->basePayload($services, [
            'preferred_date' => now()->addDays(8)->toDateString(),
            'preferred_time' => '13:45',
            'selected_photographers' => [$p1->id, $p2->id],
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        foreach ($services as $service) {
            $this->assertNull(
                $this->pivotForService($shoot, $service->id)->photographer_id,
                'ambiguous multi-service/multi-photographer -> all pivots null'
            );
        }
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], array_map('intval', (array) $shoot->requested_photographers));
        $this->assertContains(
            'Multiple photographers were requested across multiple services. Please assign manually.',
            (array) $shoot->external_booking_warnings
        );
        $this->assertReviewNotificationCreated($shoot->id);
    }

    // =====================================================================
    // Schedule cases 1–4
    // =====================================================================

    /** Schedule 1 — single service, preferred date+time: shoot + pivot scheduled. */
    public function test_schedule_1_single_service_preferred_date_time(): void
    {
        [$service] = $this->makeServices(1);

        $preferredDate = now()->addDays(5)->toDateString();
        $preferredTime = '10:30';

        $payload = $this->basePayload([$service], [
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));
        $pivot = $this->pivotForService($shoot, $service->id);

        $this->assertSame($preferredDate, $shoot->scheduled_date?->toDateString());
        $this->assertSame($preferredTime, $shoot->time);
        $this->assertNotNull($shoot->scheduled_at);
        $this->assertStringStartsWith($preferredDate, (string) $pivot->scheduled_at, 'pivot scheduled at preferred');
        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);
    }

    /**
     * Schedule 2 — 3 services, preferred + alternate: s1 preferred, s2/s3 unscheduled.
     *
     * New contract (shoot-alternate-date-field): the alternate is no longer mapped onto
     * service #2. It persists only on the shoot's alternate_scheduled_at; services #2..N
     * are left unscheduled by the alternate.
     */
    public function test_schedule_2_preferred_and_alternate_persists_alternate_on_shoot(): void
    {
        $services = $this->makeServices(3);

        $preferredDate = now()->addDays(7)->toDateString();
        $alternateDate = now()->addDays(8)->toDateString();

        $payload = $this->basePayload($services, [
            'preferred_date' => $preferredDate,
            'preferred_time' => '09:00',
            'alternate_date' => $alternateDate,
            'alternate_time' => '13:00',
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        $firstPivot = $this->pivotForService($shoot, $services[0]->id);
        $secondPivot = $this->pivotForService($shoot, $services[1]->id);
        $thirdPivot = $this->pivotForService($shoot, $services[2]->id);

        $this->assertStringStartsWith($preferredDate, (string) $firstPivot->scheduled_at, 's1 at preferred');
        $this->assertNull($secondPivot->scheduled_at, 's2 left unscheduled (alternate no longer maps to service #2)');
        $this->assertNull($thirdPivot->scheduled_at, 's3 left unscheduled');

        // The alternate is persisted on the shoot's alternate field instead.
        $this->assertNotNull($shoot->alternate_scheduled_at, 'alternate_scheduled_at persisted on the shoot');
        $this->assertStringStartsWith(
            $alternateDate,
            (string) $shoot->alternate_scheduled_at,
            'alternate_scheduled_at equals the booking alternate date'
        );

        $warnings = (array) $shoot->external_booking_warnings;
        $this->assertContains(
            'Service #2 could not be scheduled automatically and needs manual scheduling.',
            $warnings
        );
        $this->assertContains(
            'Service #3 could not be scheduled automatically and needs manual scheduling.',
            $warnings
        );
        $this->assertReviewNotificationCreated($shoot->id);
    }

    /** Schedule 3 — 2 services, preferred only: s1 preferred, s2 unscheduled (no copy-to-all). */
    public function test_schedule_3_preferred_only_does_not_copy_to_all(): void
    {
        $services = $this->makeServices(2);

        $preferredDate = now()->addDays(9)->toDateString();

        $payload = $this->basePayload($services, [
            'preferred_date' => $preferredDate,
            'preferred_time' => '14:00',
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        $firstPivot = $this->pivotForService($shoot, $services[0]->id);
        $secondPivot = $this->pivotForService($shoot, $services[1]->id);

        $this->assertStringStartsWith($preferredDate, (string) $firstPivot->scheduled_at, 's1 at preferred');
        $this->assertNull($secondPivot->scheduled_at, 's2 must NOT inherit the preferred schedule');
        $this->assertContains(
            'Service #2 could not be scheduled automatically and needs manual scheduling.',
            (array) $shoot->external_booking_warnings
        );
        $this->assertReviewNotificationCreated($shoot->id);
    }

    /** Schedule 4 — explicit service_assignments applied per service. */
    public function test_schedule_4_explicit_service_assignments_applied_per_service(): void
    {
        $services = $this->makeServices(2);
        [$p1, $p2] = $this->makePhotographers(2);

        $dateOne = now()->addDays(10)->toDateString();
        $dateTwo = now()->addDays(11)->toDateString();

        $payload = $this->basePayload($services, [
            'service_assignments' => [
                [
                    'service_id' => $services[0]->id,
                    'photographer_id' => $p1->id,
                    'scheduled_date' => $dateOne,
                    'scheduled_time' => '08:00',
                ],
                [
                    'service_id' => $services[1]->id,
                    'photographer_id' => $p2->id,
                    'scheduled_date' => $dateTwo,
                    'scheduled_time' => '16:00',
                ],
            ],
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();

        $shoot = $this->loadShoot((int) $response->json('data.shoot_id'));

        $firstPivot = $this->pivotForService($shoot, $services[0]->id);
        $secondPivot = $this->pivotForService($shoot, $services[1]->id);

        $this->assertSame($p1->id, (int) $firstPivot->photographer_id, 's1 explicit photographer applied');
        $this->assertSame($p2->id, (int) $secondPivot->photographer_id, 's2 explicit photographer applied');
        $this->assertStringStartsWith($dateOne, (string) $firstPivot->scheduled_at, 's1 explicit schedule applied');
        $this->assertStringStartsWith($dateTwo, (string) $secondPivot->scheduled_at, 's2 explicit schedule applied');
        $this->assertSame(MappingResult::STATUS_FULLY_MAPPED, $shoot->external_booking_mapping_status);
        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);
    }

    // =====================================================================
    // Dashboard notifications endpoint + role visibility (2.19, 2.20, 2.21)
    // =====================================================================

    /**
     * A needs-review booking surfaces in GET /api/notifications for admins/superadmins with
     * the correct action_type / action_payload metadata, and is hidden from clients.
     */
    public function test_needs_review_notification_visible_to_admin_with_action_metadata(): void
    {
        [$service] = $this->makeServices(1);
        [$p1, $p2] = $this->makePhotographers(2);

        // Case C => unambiguously needs review.
        $payload = $this->basePayload([$service], [
            'preferred_date' => now()->addDays(6)->toDateString(),
            'preferred_time' => '11:15',
            'selected_photographers' => [$p1->id, $p2->id],
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated();
        $shootId = (int) $response->json('data.shoot_id');

        $this->assertReviewNotificationCreated($shootId);

        // Admin sees it with the structured metadata.
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $adminResponse = $this->getJson('/api/notifications');
        $adminResponse->assertOk()->assertJsonPath('data.user_role', 'admin');

        $review = collect($adminResponse->json('data.activity_log'))
            ->firstWhere('action', 'shoot_assignment_review');

        $this->assertNotNull($review, 'admin must see the shoot_assignment_review notification');
        $this->assertSame($shootId, (int) $review['shootId']);
        $this->assertSame('shoot_assignment_review', $review['metadata']['type']);
        $this->assertSame('open_shoot_details_popup', $review['metadata']['action_type']);
        $this->assertSame($shootId, (int) $review['metadata']['action_payload']['shoot_id']);
        $this->assertSame('schedule_assignments', $review['metadata']['action_payload']['focus']);
        $this->assertSame('Booking Needs Review', $review['metadata']['title']);

        // Super Admin also sees it (scheduling-review role group).
        $superAdmin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($superAdmin);

        $superResponse = $this->getJson('/api/notifications');
        $superResponse->assertOk();
        $this->assertNotNull(
            collect($superResponse->json('data.activity_log'))->firstWhere('action', 'shoot_assignment_review'),
            'superadmin must see the shoot_assignment_review notification'
        );

        // A client must NOT see another shoot's review notification.
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);

        $clientResponse = $this->getJson('/api/notifications');
        $clientResponse->assertOk();
        $this->assertNull(
            collect($clientResponse->json('data.activity_log'))->firstWhere('action', 'shoot_assignment_review'),
            'client must NOT see the review notification'
        );
    }

    // =====================================================================
    // Backward compatibility (3.7)
    // =====================================================================

    /**
     * A legacy existing-fields-only payload produces the same shoot/response as before, with
     * NO shoot_assignment_review notification created.
     */
    public function test_legacy_existing_fields_only_payload_creates_no_review_notification(): void
    {
        [$service] = $this->makeServices(1);

        $preferredDate = now()->addDays(3)->toDateString();
        $preferredTime = '11:00';

        $payload = $this->basePayload([$service], [
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
        ]);

        $response = $this->postBooking($payload);
        $response->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.is_new_client', true)
            ->assertJsonPath('data.account_created', true);

        $shootId = (int) $response->json('data.shoot_id');
        $shoot = $this->loadShoot($shootId);

        // Same shoot as before: derived schedule, requested status, legacy service_id.
        $this->assertSame($preferredDate, $shoot->scheduled_date?->toDateString());
        $this->assertSame($preferredTime, $shoot->time);
        $this->assertNotNull($shoot->scheduled_at);
        $this->assertSame($service->id, $shoot->service_id);
        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);
        $this->assertNull($shoot->photographer_id);

        // Side-effects job still dispatched unchanged.
        Queue::assertPushed(
            ProcessExternalShootRequestedJob::class,
            fn (ProcessExternalShootRequestedJob $job) => $job->shootId === $shootId && $job->afterCommit === true
        );

        // No review notification for a legacy payload (3.7).
        $this->assertNoReviewNotification($shootId);

        // And the admin notifications feed contains no review entry for it.
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $adminResponse = $this->getJson('/api/notifications');
        $adminResponse->assertOk();
        $this->assertNull(
            collect($adminResponse->json('data.activity_log'))->firstWhere('action', 'shoot_assignment_review'),
            'legacy booking must not produce a review notification in the dashboard feed'
        );
    }
}
