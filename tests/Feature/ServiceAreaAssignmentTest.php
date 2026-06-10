<?php

namespace Tests\Feature;

use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature coverage for the photographer service-area assignment tool (Req 10).
 *
 * Exercises the four controller seams end to end: assign (10.1/10.4), filter (10.2),
 * preview (10.3/10.5 — persists nothing), and commit (10.4 — persists).
 */
class ServiceAreaAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function photographer(): User
    {
        return User::factory()->create(['role' => 'photographer']);
    }

    public function test_admin_can_assign_service_areas_to_a_photographer(): void
    {
        $photographer = $this->photographer();
        Sanctum::actingAs($this->admin());

        $response = $this->postJson("/api/admin/photographers/{$photographer->id}/service-areas", [
            'service_areas' => [
                ['kind' => 'state', 'value' => 'MD', 'label' => 'Maryland'],
                ['kind' => 'region', 'value' => 'Northeast'],
            ],
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('service_areas'));

        // AC 10.1/10.4 — areas persisted on the pivot.
        $this->assertSame(2, $photographer->serviceAreas()->count());
        $this->assertDatabaseHas('service_areas', ['kind' => 'state', 'value' => 'MD']);
    }

    public function test_filter_returns_only_matching_photographers(): void
    {
        $md = $this->photographer();
        $va = $this->photographer();

        $mdArea = ServiceArea::create(['kind' => 'state', 'value' => 'MD']);
        $vaArea = ServiceArea::create(['kind' => 'state', 'value' => 'VA']);
        $md->serviceAreas()->attach($mdArea->id);
        $va->serviceAreas()->attach($vaArea->id);

        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/admin/service-area/photographers?service_area_kind=state&service_area_value=MD');

        $response->assertOk();
        $ids = collect($response->json('photographers'))->pluck('id')->all();

        // AC 10.2 — only the MD photographer matches.
        $this->assertSame([$md->id], $ids);
    }

    public function test_preview_returns_matches_but_persists_nothing(): void
    {
        $photographer = $this->photographer();
        $area = ServiceArea::create(['kind' => 'state', 'value' => 'MD']);
        $photographer->serviceAreas()->attach($area->id);

        $pivotBefore = \DB::table('photographer_service_areas')->count();

        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/admin/assignments/preview', [
            'service_area_kind' => 'state',
            'service_area_value' => 'MD',
        ]);

        $response->assertOk();
        $response->assertJsonPath('preview', true);
        $ids = collect($response->json('photographers'))->pluck('id')->all();
        $this->assertSame([$photographer->id], $ids);

        // AC 10.3/10.5 — preview wrote nothing.
        $this->assertSame($pivotBefore, \DB::table('photographer_service_areas')->count());
    }

    public function test_commit_persists_the_previewed_assignment_and_matches_preview(): void
    {
        // A target photographer that does NOT yet have the VA area.
        $target = $this->photographer();
        // An existing VA photographer so preview/commit return a non-empty match set.
        $existing = $this->photographer();
        $existing->serviceAreas()->attach(ServiceArea::create(['kind' => 'state', 'value' => 'VA'])->id);

        Sanctum::actingAs($this->admin());

        $previewIds = collect($this->postJson('/api/admin/assignments/preview', [
            'service_area_kind' => 'state',
            'service_area_value' => 'VA',
        ])->json('photographers'))->pluck('id')->all();

        $response = $this->postJson('/api/admin/assignments/commit', [
            'service_area_kind' => 'state',
            'service_area_value' => 'VA',
            'user_id' => $target->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('committed', true);

        // AC 10.4 — the assignment is now persisted for the target photographer.
        $this->assertTrue(
            $target->fresh()->serviceAreas()
                ->where('kind', 'state')->where('value', 'VA')->exists()
        );

        // Property 5 — the commit's match set equals the preview's match set (computed pre-persistence).
        $this->assertSame($previewIds, $response->json('photographers.*.id'));
    }

    public function test_endpoints_require_admin_authentication(): void
    {
        $photographer = $this->photographer();

        $this->postJson('/api/admin/assignments/preview', [
            'service_area_kind' => 'state',
            'service_area_value' => 'MD',
        ])->assertStatus(401);

        // A non-admin (the photographer) is forbidden.
        Sanctum::actingAs($photographer);
        $this->postJson('/api/admin/assignments/preview', [
            'service_area_kind' => 'state',
            'service_area_value' => 'MD',
        ])->assertStatus(403);
    }
}
