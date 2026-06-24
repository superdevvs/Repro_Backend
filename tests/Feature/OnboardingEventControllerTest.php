<?php

namespace Tests\Feature;

use App\Models\OnboardingEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Backend coverage for the onboarding telemetry endpoints (plan item G2):
 *   - POST /api/onboarding/events  (store: single + batch, auth/role gating, validation)
 *   - GET  /api/onboarding/funnel  (admin-only aggregate summary)
 *
 * Contract sources:
 *   - app/Http/Controllers/API/OnboardingEventController.php
 *   - app/Services/Onboarding/OnboardingTelemetryService.php
 *   - app/Services/Users/DashboardOnboardingService.php (canonical role->key map)
 *   - routes/api.php (role middleware + throttle)
 */
class OnboardingEventControllerTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS_URL = '/api/onboarding/events';
    private const FUNNEL_URL = '/api/onboarding/funnel';

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function validEvent(array $overrides = []): array
    {
        return array_merge([
            'event_type' => 'started',
            'role' => 'photographer',
            'onboarding_key' => 'photographerDashboardOnboarding',
            'version' => 1,
            'step_index' => 0,
            'step_target' => 'welcome',
            'session_uuid' => $this->uuid(),
            'source' => 'dashboard',
            'meta' => ['foo' => 'bar'],
        ], $overrides);
    }

    /* ---------------------------------------------------------------------
     | POST /api/onboarding/events
     * ------------------------------------------------------------------- */

    /** 1. Unauthenticated request → 401. */
    public function test_store_requires_authentication(): void
    {
        $this->postJson(self::EVENTS_URL, $this->validEvent())
            ->assertStatus(401);
    }

    /** 2. Admin is excluded by the role middleware → 403. */
    public function test_store_forbidden_for_admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'account_status' => 'active']));

        $this->postJson(self::EVENTS_URL, $this->validEvent())
            ->assertStatus(403);
    }

    /** 2b. Superadmin is also excluded by the role middleware → 403. */
    public function test_store_forbidden_for_superadmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin', 'account_status' => 'active']));

        $this->postJson(self::EVENTS_URL, $this->validEvent())
            ->assertStatus(403);
    }

    /** 3. Onboarded role posting a single valid event → 200 { recorded: 1 } and a persisted row. */
    public function test_store_single_valid_event_records_one_row(): void
    {
        $user = User::factory()->create(['role' => 'photographer', 'account_status' => 'active']);
        Sanctum::actingAs($user);

        $session = $this->uuid();
        $event = $this->validEvent([
            'event_type' => 'step_viewed',
            'session_uuid' => $session,
            'step_index' => 2,
            'step_target' => 'upload',
        ]);

        $this->postJson(self::EVENTS_URL, $event)
            ->assertOk()
            ->assertJson(['recorded' => 1]);

        $this->assertDatabaseHas('onboarding_events', [
            'user_id' => $user->id,
            'role' => 'photographer',
            'onboarding_key' => 'photographerDashboardOnboarding',
            'event_type' => 'step_viewed',
            'step_index' => 2,
            'step_target' => 'upload',
            'session_uuid' => $session,
        ]);

        $this->assertSame(1, OnboardingEvent::count());
    }

    /** 3b. Persisted role/key derive from the authenticated user, for every onboarded role. */
    public function test_store_records_canonical_key_per_role(): void
    {
        $roleKeys = [
            'client' => 'clientDashboardOnboarding',
            'photographer' => 'photographerDashboardOnboarding',
            'salesRep' => 'salesRepDashboardOnboarding',
            'editing_manager' => 'editingManagerDashboardOnboarding',
            'editor' => 'editorDashboardOnboarding',
        ];

        foreach ($roleKeys as $role => $key) {
            $user = User::factory()->create(['role' => $role, 'account_status' => 'active']);
            Sanctum::actingAs($user);

            $session = $this->uuid();
            $this->postJson(self::EVENTS_URL, $this->validEvent([
                'event_type' => 'started',
                'role' => $role,
                'onboarding_key' => $key,
                'session_uuid' => $session,
            ]))->assertOk()->assertJson(['recorded' => 1]);

            $this->assertDatabaseHas('onboarding_events', [
                'user_id' => $user->id,
                'role' => $role,
                'onboarding_key' => $key,
                'event_type' => 'started',
                'session_uuid' => $session,
            ]);
        }
    }

    /** 4. Batch post → 200 { recorded: 2 } and 2 persisted rows. */
    public function test_store_batch_records_each_event(): void
    {
        $user = User::factory()->create(['role' => 'editor', 'account_status' => 'active']);
        Sanctum::actingAs($user);

        $s1 = $this->uuid();
        $s2 = $this->uuid();

        $payload = [
            'events' => [
                $this->validEvent([
                    'event_type' => 'started',
                    'role' => 'editor',
                    'onboarding_key' => 'editorDashboardOnboarding',
                    'session_uuid' => $s1,
                    'step_index' => 0,
                ]),
                $this->validEvent([
                    'event_type' => 'completed',
                    'role' => 'editor',
                    'onboarding_key' => 'editorDashboardOnboarding',
                    'session_uuid' => $s2,
                    'step_index' => 5,
                ]),
            ],
        ];

        $this->postJson(self::EVENTS_URL, $payload)
            ->assertOk()
            ->assertJson(['recorded' => 2]);

        $this->assertSame(2, OnboardingEvent::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('onboarding_events', [
            'user_id' => $user->id,
            'event_type' => 'started',
            'session_uuid' => $s1,
        ]);
        $this->assertDatabaseHas('onboarding_events', [
            'user_id' => $user->id,
            'event_type' => 'completed',
            'session_uuid' => $s2,
        ]);
    }

    /* ---------------------------------------------------------------------
     | POST validation failures (controller returns 422 before the service)
     * ------------------------------------------------------------------- */

    /** 5a. Invalid event_type → 422. */
    public function test_store_rejects_invalid_event_type(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer', 'account_status' => 'active']));

        $this->postJson(self::EVENTS_URL, $this->validEvent(['event_type' => 'not_a_real_type']))
            ->assertStatus(422)
            ->assertJsonPath('errors.event_type.0', fn ($msg) => is_string($msg));

        $this->assertSame(0, OnboardingEvent::count());
    }

    /** 5b. Role that is not an onboarded role → 422. */
    public function test_store_rejects_non_onboarded_role(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer', 'account_status' => 'active']));

        $this->postJson(self::EVENTS_URL, $this->validEvent(['role' => 'admin']))
            ->assertStatus(422);

        $this->assertSame(0, OnboardingEvent::count());
    }

    /** 5c. onboarding_key that does not match the supplied role → 422. */
    public function test_store_rejects_mismatched_onboarding_key(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer', 'account_status' => 'active']));

        $this->postJson(self::EVENTS_URL, $this->validEvent([
            'role' => 'photographer',
            'onboarding_key' => 'clientDashboardOnboarding', // wrong key for photographer
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.onboarding_key.0', fn ($msg) => is_string($msg));

        $this->assertSame(0, OnboardingEvent::count());
    }

    /** 5d. step_index out of range (max 100) → 422. */
    public function test_store_rejects_step_index_out_of_range(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer', 'account_status' => 'active']));

        $this->postJson(self::EVENTS_URL, $this->validEvent(['step_index' => 999]))
            ->assertStatus(422)
            ->assertJsonPath('errors.step_index.0', fn ($msg) => is_string($msg));

        $this->assertSame(0, OnboardingEvent::count());
    }

    /** 5e. Missing session_uuid → 422. */
    public function test_store_rejects_missing_session_uuid(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer', 'account_status' => 'active']));

        $event = $this->validEvent();
        unset($event['session_uuid']);

        $this->postJson(self::EVENTS_URL, $event)
            ->assertStatus(422)
            ->assertJsonPath('errors.session_uuid.0', fn ($msg) => is_string($msg));

        $this->assertSame(0, OnboardingEvent::count());
    }

    /** 5f. A non-UUID session_uuid → 422. */
    public function test_store_rejects_non_uuid_session_uuid(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer', 'account_status' => 'active']));

        $this->postJson(self::EVENTS_URL, $this->validEvent(['session_uuid' => 'not-a-uuid']))
            ->assertStatus(422);

        $this->assertSame(0, OnboardingEvent::count());
    }

    /** 5g. Batch with one invalid event → whole request rejected with 422 (controller validates each). */
    public function test_store_batch_rejects_when_any_event_invalid(): void
    {
        $user = User::factory()->create(['role' => 'photographer', 'account_status' => 'active']);
        Sanctum::actingAs($user);

        $payload = [
            'events' => [
                $this->validEvent(['event_type' => 'started']),
                $this->validEvent(['event_type' => 'bogus']), // invalid → rejects batch
            ],
        ];

        $this->postJson(self::EVENTS_URL, $payload)
            ->assertStatus(422)
            ->assertJsonPath('index', 1);

        $this->assertSame(0, OnboardingEvent::count());
    }

    /* ---------------------------------------------------------------------
     | GET /api/onboarding/funnel
     * ------------------------------------------------------------------- */

    /** 6. Non-admin (photographer) → 403. */
    public function test_funnel_forbidden_for_photographer(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer', 'account_status' => 'active']));

        $this->getJson(self::FUNNEL_URL)->assertStatus(403);
    }

    /** 6b. Non-admin (client) → 403. */
    public function test_funnel_forbidden_for_client(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client', 'account_status' => 'active']));

        $this->getJson(self::FUNNEL_URL)->assertStatus(403);
    }

    /** 6c. Unauthenticated → 401. */
    public function test_funnel_requires_authentication(): void
    {
        $this->getJson(self::FUNNEL_URL)->assertStatus(401);
    }

    private function seedFunnelEvents(): array
    {
        $photographer = User::factory()->create(['role' => 'photographer', 'account_status' => 'active']);
        $client = User::factory()->create(['role' => 'client', 'account_status' => 'active']);

        $rows = [
            // photographer: 2 started, 1 step_viewed
            ['user_id' => $photographer->id, 'role' => 'photographer', 'onboarding_key' => 'photographerDashboardOnboarding', 'event_type' => 'started', 'step_index' => null, 'step_target' => null],
            ['user_id' => $photographer->id, 'role' => 'photographer', 'onboarding_key' => 'photographerDashboardOnboarding', 'event_type' => 'started', 'step_index' => null, 'step_target' => null],
            ['user_id' => $photographer->id, 'role' => 'photographer', 'onboarding_key' => 'photographerDashboardOnboarding', 'event_type' => 'step_viewed', 'step_index' => 0, 'step_target' => 'welcome'],
            // client: 1 started, 1 step_viewed
            ['user_id' => $client->id, 'role' => 'client', 'onboarding_key' => 'clientDashboardOnboarding', 'event_type' => 'started', 'step_index' => null, 'step_target' => null],
            ['user_id' => $client->id, 'role' => 'client', 'onboarding_key' => 'clientDashboardOnboarding', 'event_type' => 'step_viewed', 'step_index' => 1, 'step_target' => 'gallery'],
        ];

        foreach ($rows as $row) {
            OnboardingEvent::create(array_merge($row, [
                'version' => 1,
                'session_uuid' => $this->uuid(),
                'source' => 'dashboard',
                'created_at' => now(),
            ]));
        }

        return ['photographer' => $photographer, 'client' => $client];
    }

    /** 7. Admin → 200 with documented shape; aggregated counts reflect seeded rows. */
    public function test_funnel_returns_aggregated_counts_for_admin(): void
    {
        $this->seedFunnelEvents();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'account_status' => 'active']));

        $response = $this->getJson(self::FUNNEL_URL)
            ->assertOk()
            ->assertJsonStructure([
                'filters' => ['role', 'from', 'to'],
                'counts_by_role',
                'step_dropoff',
            ]);

        // counts_by_role: role => event_type => total
        $response->assertJsonPath('counts_by_role.photographer.started', 2);
        $response->assertJsonPath('counts_by_role.photographer.step_viewed', 1);
        $response->assertJsonPath('counts_by_role.client.started', 1);
        $response->assertJsonPath('counts_by_role.client.step_viewed', 1);

        // step_dropoff: only step_viewed events appear, grouped per role.
        $data = $response->json();
        $this->assertArrayHasKey('photographer', $data['step_dropoff']);
        $this->assertArrayHasKey('client', $data['step_dropoff']);

        $photographerSteps = collect($data['step_dropoff']['photographer']);
        $this->assertTrue(
            $photographerSteps->contains(fn ($s) => $s['step_index'] === 0 && $s['step_target'] === 'welcome' && $s['views'] === 1),
            'photographer step_dropoff should include the welcome step view'
        );
    }

    /** 7b. Superadmin can also read the funnel → 200. */
    public function test_funnel_accessible_to_superadmin(): void
    {
        $this->seedFunnelEvents();

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin', 'account_status' => 'active']));

        $this->getJson(self::FUNNEL_URL)
            ->assertOk()
            ->assertJsonPath('counts_by_role.photographer.started', 2);
    }

    /** 7c. role filter narrows counts_by_role to the requested role only. */
    public function test_funnel_role_filter(): void
    {
        $this->seedFunnelEvents();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'account_status' => 'active']));

        $response = $this->getJson(self::FUNNEL_URL . '?role=photographer')
            ->assertOk()
            ->assertJsonPath('filters.role', 'photographer')
            ->assertJsonPath('counts_by_role.photographer.started', 2);

        $data = $response->json();
        $this->assertArrayNotHasKey('client', $data['counts_by_role']);
    }
}
