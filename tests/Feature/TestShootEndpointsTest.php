<?php

namespace Tests\Feature;

use App\Models\ServiceArea;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature coverage for the Test_Shoot admin endpoints (Req 10.7-10.9, task 10.5).
 *
 * Drives the three controller seams end to end:
 *   - createTestShoot : POST /admin/test-shoots                                    (AC 10.7)
 *   - previewEligible : GET  /admin/test-shoots/{shoot}/eligible-photographers     (AC 10.8)
 *   - assignTestShoot : POST /admin/test-shoots/{shoot}/assign                     (AC 10.9)
 */
class TestShootEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function photographer(array $areas = []): User
    {
        $photographer = User::factory()->photographer()->create();

        foreach ($areas as $area) {
            $serviceArea = ServiceArea::firstOrCreate(
                ['kind' => $area['kind'], 'value' => $area['value']],
            );
            $photographer->serviceAreas()->attach($serviceArea->id);
        }

        return $photographer;
    }

    public function test_create_test_shoot_persists_an_internal_test_shoot_with_region_scope_and_local_day(): void
    {
        Sanctum::actingAs($this->admin());

        // 2026-03-16 03:30 UTC is 2026-03-15 23:30 in America/New_York.
        // The Test_Shoot's local calendar day must be the 15th — never the UTC day.
        $response = $this->postJson('/api/admin/test-shoots', [
            'kind'         => 'state',
            'value'        => 'NY',
            'scheduled_at' => '2026-03-16T03:30:00Z',
            'timezone'     => 'America/New_York',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('shoot.shoot_type', Shoot::SHOOT_TYPE_INTERNAL_TEST);
        $response->assertJsonPath('shoot.service_area_kind', 'state');
        $response->assertJsonPath('shoot.service_area_value', 'NY');
        $response->assertJsonPath('shoot.timezone', 'America/New_York');
        $response->assertJsonPath('shoot.scheduled_date', '2026-03-15');

        $shootId = $response->json('shoot.id');
        $this->assertDatabaseHas('shoots', [
            'id'                 => $shootId,
            'shoot_type'         => Shoot::SHOOT_TYPE_INTERNAL_TEST,
            'service_area_kind'  => 'state',
            'service_area_value' => 'NY',
        ]);
    }

    public function test_create_test_shoot_validates_kind_value_scheduled_at_and_timezone(): void
    {
        Sanctum::actingAs($this->admin());

        // Each invalid field independently rejects the request with 422 — the codebase's
        // custom exception handler in bootstrap/app.php returns a flattened error shape, so
        // we exercise each rule with a single bad value to confirm validation is wired.
        foreach ([
            ['kind' => 'planet'],          // not in ServiceArea::KINDS
            ['value' => ''],                // empty
            ['scheduled_at' => 'not-a-date'],
            ['timezone' => 'Mars/Olympus'],
        ] as $override) {
            $payload = array_merge([
                'kind'         => 'state',
                'value'        => 'NY',
                'scheduled_at' => '2026-04-01T18:00:00Z',
                'timezone'     => 'America/New_York',
            ], $override);

            $this->postJson('/api/admin/test-shoots', $payload)->assertStatus(422);
        }
    }

    public function test_preview_eligible_returns_only_photographers_matching_test_shoot_scope(): void
    {
        $matching = $this->photographer([['kind' => 'state', 'value' => 'NY']]);
        $other    = $this->photographer([['kind' => 'state', 'value' => 'NJ']]);
        $alsoNY   = $this->photographer([
            ['kind' => 'state',  'value' => 'NY'],
            ['kind' => 'region', 'value' => 'Northeast'],
        ]);

        Sanctum::actingAs($this->admin());

        $createResponse = $this->postJson('/api/admin/test-shoots', [
            'kind'         => 'state',
            'value'        => 'NY',
            'scheduled_at' => '2026-04-01T18:00:00Z',
            'timezone'     => 'America/New_York',
        ]);
        $createResponse->assertStatus(201);
        $shootId = $createResponse->json('shoot.id');

        $response = $this->getJson("/api/admin/test-shoots/{$shootId}/eligible-photographers");

        $response->assertOk();
        $response->assertJsonPath('service_area.kind', 'state');
        $response->assertJsonPath('service_area.value', 'NY');

        $ids = collect($response->json('photographers'))->pluck('id')->sort()->values()->all();
        $this->assertSame(
            collect([$matching->id, $alsoNY->id])->sort()->values()->all(),
            $ids,
            'Only photographers whose service-area assignments match the Test_Shoot should be returned.'
        );
        $this->assertNotContains($other->id, $ids);
    }

    public function test_assign_test_shoot_links_photographer_and_persists(): void
    {
        $photographer = $this->photographer([['kind' => 'state', 'value' => 'NY']]);

        Sanctum::actingAs($this->admin());

        $createResponse = $this->postJson('/api/admin/test-shoots', [
            'kind'         => 'state',
            'value'        => 'NY',
            'scheduled_at' => '2026-05-10T14:00:00-04:00',
            'timezone'     => 'America/New_York',
        ]);
        $createResponse->assertStatus(201);
        $shootId = $createResponse->json('shoot.id');

        $response = $this->postJson("/api/admin/test-shoots/{$shootId}/assign", [
            'user_id' => $photographer->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('assigned', true);
        $response->assertJsonPath('shoot.photographer_id', $photographer->id);

        $this->assertDatabaseHas('shoots', [
            'id'              => $shootId,
            'photographer_id' => $photographer->id,
        ]);
    }

    public function test_assign_rejects_user_id_that_is_not_a_photographer(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        Sanctum::actingAs($this->admin());

        $createResponse = $this->postJson('/api/admin/test-shoots', [
            'kind'         => 'state',
            'value'        => 'NY',
            'scheduled_at' => '2026-05-10T14:00:00Z',
            'timezone'     => 'America/New_York',
        ]);
        $shootId = $createResponse->json('shoot.id');

        $this->postJson("/api/admin/test-shoots/{$shootId}/assign", [
            'user_id' => $client->id,
        ])->assertStatus(422);

        // The assignment should not have been persisted.
        $this->assertDatabaseMissing('shoots', [
            'id'              => $shootId,
            'photographer_id' => $client->id,
        ]);
    }

    public function test_endpoints_require_admin_authentication(): void
    {
        // Anonymous request — 401.
        $this->postJson('/api/admin/test-shoots', [
            'kind'         => 'state',
            'value'        => 'NY',
            'scheduled_at' => '2026-05-10T14:00:00Z',
            'timezone'     => 'America/New_York',
        ])->assertStatus(401);

        // Non-admin user (a photographer) — 403.
        $photographer = User::factory()->photographer()->create();
        Sanctum::actingAs($photographer);
        $this->postJson('/api/admin/test-shoots', [
            'kind'         => 'state',
            'value'        => 'NY',
            'scheduled_at' => '2026-05-10T14:00:00Z',
            'timezone'     => 'America/New_York',
        ])->assertStatus(403);
    }

    public function test_preview_eligible_returns_404_for_a_non_test_shoot(): void
    {
        Sanctum::actingAs($this->admin());

        // A regular shoot (not internal_test) should not be addressable via the
        // Test_Shoot endpoints — guards against silent misbehavior on shoots
        // that have no service_area_kind/value scope.
        $shoot = Shoot::factory()->create();

        $this->getJson("/api/admin/test-shoots/{$shoot->id}/eligible-photographers")
            ->assertStatus(404);
    }
}
