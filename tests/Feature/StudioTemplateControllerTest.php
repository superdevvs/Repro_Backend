<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Targeted endpoint coverage for Studio Template CRUD.
 *
 * Validates: Requirements 10.3, 13.9, 13.10, 13.18, 16.8
 */
class StudioTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/templates';

    public function test_list_is_scoped_to_editor_ownership_and_privileged_team_access(): void
    {
        $editor = $this->actor('editor', 51);
        $peer = $this->actor('editor', 51);
        $outsider = $this->actor('editor', 52);
        $admin = $this->actor('admin', 51);

        $own = $this->template(51, $editor, 'Own Template');
        $team = $this->template(51, $peer, 'Team Template');
        $outside = $this->template(52, $outsider, 'Outside Template');

        Sanctum::actingAs($editor);
        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $own->id)
            ->assertJsonMissing(['id' => (string) $team->id])
            ->assertJsonMissing(['id' => (string) $outside->id]);

        Sanctum::actingAs($admin);
        $response = $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing(
            [(string) $own->id, (string) $team->id],
            collect($response->json('data'))->pluck('id')->all()
        );
        $response->assertJsonMissing(['id' => (string) $outside->id]);
    }
    public function test_create_persists_server_scope_and_returns_project_application_contract(): void
    {
        $editor = $this->actor('editor', 61);
        Sanctum::actingAs($editor);
        $config = [
            'targetSeconds' => 30,
            'transitions' => ['crossfade'],
            'includeBranding' => true,
        ];

        $response = $this->postJson(self::URL, [
            'name' => 'Listing Launch',
            'workflowId' => 'listing-video',
            'config' => $config,
            'team_id' => 999,
            'created_by' => 999,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Listing Launch')
            ->assertJsonPath('data.workflowId', 'listing-video')
            ->assertJsonPath('data.config', $config)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.createdBy', $editor->id)
            ->assertJsonPath('data.projectDefaults.workflowId', 'listing-video')
            ->assertJsonPath('data.projectDefaults.workflowConfig', $config);

        $template = Template::query()->findOrFail($response->json('data.id'));
        $this->assertSame(61, $template->team_id);
        $this->assertSame($editor->id, $template->created_by);
        $this->assertSame($config, $template->config);
        $this->assertSame((string) $template->id, $response->json('data.projectDefaults.templateId'));
    }

    public function test_update_applies_persisted_config_and_returns_the_committed_version(): void
    {
        $editor = $this->actor('editor', 71);
        $template = $this->template(71, $editor, 'Original');
        Sanctum::actingAs($editor);
        $config = ['style' => 'warm', 'strength' => 80];

        $this->putJson(self::URL . '/' . $template->id, [
            'name' => 'Warm Twilight',
            'workflowId' => 'twilight',
            'config' => $config,
            'version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.config', $config)
            ->assertJsonPath('data.projectDefaults.templateId', (string) $template->id)
            ->assertJsonPath('data.projectDefaults.workflowId', 'twilight')
            ->assertJsonPath('data.projectDefaults.workflowConfig', $config);

        $template->refresh();
        $this->assertSame(2, $template->version);
        $this->assertSame('twilight', $template->workflow_id);
        $this->assertSame($config, $template->config);
    }
    public function test_stale_update_and_delete_return_latest_committed_state_without_mutation(): void
    {
        $editor = $this->actor('editor', 81);
        $template = $this->template(81, $editor, 'Committed');
        Sanctum::actingAs($editor);

        $this->putJson(self::URL . '/' . $template->id, [
            'name' => 'Committed Version Two',
            'workflowId' => 'photo-enhancement',
            'config' => ['exposure' => 15],
            'version' => 1,
        ])->assertOk()->assertJsonPath('data.version', 2);

        $stalePayload = [
            'name' => 'Stale Overwrite',
            'workflowId' => 'batch-ai-jobs',
            'config' => ['exposure' => -100],
            'version' => 1,
        ];
        $this->putJson(self::URL . '/' . $template->id, $stalePayload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_version')
            ->assertJsonPath('error.latestVersion', 2)
            ->assertJsonPath('data.name', 'Committed Version Two')
            ->assertJsonPath('data.version', 2);

        $this->deleteJson(self::URL . '/' . $template->id, ['version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.latestVersion', 2);
        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'name' => 'Committed Version Two',
            'version' => 2,
        ]);

        $this->deleteJson(self::URL . '/' . $template->id, ['version' => 2])
            ->assertOk()
            ->assertJsonPath('data.id', (string) $template->id)
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.version', 2);
        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    public function test_mutations_reject_out_of_scope_records_and_invalid_workflows(): void
    {
        $editor = $this->actor('editor', 91);
        $peer = $this->actor('editor', 91);
        $peerTemplate = $this->template(91, $peer, 'Peer Secret');
        Sanctum::actingAs($editor);

        $this->putJson(self::URL . '/' . $peerTemplate->id, [
            'name' => 'Unauthorized',
            'workflowId' => 'photo-enhancement',
            'config' => [],
            'version' => 1,
        ])->assertForbidden();

        $this->postJson(self::URL, [
            'name' => 'Unsupported',
            'workflowId' => 'unknown-workflow',
            'config' => [],
        ])->assertUnprocessable()->assertJsonStructure(['message']);
        $this->assertSame('Peer Secret', $peerTemplate->fresh()->name);
    }
    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function template(int $teamId, User $owner, string $name): Template
    {
        return Template::query()->create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'name' => $name,
            'workflow_id' => 'photo-enhancement',
            'config' => ['exposure' => 10],
        ]);
    }
}
