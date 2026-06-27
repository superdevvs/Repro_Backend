<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Feature + scoped-property tests for the apply-alternate-date endpoint and
 * ApplyAlternateDateAction.
 *
 * Endpoint: POST /api/shoots/{shoot}/apply-alternate-date  body { scope: 'main'|'all_services' }
 * Role-gated to admin/superadmin/editing_manager.
 *
 * These mirror the conventions in ExternalBookingShootSyncIntegrationTest (RefreshDatabase,
 * sqlite in-memory, Sanctum::actingAs, existing factories) and the seeded-deterministic
 * generator approach in ExternalBookingAutoMapperTest (the repo has no PHP PBT library):
 * property-style methods iterate a small deterministic matrix of inputs.
 *
 * Covers design Correctness Properties 7-12 and tasks 8.7-8.12, 8.14, 8.15.
 *
 * Validates: Requirements 3.3, 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9,
 *            6.1, 6.2, 6.3, 6.4, 8.1, 8.2, 9.2, 9.3, 9.4, 9.5, 9.6
 */
class ShootAlternateDateApplyTest extends TestCase
{
    use RefreshDatabase;

    // A fixed alternate slot used across the suite (distinct from the main schedule).
    private const ALT_DATE = '2026-05-10';
    private const ALT_TIME = '13:30';
    private const ALT_AT = '2026-05-10 13:30:00';

    // A fixed original main schedule (distinct from the alternate).
    private const MAIN_DATE = '2026-01-01';
    private const MAIN_TIME = '08:00';
    private const MAIN_AT = '2026-01-01 08:00:00';

    // A fixed original service schedule (distinct from both).
    private const SERVICE_AT = '2026-04-01 09:00:00';

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function authorizedEditor(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /**
     * Create a shoot with $serviceCount attached services (each carrying SERVICE_AT) and a
     * fixed original main schedule. When $withAlternate, set the stored alternate fields.
     *
     * @return array{0: Shoot, 1: array<int, Service>}
     */
    private function makeShoot(int $serviceCount, bool $withAlternate = true, ?string $altTime = self::ALT_TIME): array
    {
        $attributes = [
            'scheduled_date' => self::MAIN_DATE,
            'time' => self::MAIN_TIME,
            'scheduled_at' => self::MAIN_AT,
        ];

        if ($withAlternate) {
            $attributes['alternate_scheduled_date'] = self::ALT_DATE;
            $attributes['alternate_time'] = $altTime;
            $attributes['alternate_scheduled_at'] = $altTime ? self::ALT_AT : null;
        }

        $shoot = Shoot::factory()->create($attributes);

        $services = Service::factory()->count($serviceCount)->create()->all();
        foreach ($services as $service) {
            $shoot->services()->attach($service->id, [
                'price' => 150,
                'quantity' => 1,
                'scheduled_at' => self::SERVICE_AT,
            ]);
        }

        return [$shoot->fresh(), $services];
    }

    /** Read the raw pivot scheduled_at for a service straight from the shoot_service table. */
    private function pivotScheduledAt(int $shootId, int $serviceId): ?string
    {
        $value = DB::table('shoot_service')
            ->where('shoot_id', $shootId)
            ->where('service_id', $serviceId)
            ->value('scheduled_at');

        return $value === null ? null : (string) $value;
    }

    /** @param array<int, Service> $services @return array<int, ?string> keyed by service id */
    private function snapshotServiceSchedules(int $shootId, array $services): array
    {
        $snapshot = [];
        foreach ($services as $service) {
            $snapshot[$service->id] = $this->pivotScheduledAt($shootId, $service->id);
        }

        return $snapshot;
    }

    private function apply(Shoot $shoot, ?string $scope = null)
    {
        $body = $scope === null ? [] : ['scope' => $scope];

        return $this->postJson("/api/shoots/{$shoot->id}/apply-alternate-date", $body);
    }

    // =================================================================
    // Property 7 / 8.7 — scope=main sets main, leaves services unchanged
    // Feature: shoot-alternate-date-field, Property 7
    // Validates: Requirements 5.4, 3.3, 9.2
    // =================================================================

    public function test_property_7_scope_main_sets_main_schedule_and_leaves_services_unchanged(): void
    {
        Sanctum::actingAs($this->authorizedEditor());

        // Deterministic matrix: single vs multi service × alternate with/without a time.
        $cases = [
            ['services' => 1, 'altTime' => self::ALT_TIME],
            ['services' => 2, 'altTime' => self::ALT_TIME],
            ['services' => 3, 'altTime' => null], // alternate date without a time
        ];

        foreach ($cases as $case) {
            [$shoot, $services] = $this->makeShoot($case['services'], true, $case['altTime']);
            $before = $this->snapshotServiceSchedules($shoot->id, $services);

            $response = $this->apply($shoot, 'main');
            $response->assertOk();

            $fresh = $shoot->fresh();

            // Main schedule equals the stored alternate.
            $this->assertSame(self::ALT_DATE, $fresh->scheduled_date?->toDateString());
            $this->assertSame($case['altTime'], $fresh->time);
            if ($case['altTime'] === null) {
                // Null-time rule: no fabricated scheduled_at.
                $this->assertNull($fresh->scheduled_at);
            } else {
                $this->assertNotNull($fresh->scheduled_at);
                $this->assertStringStartsWith(self::ALT_DATE, $fresh->scheduled_at->format('Y-m-d H:i:s'));
            }

            // Every service pivot scheduled_at is UNCHANGED (Req 3.3 / 9.2).
            $after = $this->snapshotServiceSchedules($shoot->id, $services);
            $this->assertSame($before, $after, 'scope=main must not touch any service schedule');
            foreach ($after as $value) {
                $this->assertNotNull($value);
                $this->assertStringStartsWith('2026-04-01', (string) $value);
            }
        }
    }

    // =================================================================
    // Property 8 / 8.8 — scope=all_services sets main AND every service
    // Feature: shoot-alternate-date-field, Property 8
    // Validates: Requirements 5.5, 9.3
    // =================================================================

    public function test_property_8_scope_all_services_sets_main_and_every_service(): void
    {
        Sanctum::actingAs($this->authorizedEditor());

        // Multi-service shoots only; alternate with a time, and the null-time variant.
        $cases = [
            ['services' => 2, 'altTime' => self::ALT_TIME],
            ['services' => 3, 'altTime' => self::ALT_TIME],
            ['services' => 2, 'altTime' => null],
        ];

        foreach ($cases as $case) {
            [$shoot, $services] = $this->makeShoot($case['services'], true, $case['altTime']);

            $response = $this->apply($shoot, 'all_services');
            $response->assertOk();

            $fresh = $shoot->fresh();

            // Main schedule set from the alternate.
            $this->assertSame(self::ALT_DATE, $fresh->scheduled_date?->toDateString());
            $this->assertSame($case['altTime'], $fresh->time);

            // Every service pivot scheduled_at now equals the alternate (kept consistent with
            // the main schedule: null when the alternate has no time).
            foreach ($services as $service) {
                $pivot = $this->pivotScheduledAt($shoot->id, $service->id);
                if ($case['altTime'] === null) {
                    $this->assertNull($pivot, 'null-time alternate => service scheduled_at null');
                } else {
                    $this->assertNotNull($pivot);
                    $this->assertStringStartsWith(self::ALT_DATE, $pivot, 'service moved to the alternate date');
                    $this->assertStringContainsString('13:30', $pivot);
                }
            }
        }
    }

    // =================================================================
    // Property 9 / 8.9 — apply retains the alternate unchanged (either scope)
    // Feature: shoot-alternate-date-field, Property 9
    // Validates: Requirements 5.9, 9.6
    // =================================================================

    public function test_property_9_apply_retains_alternate_unchanged(): void
    {
        Sanctum::actingAs($this->authorizedEditor());

        foreach (['main', 'all_services'] as $scope) {
            [$shoot] = $this->makeShoot(2, true, self::ALT_TIME);

            $this->apply($shoot, $scope)->assertOk();

            $fresh = $shoot->fresh();
            $this->assertSame(self::ALT_DATE, $fresh->alternate_scheduled_date?->toDateString());
            $this->assertSame(self::ALT_TIME, $fresh->alternate_time);
            $this->assertNotNull($fresh->alternate_scheduled_at);
            $this->assertStringStartsWith(self::ALT_DATE, $fresh->alternate_scheduled_at->format('Y-m-d H:i:s'));
        }
    }

    // =================================================================
    // Property 10 / 8.10 — exactly one activity log, no reschedule request
    // Feature: shoot-alternate-date-field, Property 10
    // Validates: Requirements 5.6, 5.7, 6.1, 9.5
    // =================================================================

    public function test_property_10_apply_creates_one_activity_log_and_no_reschedule_request(): void
    {
        Sanctum::actingAs($this->authorizedEditor());

        foreach (['main', 'all_services'] as $scope) {
            [$shoot] = $this->makeShoot(2, true, self::ALT_TIME);

            $this->apply($shoot, $scope)->assertOk();

            $logs = ShootActivityLog::where('shoot_id', $shoot->id)
                ->where('action', 'apply_alternate_date')
                ->get();

            $this->assertCount(1, $logs, "exactly one apply_alternate_date log for scope={$scope}");
            $this->assertSame($scope, $logs->first()->metadata['scope'] ?? null);

            $this->assertSame(
                0,
                ShootRescheduleRequest::where('shoot_id', $shoot->id)->count(),
                'apply must never create a ShootRescheduleRequest'
            );
        }
    }

    // =================================================================
    // Property 11 / 8.11 — no alternate => 422 and no schedule changes
    // Feature: shoot-alternate-date-field, Property 11
    // Validates: Requirements 5.3, 9.4
    // =================================================================

    public function test_property_11_apply_with_no_alternate_is_rejected_with_no_changes(): void
    {
        Sanctum::actingAs($this->authorizedEditor());

        foreach (['main', 'all_services'] as $scope) {
            [$shoot, $services] = $this->makeShoot(2, false); // no alternate stored
            $before = $this->snapshotServiceSchedules($shoot->id, $services);

            $response = $this->apply($shoot, $scope);
            $response->assertStatus(422);

            $fresh = $shoot->fresh();
            // Main schedule unchanged.
            $this->assertSame(self::MAIN_DATE, $fresh->scheduled_date?->toDateString());
            $this->assertSame(self::MAIN_TIME, $fresh->time);
            $this->assertStringStartsWith(self::MAIN_DATE, $fresh->scheduled_at->format('Y-m-d H:i:s'));

            // Every service schedule unchanged.
            $this->assertSame($before, $this->snapshotServiceSchedules($shoot->id, $services));

            // No side effects.
            $this->assertSame(0, ShootActivityLog::where('shoot_id', $shoot->id)->where('action', 'apply_alternate_date')->count());
            $this->assertSame(0, ShootRescheduleRequest::where('shoot_id', $shoot->id)->count());
        }
    }

    // =================================================================
    // Property 12 / 8.12 — authorization gate
    // Feature: shoot-alternate-date-field, Property 12
    // Validates: Requirements 8.1, 8.2
    // =================================================================

    public function test_property_12_authorized_roles_are_permitted(): void
    {
        foreach (['admin', 'superadmin', 'editing_manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            [$shoot] = $this->makeShoot(1, true, self::ALT_TIME);

            $this->apply($shoot, 'main')->assertOk();

            $this->assertSame(self::ALT_DATE, $shoot->fresh()->scheduled_date?->toDateString());
        }
    }

    public function test_property_12_unauthorized_roles_are_rejected_with_no_changes(): void
    {
        foreach (['photographer', 'client', 'editor'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            [$shoot, $services] = $this->makeShoot(2, true, self::ALT_TIME);
            $before = $this->snapshotServiceSchedules($shoot->id, $services);

            $response = $this->apply($shoot, 'main');
            $response->assertStatus(403);

            $fresh = $shoot->fresh();
            // Main schedule untouched (still the original, NOT the alternate).
            $this->assertSame(self::MAIN_DATE, $fresh->scheduled_date?->toDateString());
            $this->assertSame(self::MAIN_TIME, $fresh->time);

            // Service schedules untouched.
            $this->assertSame($before, $this->snapshotServiceSchedules($shoot->id, $services));

            // No activity log written.
            $this->assertSame(
                0,
                ShootActivityLog::where('shoot_id', $shoot->id)->where('action', 'apply_alternate_date')->count()
            );
        }
    }

    // =================================================================
    // 8.14 — endpoint feature tests (scope acceptance/default + resource shape)
    // Validates: Requirements 5.1, 5.2, 5.8
    // =================================================================

    public function test_endpoint_accepts_scope_main(): void
    {
        Sanctum::actingAs($this->authorizedEditor());
        [$shoot] = $this->makeShoot(1, true, self::ALT_TIME);

        $this->apply($shoot, 'main')->assertOk();
    }

    public function test_endpoint_accepts_scope_all_services(): void
    {
        Sanctum::actingAs($this->authorizedEditor());
        [$shoot] = $this->makeShoot(2, true, self::ALT_TIME);

        $this->apply($shoot, 'all_services')->assertOk();
    }

    public function test_endpoint_defaults_scope_to_main_when_omitted(): void
    {
        Sanctum::actingAs($this->authorizedEditor());
        [$shoot, $services] = $this->makeShoot(2, true, self::ALT_TIME);
        $before = $this->snapshotServiceSchedules($shoot->id, $services);

        // No scope in the body => defaults to 'main'.
        $this->apply($shoot, null)->assertOk();

        // Main moved to the alternate; services left untouched (proves the default is 'main').
        $this->assertSame(self::ALT_DATE, $shoot->fresh()->scheduled_date?->toDateString());
        $this->assertSame($before, $this->snapshotServiceSchedules($shoot->id, $services));

        $log = ShootActivityLog::where('shoot_id', $shoot->id)
            ->where('action', 'apply_alternate_date')
            ->first();
        $this->assertSame('main', $log->metadata['scope'] ?? null);
    }

    public function test_endpoint_rejects_invalid_scope(): void
    {
        Sanctum::actingAs($this->authorizedEditor());
        [$shoot] = $this->makeShoot(1, true, self::ALT_TIME);

        $this->apply($shoot, 'everything')->assertStatus(422);
    }

    public function test_endpoint_returns_updated_shoot_resource_reflecting_new_main_schedule(): void
    {
        Sanctum::actingAs($this->authorizedEditor());
        [$shoot] = $this->makeShoot(1, true, self::ALT_TIME);

        $response = $this->apply($shoot, 'main');

        $response->assertOk()
            ->assertJsonPath('data.id', (string) $shoot->id)
            ->assertJsonPath('data.scheduledDate', self::ALT_DATE)
            ->assertJsonPath('data.time', self::ALT_TIME)
            // Alternate fields are retained in the serialized resource.
            ->assertJsonPath('data.alternate_scheduled_date', self::ALT_DATE)
            ->assertJsonPath('data.alternate_time', self::ALT_TIME);

        $this->assertNotNull($response->json('data.scheduledAt'));
        $this->assertStringStartsWith(self::ALT_DATE, (string) $response->json('data.scheduledAt'));
    }

    public function test_endpoint_returns_422_when_no_alternate_stored(): void
    {
        Sanctum::actingAs($this->authorizedEditor());
        [$shoot] = $this->makeShoot(1, false);

        $this->apply($shoot, 'main')->assertStatus(422);
    }

    // =================================================================
    // 8.15 — internal-update integration guarantees
    // Validates: Requirements 6.1, 6.2, 6.3, 6.4, 9.5
    // =================================================================

    public function test_apply_fires_no_notifications_automations_or_reschedule_request(): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();

        // Spy on AutomationService so we can assert no automation event is ever dispatched.
        $automationSpy = Mockery::spy(AutomationService::class);
        $this->app->instance(AutomationService::class, $automationSpy);

        Sanctum::actingAs($this->authorizedEditor());

        [$shoot] = $this->makeShoot(2, true, self::ALT_TIME);

        $this->apply($shoot, 'all_services')->assertOk();

        // Req 6.2 / 6.4 — no automation flow (incl. SHOOT_UPDATED / SHOOT_SCHEDULED) fired.
        $automationSpy->shouldNotHaveReceived('handleEvent');

        // Req 6.3 — no email or notification dispatched.
        Mail::assertNothingSent();
        Notification::assertNothingSent();

        // Req 6.1 — no reschedule request created.
        $this->assertSame(0, ShootRescheduleRequest::where('shoot_id', $shoot->id)->count());

        // Req 9.5 — exactly one activity log entry.
        $this->assertCount(
            1,
            ShootActivityLog::where('shoot_id', $shoot->id)->where('action', 'apply_alternate_date')->get()
        );
    }
}
