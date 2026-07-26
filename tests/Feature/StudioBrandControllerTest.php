<?php

namespace Tests\Feature;

use App\Models\BrandState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Targeted endpoint coverage for team-scoped Studio Brand State.
 *
 * Validates: Requirements 10.4, 10.9, 13.11, 13.12, 13.19, 16.8.
 */
class StudioBrandControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_studio_roles_can_read_and_persist_team_brand_state(): void
    {
        foreach (['admin', 'superadmin', 'editing_manager', 'editor'] as $index => $role) {
            $user = $this->teamUser($role, 700 + $index);
            Sanctum::actingAs($user);

            $this->getJson('/api/studio/brand')
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.teamId', 700 + $index)
                ->assertJsonPath('data.settings', [])
                ->assertJsonPath('data.version', 0);

            $this->putJson('/api/studio/brand', [
                'version' => 0,
                'settings' => [
                    'logo' => "brands/{$role}.svg",
                    'primary_color' => '#123ABC',
                    'include_logo' => true,
                ],
            ])->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.version', 1)
                ->assertJsonPath('data.updatedBy', $user->id)
                ->assertJsonPath('data.settings.logo', "brands/{$role}.svg");
        }
    }

    public function test_brand_state_is_shared_with_team_members_and_isolated_between_teams(): void
    {
        $editor = $this->teamUser('editor', 81);
        $teamAdmin = $this->teamUser('admin', 81);
        $outsideAdmin = $this->teamUser('admin', 82);

        Sanctum::actingAs($editor);
        $this->putJson('/api/studio/brand', [
            'version' => 0,
            'settings' => ['logo' => 'brands/team-81.svg'],
        ])->assertOk();

        Sanctum::actingAs($teamAdmin);
        $this->getJson('/api/studio/brand')
            ->assertOk()
            ->assertJsonPath('data.settings.logo', 'brands/team-81.svg')
            ->assertJsonPath('data.version', 1);

        Sanctum::actingAs($outsideAdmin);
        $this->getJson('/api/studio/brand')
            ->assertOk()
            ->assertJsonPath('data.teamId', 82)
            ->assertJsonPath('data.settings', [])
            ->assertJsonPath('data.version', 0);

        $this->putJson('/api/studio/brand', [
            'version' => 0,
            'settings' => ['logo' => 'brands/team-82.svg'],
        ])->assertOk();

        $this->assertSame('brands/team-81.svg', BrandState::find(81)->settings['logo']);
        $this->assertSame('brands/team-82.svg', BrandState::find(82)->settings['logo']);
    }

    public function test_update_rejects_unsupported_or_invalid_settings_without_changing_committed_state(): void
    {
        $admin = $this->teamUser('admin', 91);
        Sanctum::actingAs($admin);

        $this->putJson('/api/studio/brand', [
            'version' => 0,
            'settings' => ['logo' => 'brands/committed.svg', 'primary_color' => '#112233'],
        ])->assertOk();

        $this->putJson('/api/studio/brand', [
            'version' => 1,
            'settings' => ['tracking_script' => '<script>bad()</script>'],
        ])->assertUnprocessable()
            ->assertJsonStructure(['message']);

        $this->putJson('/api/studio/brand', [
            'version' => 1,
            'settings' => ['primary_color' => 'not-a-color'],
        ])->assertUnprocessable()
            ->assertJsonStructure(['message']);

        $committed = BrandState::findOrFail(91);
        $this->assertSame(1, $committed->version);
        $this->assertSame([
            'logo' => 'brands/committed.svg',
            'primary_color' => '#112233',
        ], $committed->settings);
    }

    public function test_stale_update_is_rejected_with_latest_committed_version_and_workflow_state(): void
    {
        $editor = $this->teamUser('editor', 101);
        $manager = $this->teamUser('editing_manager', 101);

        Sanctum::actingAs($editor);
        $this->putJson('/api/studio/brand', [
            'version' => 0,
            'settings' => ['logo' => 'brands/v1.svg'],
        ])->assertOk()->assertJsonPath('data.version', 1);

        Sanctum::actingAs($manager);
        $this->putJson('/api/studio/brand', [
            'version' => 1,
            'settings' => ['logo' => 'brands/v2.svg', 'font_family' => 'Inter'],
        ])->assertOk()->assertJsonPath('data.version', 2);

        Sanctum::actingAs($editor);
        $this->putJson('/api/studio/brand', [
            'version' => 1,
            'settings' => ['logo' => 'brands/stale.svg'],
        ])->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'stale_version')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.logo', 'brands/v2.svg')
            ->assertJsonPath('data.settings.font_family', 'Inter');

        $workflowBrand = BrandState::latestCommittedForTeam(101);
        $this->assertNotNull($workflowBrand);
        $this->assertSame(2, $workflowBrand->version);
        $this->assertSame('brands/v2.svg', $workflowBrand->settings['logo']);
    }

    public function test_unauthenticated_and_unsupported_roles_are_rejected(): void
    {
        $this->getJson('/api/studio/brand')->assertUnauthorized();

        $client = $this->teamUser('client', 121);
        Sanctum::actingAs($client);
        $this->getJson('/api/studio/brand')->assertForbidden();
        $this->putJson('/api/studio/brand', [
            'version' => 0,
            'settings' => ['logo' => 'brands/forbidden.svg'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('brand_state', ['team_id' => 121]);
    }

    private function teamUser(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }
}
