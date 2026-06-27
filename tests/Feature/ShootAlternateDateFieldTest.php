<?php

namespace Tests\Feature;

use App\Http\Resources\ShootResource;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\ExternalBooking\ExternalBookingAutoMapper;
use App\Services\ExternalBooking\NormalizedBooking;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\PhotographerAvailabilityService;
use App\Services\Shoots\ShootEditablePayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Focused correctness-property tests for the shoot-alternate-date-field feature.
 *
 * Each test maps to a numbered design property (Correctness Properties 1–6) and a tasks.md
 * item (8.1–8.6). Where a property spans a domain we use a seeded deterministic generator
 * (the repo has no PHP PBT library beyond faker), mirroring the scoped-PBT approach in
 * ExternalBookingAutoMapperTest. Concrete cases are used where the behavior is deterministic.
 *
 * Existing coverage that this class deliberately does NOT duplicate:
 *   - ShootResourceExternalBookingTest  (resource serialization examples)
 *   - ExternalBookingAutoMapperTest     (mapper schedule decision table)
 *   - ShootExternalBookingColumnsTest   (fillable/cast round-trip)
 * This class adds the explicit per-property guarantees those example tests do not assert
 * universally (formatter equivalence with the main schedule, alternate-never-on-a-service
 * across the service-count domain, the write-path no-fabricated-time rule, service
 * independence across mapper/modify/approve, and the modify/approve round-trip).
 *
 * Feature: shoot-alternate-date-field
 */
class ShootAlternateDateFieldTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected User $photographer;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindSideEffectFakes();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Alt Admin',
            'email' => 'alt-admin@test.com',
        ]);
        $this->client = User::factory()->create([
            'role' => 'client',
            'name' => 'Alt Client',
            'email' => 'alt-client@test.com',
        ]);
        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Alt Photographer',
            'email' => 'alt-photographer@test.com',
        ]);
        $this->service = Service::factory()->create([
            'name' => 'Alt HDR Photos',
            'price' => 150.00,
        ]);
    }

    // ==================================================================
    // 8.1 — Property 1: Alternate serialization formatting and emptiness
    // Validates: Requirements 1.2, 1.3
    // ==================================================================

    /**
     * Feature: shoot-alternate-date-field, Property 1: Alternate serialization formatting
     * and emptiness.
     *
     * For any shoot, ShootResource exposes alternate_scheduled_date (Y-m-d), alternate_time
     * (string), and alternate_scheduled_at (ISO-8601) using the SAME formatters as the main
     * schedule when the alternate is set, and returns null for all three when it is empty.
     *
     * Validates: Requirements 1.2, 1.3
     */
    #[Test]
    public function property_1_alternate_serialization_formatting_and_emptiness(): void
    {
        mt_srand(20260301);

        $dates = ['2026-03-01', '2026-07-04', '2026-12-31', '2027-01-15'];
        $times = ['09:00', '14:30', '23:45'];

        for ($i = 0; $i < 12; $i++) {
            $present = (bool) mt_rand(0, 1);

            if ($present) {
                $date = $dates[array_rand($dates)];
                $time = $times[array_rand($times)];
                $at = "{$date} {$time}:00";

                // Point the MAIN schedule at the same underlying values so we can prove the
                // alternate serializer uses the identical formatter as scheduledDate/scheduledAt.
                $shoot = Shoot::factory()->create([
                    'client_id' => $this->client->id,
                    'scheduled_date' => $date,
                    'time' => $time,
                    'scheduled_at' => $at,
                    'alternate_scheduled_date' => $date,
                    'alternate_time' => $time,
                    'alternate_scheduled_at' => $at,
                ]);

                $array = (new ShootResource($shoot->fresh()))->toArray(Request::create('/'));
                $example = "iter={$i}, date={$date}, time={$time}";

                // Y-m-d date formatting, identical to the main scheduledDate formatter.
                $this->assertSame($date, $array['alternate_scheduled_date'], "DATE [$example]");
                $this->assertSame($array['scheduledDate'], $array['alternate_scheduled_date'], "DATE-FORMATTER [$example]");
                $this->assertSame($array['alternate_scheduled_date'], $array['alternateScheduledDate'], "DATE-ALIAS [$example]");

                // Plain string time.
                $this->assertSame($time, $array['alternate_time'], "TIME [$example]");
                $this->assertSame($array['alternate_time'], $array['alternateTime'], "TIME-ALIAS [$example]");

                // ISO-8601 datetime formatting, identical to the main scheduledAt formatter.
                $this->assertSame(
                    $shoot->alternate_scheduled_at->toIso8601String(),
                    $array['alternate_scheduled_at'],
                    "AT [$example]"
                );
                $this->assertSame($array['scheduledAt'], $array['alternate_scheduled_at'], "AT-FORMATTER [$example]");
                $this->assertSame($array['alternate_scheduled_at'], $array['alternateScheduledAt'], "AT-ALIAS [$example]");
            } else {
                $shoot = Shoot::factory()->create([
                    'client_id' => $this->client->id,
                    'alternate_scheduled_date' => null,
                    'alternate_time' => null,
                    'alternate_scheduled_at' => null,
                ]);

                $array = (new ShootResource($shoot->fresh()))->toArray(Request::create('/'));
                $example = "iter={$i}, empty";

                $this->assertNull($array['alternate_scheduled_date'], "EMPTY-DATE [$example]");
                $this->assertNull($array['alternateScheduledDate'], "EMPTY-DATE-ALIAS [$example]");
                $this->assertNull($array['alternate_time'], "EMPTY-TIME [$example]");
                $this->assertNull($array['alternateTime'], "EMPTY-TIME-ALIAS [$example]");
                $this->assertNull($array['alternate_scheduled_at'], "EMPTY-AT [$example]");
                $this->assertNull($array['alternateScheduledAt'], "EMPTY-AT-ALIAS [$example]");
            }
        }
    }

    // ==================================================================
    // 8.2 — Property 2: External booking maps the alternate to the alternate field only
    // Validates: Requirements 2.1, 2.3, 9.1
    // ==================================================================

    /**
     * Feature: shoot-alternate-date-field, Property 2: External booking maps the alternate
     * to the alternate field only.
     *
     * For any external booking that includes an alternate date, the auto-mapper writes that
     * value only into alternateSchedule and never assigns it to any service's scheduled_at.
     *
     * Validates: Requirements 2.1, 2.3, 9.1
     */
    #[Test]
    public function property_2_external_booking_maps_alternate_to_alternate_field_only(): void
    {
        mt_srand(20260302);

        $mapper = new ExternalBookingAutoMapper();
        $servicePool = [101, 102, 103];

        for ($i = 0; $i < 12; $i++) {
            // Alternate is always present for this property; vary service count and the
            // presence of a preferred date (cases 2 and 3).
            $s = mt_rand(1, 3);
            $withPreferred = (bool) mt_rand(0, 1);
            $altDate = '2026-05-1' . mt_rand(0, 9);
            $altTime = sprintf('%02d:%02d', mt_rand(8, 18), [0, 15, 30, 45][mt_rand(0, 3)]);
            $altAt = "{$altDate} {$altTime}:00";

            $serviceIds = array_slice($servicePool, 0, $s);
            $booking = new NormalizedBooking(
                preferred: ['date' => $withPreferred ? '2026-05-01' : null, 'time' => $withPreferred ? '09:00' : null],
                alternate: ['date' => $altDate, 'time' => $altTime],
                requested_photographers: [],
                selected_services: array_map(fn ($id) => ['id' => $id, 'quantity' => 1], $serviceIds),
                service_assignments: [],
            );

            $result = $mapper->map($booking);
            $example = "iter={$i}, S={$s}, pref=" . ($withPreferred ? 'y' : 'n') . ", altAt={$altAt}";

            // (a) The alternate persists into the shoot-level alternate field, intact.
            $this->assertSame($altDate, $result->alternateSchedule['alternate_scheduled_date'], "ALT-DATE [$example]");
            $this->assertSame($altTime, $result->alternateSchedule['alternate_time'], "ALT-TIME [$example]");
            $this->assertSame($altAt, $result->alternateSchedule['alternate_scheduled_at'], "ALT-AT [$example]");

            // (b) The alternate is NEVER assigned to ANY service's scheduled_at.
            foreach ($result->serviceAssignments as $serviceId => $assignment) {
                $this->assertNotSame(
                    $altAt,
                    $assignment['scheduled_at'],
                    "ALT-ON-SERVICE [$example]: alternate leaked onto service {$serviceId}."
                );
            }
        }
    }

    // ==================================================================
    // 8.3 — Property 3: Preferred date maps to the main schedule
    // Validates: Requirements 2.2
    // ==================================================================

    /**
     * Feature: shoot-alternate-date-field, Property 3: Preferred date maps to the main
     * schedule.
     *
     * For any external booking with a preferred date, the resulting shoot schedule
     * (scheduled_date/time/scheduled_at) is derived from the preferred date and time.
     *
     * Validates: Requirements 2.2
     */
    #[Test]
    public function property_3_preferred_date_maps_to_main_schedule(): void
    {
        mt_srand(20260303);

        $mapper = new ExternalBookingAutoMapper();
        $servicePool = [201, 202, 203];

        for ($i = 0; $i < 12; $i++) {
            $s = mt_rand(1, 3);
            $prefDate = '2026-06-0' . mt_rand(1, 9);
            $prefTime = sprintf('%02d:%02d', mt_rand(8, 18), [0, 30][mt_rand(0, 1)]);
            $prefAt = "{$prefDate} {$prefTime}:00";
            // Vary whether an alternate is present — it must not affect the main schedule.
            $withAlt = (bool) mt_rand(0, 1);

            $serviceIds = array_slice($servicePool, 0, $s);
            $booking = new NormalizedBooking(
                preferred: ['date' => $prefDate, 'time' => $prefTime],
                alternate: ['date' => $withAlt ? '2026-06-20' : null, 'time' => $withAlt ? '15:00' : null],
                requested_photographers: [],
                selected_services: array_map(fn ($id) => ['id' => $id, 'quantity' => 1], $serviceIds),
                service_assignments: [],
            );

            $result = $mapper->map($booking);
            $example = "iter={$i}, S={$s}, prefAt={$prefAt}, alt=" . ($withAlt ? 'y' : 'n');

            $this->assertSame($prefDate, $result->shootSchedule['scheduled_date'], "MAIN-DATE [$example]");
            $this->assertSame($prefTime, $result->shootSchedule['time'], "MAIN-TIME [$example]");
            $this->assertSame($prefAt, $result->shootSchedule['scheduled_at'], "MAIN-AT [$example]");

            // The first service always carries the preferred schedule (subject to time present).
            $firstId = $serviceIds[0];
            $this->assertSame($prefAt, $result->serviceAssignments[$firstId]['scheduled_at'], "FIRST-SERVICE [$example]");
        }
    }

    // ==================================================================
    // 8.4 — Property 4: No-fabricated-time rule for the alternate
    // Validates: Requirements 2.4
    // ==================================================================

    /**
     * Feature: shoot-alternate-date-field, Property 4: No-fabricated-time rule for the
     * alternate.
     *
     * For any alternate date provided WITHOUT a time, both the mapper AND the
     * modify/approve write path (ShootEditablePayloadService) store the alternate date while
     * leaving alternate_time and alternate_scheduled_at null.
     *
     * Validates: Requirements 2.4
     */
    #[Test]
    public function property_4_no_fabricated_time_rule_for_the_alternate(): void
    {
        mt_srand(20260304);

        $mapper = new ExternalBookingAutoMapper();
        $payloadService = app(ShootEditablePayloadService::class);
        $dates = ['2026-03-02', '2026-08-09', '2026-11-23'];

        for ($i = 0; $i < 10; $i++) {
            $altDate = $dates[array_rand($dates)];
            $example = "iter={$i}, altDate={$altDate}";

            // --- (a) Mapper path ---
            $booking = new NormalizedBooking(
                preferred: ['date' => '2026-03-01', 'time' => '09:00'],
                alternate: ['date' => $altDate, 'time' => null],
                requested_photographers: [],
                selected_services: [['id' => 301, 'quantity' => 1], ['id' => 302, 'quantity' => 1]],
                service_assignments: [],
            );
            $result = $mapper->map($booking);

            $this->assertSame($altDate, $result->alternateSchedule['alternate_scheduled_date'], "MAP-DATE [$example]");
            $this->assertNull($result->alternateSchedule['alternate_time'], "MAP-TIME [$example]");
            $this->assertNull($result->alternateSchedule['alternate_scheduled_at'], "MAP-AT [$example]");
            $this->assertTrue($result->flags['alternateDateMissingTime'], "MAP-FLAG [$example]");

            // --- (b) Write-path (ShootEditablePayloadService::apply) ---
            $shoot = Shoot::factory()->create([
                'client_id' => $this->client->id,
                'alternate_scheduled_date' => null,
                'alternate_time' => null,
                'alternate_scheduled_at' => null,
            ]);

            $payloadService->apply($shoot, [
                'alternate_scheduled_date' => $altDate,
                'alternate_time' => null,
            ]);
            $fresh = $shoot->fresh();

            $this->assertSame($altDate, $fresh->alternate_scheduled_date->toDateString(), "WRITE-DATE [$example]");
            $this->assertNull($fresh->alternate_time, "WRITE-TIME [$example]");
            $this->assertNull($fresh->alternate_scheduled_at, "WRITE-AT [$example]");
        }
    }

    // ==================================================================
    // 8.5 — Property 5: Setting the alternate never moves a service
    // Validates: Requirements 3.1, 3.2
    // ==================================================================

    /**
     * Feature: shoot-alternate-date-field, Property 5: Setting the alternate never moves a
     * service.
     *
     * For any shoot, setting or updating the alternate field (via the auto-mapper, the modify
     * form, or the approve flow) leaves every shoot_service.scheduled_at unchanged.
     *
     * Validates: Requirements 3.1, 3.2
     */
    #[Test]
    public function property_5_setting_the_alternate_never_moves_a_service(): void
    {
        // (a) Mapper: the alternate is never written onto a service (covered structurally by
        //     Property 2; re-asserted here on a concrete multi-service booking).
        $mapper = new ExternalBookingAutoMapper();
        $result = $mapper->map(new NormalizedBooking(
            preferred: ['date' => '2026-04-01', 'time' => '09:00'],
            alternate: ['date' => '2026-04-02', 'time' => '13:00'],
            requested_photographers: [],
            selected_services: [['id' => 401, 'quantity' => 1], ['id' => 402, 'quantity' => 1]],
            service_assignments: [],
        ));
        $this->assertNull($result->serviceAssignments[402]['scheduled_at'], 'MAP: alternate moved service #2.');
        $this->assertSame('2026-04-02 13:00:00', $result->alternateSchedule['alternate_scheduled_at']);

        $originalServiceAt = '2026-04-10 08:00:00';

        // (b) Write path: ShootEditablePayloadService::apply with alternate fields.
        $shootA = $this->makeShootWithScheduledService($originalServiceAt);
        app(ShootEditablePayloadService::class)->apply($shootA, [
            'alternate_scheduled_date' => '2026-09-09',
            'alternate_time' => '16:00',
        ]);
        $this->assertServicePivotScheduledAtUnchanged($shootA, $originalServiceAt, 'WRITE-PATH');

        // (c) Modify endpoint (PATCH /shoots/{id} -> UpdateShootAction).
        $shootB = $this->makeShootWithScheduledService($originalServiceAt);
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/shoots/{$shootB->id}", [
            'alternate_scheduled_date' => '2026-09-10',
            'alternate_time' => '10:30',
        ])->assertOk();
        $this->assertServicePivotScheduledAtUnchanged($shootB, $originalServiceAt, 'MODIFY');

        // (d) Approve endpoint (POST /shoots/{id}/approve -> ApproveShootAction).
        $shootC = $this->makeShootWithScheduledService($originalServiceAt, Shoot::STATUS_REQUESTED);
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/shoots/{$shootC->id}/approve", [
            'scheduled_at' => now()->addDays(5)->setTime(11, 0)->format('Y-m-d H:i:s'),
            'photographer_id' => $this->photographer->id,
            'alternate_scheduled_date' => '2026-09-11',
            'alternate_time' => '12:15',
        ])->assertOk();
        $this->assertServicePivotScheduledAtUnchanged($shootC, $originalServiceAt, 'APPROVE');
    }

    // ==================================================================
    // 8.6 — Property 6: Modify/approve persist the alternate (round-trip)
    // Validates: Requirements 4.2, 4.3
    // ==================================================================

    /**
     * Feature: shoot-alternate-date-field, Property 6: Modify/approve persist the alternate
     * (round-trip).
     *
     * For any alternate date/time submitted through the modify form or the approve flow,
     * reloading the shoot returns the same alternate date and time, with alternate_scheduled_at
     * derived as date+time (null when time is absent), and the serialized resource reflects it.
     *
     * Validates: Requirements 4.2, 4.3
     */
    #[Test]
    public function property_6_modify_and_approve_round_trip_the_alternate(): void
    {
        mt_srand(20260306);

        $dates = ['2026-10-01', '2026-10-15', '2026-11-30'];
        $times = ['08:00', '13:45', '19:30'];

        for ($i = 0; $i < 8; $i++) {
            $date = $dates[array_rand($dates)];
            $withTime = (bool) mt_rand(0, 1);
            $time = $withTime ? $times[array_rand($times)] : null;
            $useApprove = (bool) mt_rand(0, 1);
            $example = "iter={$i}, date={$date}, time=" . ($time ?? 'null') . ', flow=' . ($useApprove ? 'approve' : 'modify');

            $payload = ['alternate_scheduled_date' => $date];
            if ($withTime) {
                $payload['alternate_time'] = $time;
            }

            Sanctum::actingAs($this->admin);

            if ($useApprove) {
                $shoot = $this->makeShootWithScheduledService('2026-04-10 08:00:00', Shoot::STATUS_REQUESTED);
                $payload['scheduled_at'] = now()->addDays(6)->setTime(9, 0)->format('Y-m-d H:i:s');
                $payload['photographer_id'] = $this->photographer->id;
                $this->postJson("/api/shoots/{$shoot->id}/approve", $payload)->assertOk();
            } else {
                $shoot = $this->makeShootWithScheduledService('2026-04-10 08:00:00');
                $this->patchJson("/api/shoots/{$shoot->id}", $payload)->assertOk();
            }

            $fresh = $shoot->fresh();

            // Date round-trips identically.
            $this->assertSame($date, $fresh->alternate_scheduled_date->toDateString(), "RT-DATE [$example]");

            if ($withTime) {
                // Time round-trips, and alternate_scheduled_at is derived from date+time.
                $this->assertSame($time, $fresh->alternate_time, "RT-TIME [$example]");
                $this->assertNotNull($fresh->alternate_scheduled_at, "RT-AT-PRESENT [$example]");
                $this->assertSame(
                    "{$date} {$time}:00",
                    $fresh->alternate_scheduled_at->format('Y-m-d H:i:s'),
                    "RT-AT-DERIVED [$example]"
                );
            } else {
                // No time => no fabricated alternate_scheduled_at.
                $this->assertNull($fresh->alternate_time, "RT-TIME-NULL [$example]");
                $this->assertNull($fresh->alternate_scheduled_at, "RT-AT-NULL [$example]");
            }

            // The serialized resource reflects the persisted alternate.
            $array = (new ShootResource($fresh))->toArray(Request::create('/'));
            $this->assertSame($date, $array['alternate_scheduled_date'], "RT-RESOURCE-DATE [$example]");
            $this->assertSame($withTime ? $time : null, $array['alternate_time'], "RT-RESOURCE-TIME [$example]");
            $this->assertSame(
                $withTime ? $fresh->alternate_scheduled_at->toIso8601String() : null,
                $array['alternate_scheduled_at'],
                "RT-RESOURCE-AT [$example]"
            );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeShootWithScheduledService(string $serviceScheduledAt, ?string $status = null): Shoot
    {
        $attributes = [
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'address' => '12 Alternate Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ];
        if ($status !== null) {
            $attributes['status'] = $status;
            $attributes['workflow_status'] = $status;
        }

        $shoot = Shoot::factory()->create($attributes);
        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
            'scheduled_at' => $serviceScheduledAt,
        ]);

        return $shoot;
    }

    private function assertServicePivotScheduledAtUnchanged(Shoot $shoot, string $expected, string $label): void
    {
        $shoot->load('services');
        foreach ($shoot->services as $service) {
            $actual = $service->pivot->scheduled_at;
            $actual = $actual ? \Carbon\Carbon::parse($actual)->format('Y-m-d H:i:s') : null;
            $this->assertSame(
                $expected,
                $actual,
                "{$label}: service #{$service->id} scheduled_at changed (expected {$expected}, got " . ($actual ?? 'null') . ')'
            );
        }
    }

    private function bindSideEffectFakes(): void
    {
        $dropboxService = Mockery::mock(DropboxWorkflowService::class);
        $dropboxService->shouldIgnoreMissing();
        $dropboxService->shouldReceive('createShootFolders')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(DropboxWorkflowService::class, $dropboxService);

        $invoiceService = Mockery::mock(InvoiceService::class);
        $invoiceService->shouldIgnoreMissing();
        $invoiceService->shouldReceive('generateForShoot')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(InvoiceService::class, $invoiceService);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('captureShootSnapshot')->zeroOrMoreTimes()->andReturn([]);
        $mailService->shouldReceive('buildShootChangeSummary')->zeroOrMoreTimes()->andReturn([
            'summary' => 'Shoot details updated',
            'html' => '<p>Shoot details updated</p>',
        ]);
        $mailService->shouldReceive('generatePaymentLink')->zeroOrMoreTimes()->andReturn('https://example.test/payment');
        $this->app->instance(MailService::class, $mailService);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $shoot) => [
                'shoot' => $shoot,
                'shoot_id' => $shoot->id,
                'client' => $shoot->client,
                'photographer' => $shoot->photographer,
                'photographers' => $shoot->photographer ? [$shoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $triggerType) => [
                'trigger_type' => $triggerType,
                'active_rule_count' => 0,
                'handled' => false,
                'errors' => [],
                'client_email_sent' => false,
                'photographer_email_sent' => false,
            ]);
        $automationService->shouldReceive('shouldUseFallback')->zeroOrMoreTimes()->andReturnTrue();
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $availabilityService = Mockery::mock(PhotographerAvailabilityService::class);
        $availabilityService->shouldIgnoreMissing();
        $availabilityService->shouldReceive('isAvailable')->zeroOrMoreTimes()->andReturnTrue();
        $this->app->instance(PhotographerAvailabilityService::class, $availabilityService);
    }
}
