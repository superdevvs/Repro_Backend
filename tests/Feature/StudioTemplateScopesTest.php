<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\StudioTemplateRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioTemplateScopesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/templates';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_new_private_recipe_is_hidden_from_peer_admin_search_deep_link_and_submission(): void
    {
        $owner = $this->actor('editor');
        $admin = $this->actor('admin');
        Sanctum::actingAs($owner);
        $template = $this->postJson(self::URL, [
            'name' => 'Private unreleased campaign', 'presetId' => 'twilight',
            'config' => ['prompt' => 'Private owner direction'],
        ])->assertCreated()->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.workflowId', 'twilight')->json('data');

        Sanctum::actingAs($admin);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/studio/search?q=unreleased')->assertOk()
            ->assertJsonMissing(['id' => $template['id']])->assertDontSee('Private owner direction');
        $this->postJson('/api/studio/deep-links/resolve', [
            'destination' => 'templates', 'recordType' => 'template', 'recordId' => $template['id'],
        ])->assertForbidden()->assertDontSee('Private owner direction');
        $this->putJson(self::URL.'/'.$template['id'], ['version' => 1, 'name' => 'Stolen'])->assertForbidden();
        $this->deleteJson(self::URL.'/'.$template['id'], ['version' => 1])->assertForbidden();
        $this->postJson(self::URL.'/'.$template['id'].'/duplicate', ['version' => 1])->assertForbidden();
        // Authorization must fail before source resolution or any provider job creation.
        $this->postJson('/api/studio/projects', [
            'requestId' => 'private-template-probe', 'workflowId' => 'twilight',
            'sourceType' => 'upload', 'mediaRefs' => ['unresolved-test-source'],
            'workflowConfig' => [], 'templateId' => $template['id'],
        ])->assertForbidden();
    }

    public function test_shared_team_recipe_is_readable_but_only_owner_can_edit_and_copy_is_private(): void
    {
        $owner = $this->actor('editor');
        $peer = $this->actor('editor');
        $admin = $this->actor('admin');
        $outsider = $this->actor('admin', 502);
        $template = $this->template($owner, 'team');

        foreach ([$peer, $admin] as $reader) {
            Sanctum::actingAs($reader);
            $this->getJson(self::URL.'?scope=team')->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.isOwner', false)->assertJsonPath('data.0.canEdit', false)
                ->assertJsonPath('data.0.canDelete', false)->assertJsonPath('data.0.canShare', false);
            $this->putJson(self::URL.'/'.$template->id, ['version' => 1, 'name' => 'Changed'])->assertForbidden();
            $this->deleteJson(self::URL.'/'.$template->id, ['version' => 1])->assertForbidden();
        }
        Sanctum::actingAs($peer);
        $copy = $this->postJson(self::URL.'/'.$template->id.'/duplicate', ['version' => 1, 'name' => 'My variation'])
            ->assertCreated()->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.isOwner', true)
            ->assertJsonPath('data.createdBy', $peer->id)->assertJsonPath('data.version', 1)->json('data');
        $this->assertNotSame($template->id, $copy['id']);
        $this->assertSame(1, $template->fresh()->version);
        Sanctum::actingAs($outsider);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson(self::URL.'/'.$template->id.'/duplicate', ['version' => 1])->assertForbidden();
    }

    public function test_mine_team_filters_and_search_do_not_leak_private_or_legacy_peer_recipes(): void
    {
        $owner = $this->actor('editor');
        $peer = $this->actor('editor');
        $mine = $this->template($owner, 'private', 'My evening');
        $shared = $this->template($peer, 'team', 'Team evening');
        $this->template($peer, 'private', 'Secret evening');
        $this->template($peer, null, 'Old evening');
        Sanctum::actingAs($owner);

        $this->getJson(self::URL.'?scope=mine')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->getJson(self::URL.'?scope=team&q=evening')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $shared->id);
        $this->getJson(self::URL.'?scope=all')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::URL.'?q=Secret')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?scope=curated')->assertUnprocessable();
    }

    public function test_client_personal_templates_follow_rollout_and_cannot_read_or_share_staff_team_recipes(): void
    {
        $client = $this->actor('client');
        $peer = $this->actor('client');
        $staff = $this->actor('admin');
        $shared = $this->template($staff, 'team');
        $this->template($staff, null);
        $this->template($peer, 'private');
        $payload = ['name' => 'My lawn', 'presetId' => 'green-grass', 'config' => ['prompt' => 'Natural lawn']];
        Sanctum::actingAs($client);
        config(['studio.client_access_enabled' => false]);
        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, $payload)->assertForbidden();

        config(['studio.client_access_enabled' => true]);
        $own = $this->postJson(self::URL, $payload)->assertCreated()
            ->assertJsonPath('data.canShare', false)->json('data');
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own['id']);
        $this->getJson(self::URL.'?scope=team')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson(self::URL, [...$payload, 'visibility' => 'team'])->assertForbidden();
        $this->putJson(self::URL.'/'.$own['id'], ['version' => 1, 'visibility' => 'team'])->assertForbidden();
        $this->postJson(self::URL.'/'.$shared->id.'/duplicate', ['version' => 1])->assertForbidden();
        $this->putJson(self::URL.'/'.$own['id'], ['version' => 1, 'name' => 'Personal lawn'])->assertOk()->assertJsonPath('data.version', 2);
        // Moving template routes must not open administrative Studio endpoints to clients.
        $this->getJson('/api/studio/brand')->assertForbidden();
        $this->getJson('/api/studio/projects')->assertForbidden();
        config(['studio.client_access_enabled' => false]);
        $this->deleteJson(self::URL.'/'.$own['id'], ['version' => 2])->assertForbidden();
        $this->assertDatabaseHas('templates', ['id' => $own['id'], 'name' => 'Personal lawn']);
        config(['studio.client_access_enabled' => true]);
        $this->deleteJson(self::URL.'/'.$own['id'], ['version' => 2])->assertOk();
    }

    public function test_recipe_save_strips_source_identity_reviews_and_private_audio_but_keeps_parameters(): void
    {
        $owner = $this->actor('editor');
        Sanctum::actingAs($owner);
        $config = [
            'prompt' => 'Natural walkthrough', 'ratio' => '9:16', 'duration' => 15,
            'media' => [['id' => 'source-a', 'url' => 'https://private.test/image']],
            'shoot_id' => 11, 'workspaceId' => 'private-workspace', 'frames' => [['mediaId' => 'source-a']],
            'reviewedOutputIds' => ['result-a'], 'reviewedFrameIds' => ['frame-a'],
            'audio' => ['musicId' => 'private-audio', 'original' => false, 'musicVolume' => 0.35, 'fade' => true],
            'adjustments' => ['strength' => 70, 'nested' => ['referenceMediaIds' => ['private-ref'], 'previewUrl' => 'https://private.test/ref', 'keep' => true]],
            '_studioRuntime' => ['secret' => 'must not be copied'],
        ];
        $response = $this->postJson(self::URL, [
            'name' => 'Walkthrough recipe', 'presetId' => 'walkthrough', 'config' => $config,
            'team_id' => 999, 'created_by' => 999,
        ])->assertCreated()->assertJsonPath('data.workflowId', 'reel-generator')
            ->assertJsonPath('data.workspaceDefaults.presetId', 'walkthrough');
        $recipe = $response->json('data.config');
        $this->assertSame([
            'prompt' => 'Natural walkthrough', 'ratio' => '9:16', 'duration' => 15,
            'audio' => ['original' => false, 'musicVolume' => 0.35, 'fade' => true, 'musicId' => null],
            'adjustments' => ['strength' => 70, 'nested' => ['keep' => true]],
        ], $recipe);
        $this->assertSame($recipe, $response->json('data.workspaceDefaults.config'));
        $record = Template::findOrFail($response->json('data.id'));
        $this->assertSame($recipe, $record->config);
        $this->assertSame($owner->id, $record->created_by);
        $this->assertSame(501, $record->team_id);
    }

    public function test_rename_share_and_unshare_preserve_recipe_with_optimistic_conflicts(): void
    {
        $owner = $this->actor('editing_manager');
        $peer = $this->actor('editor');
        $record = $this->template($owner, 'private');
        Sanctum::actingAs($owner);
        $this->putJson(self::URL.'/'.$record->id, ['version' => 1, 'name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.presetId', 'twilight')
            ->assertJsonPath('data.config.prompt', 'Keep the property');
        $this->putJson(self::URL.'/'.$record->id, ['version' => 2, 'visibility' => 'team'])
            ->assertOk()->assertJsonPath('data.version', 3);
        $this->postJson(self::URL.'/'.$record->id.'/duplicate', ['version' => 2])
            ->assertStatus(409)->assertJsonPath('error.latestVersion', 3);
        $this->deleteJson(self::URL.'/'.$record->id, ['version' => 2])->assertStatus(409);
        $this->putJson(self::URL.'/'.$record->id, ['version' => 2, 'name' => 'Stale'])->assertStatus(409);
        Sanctum::actingAs($peer);
        $this->getJson(self::URL.'?scope=team')->assertOk()->assertJsonPath('meta.total', 1);
        Sanctum::actingAs($owner);
        $this->putJson(self::URL.'/'.$record->id, ['version' => 3, 'visibility' => 'private'])->assertOk();
        Sanctum::actingAs($peer);
        $this->getJson(self::URL.'?scope=team')->assertOk()->assertJsonPath('meta.total', 0);
        // Stale conflict responses must never disclose newly private recipe contents.
        $this->postJson(self::URL.'/'.$record->id.'/duplicate', ['version' => 3])->assertForbidden()->assertDontSee('Keep the property');
    }

    public function test_applying_saved_recipe_through_workspace_update_retains_destination_media_without_submission(): void
    {
        $owner = $this->actor('editor');
        Sanctum::actingAs($owner);
        Storage::fake('public');
        config(['studio_uploads.disk' => 'public']);
        $source = "studio/uploads/501/{$owner->id}/destination.jpg";
        Storage::disk('public')->put($source, UploadedFile::fake()->image('destination.jpg')->get());
        $media = [['id' => 'destination-photo', 'name' => 'Current property', 'mediaRef' => $source]];
        $frames = [['mediaId' => 'destination-photo', 'method' => 'crop', 'duration' => 5]];
        $workspace = StudioWorkspace::create([
            'team_id' => 501, 'created_by' => $owner->id, 'name' => 'Current property',
            'preset_id' => 'listing-ready', 'status' => 'draft', 'version' => 1, 'media' => $media,
            'config' => ['prompt' => 'Original direction', 'frames' => $frames,
                'reviewedOutputIds' => ['previous-output'], 'reviewedFrameIds' => ['previous-frame']],
        ]);
        $saved = $this->postJson(self::URL, [
            'name' => 'Natural polish', 'presetId' => 'listing-ready',
            'config' => ['prompt' => 'Balanced light', 'ratio' => '16:9',
                'media' => [['id' => 'different-property']], 'frames' => [['mediaId' => 'different-property']]],
        ])->assertCreated()->json('data.workspaceDefaults');
        $this->assertSame('Original direction', $workspace->fresh()->config['prompt']);
        $this->patchJson('/api/studio/workspaces/'.$workspace->id, [
            'version' => $workspace->version, 'presetId' => $saved['presetId'],
            'config' => [...$saved['config'], 'reviewedOutputIds' => [], 'reviewedFrameIds' => []],
        ])->assertOk();
        $workspace->refresh();
        $this->assertSame($media, $workspace->media);
        $this->assertSame($frames, $workspace->config['frames']);
        $this->assertSame('Balanced light', $workspace->config['prompt']);
        $this->assertSame([], $workspace->config['reviewedOutputIds']);
        $this->assertSame([], $workspace->config['reviewedFrameIds']);
        $this->assertSame('draft', $workspace->status);
    }

    public function test_legacy_access_is_preserved_without_allowing_admin_to_publish_another_owners_recipe(): void
    {
        $owner = $this->actor('editor');
        $admin = $this->actor('admin');
        $record = $this->template($owner, null);
        Sanctum::actingAs($admin);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.visibility', 'legacy')
            ->assertJsonPath('data.0.canEdit', true)->assertJsonPath('data.0.canShare', false);
        $this->putJson(self::URL.'/'.$record->id, ['version' => 1, 'name' => 'Legacy maintenance'])->assertOk();
        $this->putJson(self::URL.'/'.$record->id, ['version' => 2, 'visibility' => 'team'])->assertForbidden();
        $this->assertNull($record->fresh()->visibility);
    }

    public function test_legacy_web_creation_preserves_existing_privileged_team_management_only(): void
    {
        $owner = $this->actor('editor');
        Sanctum::actingAs($owner);
        $created = $this->postJson(self::URL, [
            'name' => 'Legacy web recipe', 'workflowId' => 'photo-enhancement',
            'config' => ['strength' => 70],
        ])->assertCreated()->assertJsonPath('data.visibility', 'legacy')->json('data');
        $version = 1;
        foreach (['admin', 'superadmin', 'editing_manager'] as $role) {
            Sanctum::actingAs($this->actor($role));
            $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.id', $created['id'])
                ->assertJsonPath('data.0.canEdit', true)->assertJsonPath('data.0.canShare', false);
            $this->putJson(self::URL.'/'.$created['id'], ['version' => $version, 'name' => 'Maintained by '.$role])
                ->assertOk()->assertJsonPath('data.version', ++$version)
                ->assertJsonPath('data.visibility', 'legacy')->assertJsonPath('data.createdBy', $owner->id);
        }
        foreach ([$this->actor('editor'), $this->actor('admin', 502)] as $denied) {
            Sanctum::actingAs($denied);
            $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
            $this->putJson(self::URL.'/'.$created['id'], ['version' => $version, 'name' => 'Not allowed'])->assertForbidden();
            $this->deleteJson(self::URL.'/'.$created['id'], ['version' => $version])->assertForbidden();
        }
    }

    public function test_explicit_private_and_any_native_preset_key_do_not_use_legacy_default(): void
    {
        $owner = $this->actor('editor');
        $peer = $this->actor('admin');
        foreach ([['visibility' => 'private'], ['presetId' => 'twilight'], ['presetId' => null]] as $scope) {
            Sanctum::actingAs($owner);
            $created = $this->postJson(self::URL, [
                'name' => 'Personal direction', 'workflowId' => 'twilight',
                'config' => ['prompt' => 'Private direction'], ...$scope,
            ])->assertCreated()->assertJsonPath('data.visibility', 'private')->json('data');
            Sanctum::actingAs($peer);
            $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0)->assertDontSee('Private direction');
            $this->putJson(self::URL.'/'.$created['id'], ['version' => 1, 'name' => 'Not allowed'])->assertForbidden();
            $this->deleteJson(self::URL.'/'.$created['id'], ['version' => 1])->assertForbidden();
        }
        Sanctum::actingAs($owner);
        $this->postJson(self::URL, [
            'name' => 'Explicitly shared', 'workflowId' => 'twilight', 'visibility' => 'team', 'config' => [],
        ])->assertCreated()->assertJsonPath('data.visibility', 'team');
    }

    public function test_client_workflow_only_creation_stays_private_and_rollout_gated(): void
    {
        $client = $this->actor('client');
        $payload = ['name' => 'Personal legacy-shaped recipe', 'workflowId' => 'twilight', 'config' => []];
        Sanctum::actingAs($client);
        config(['studio.client_access_enabled' => false]);
        $this->postJson(self::URL, $payload)->assertForbidden();
        config(['studio.client_access_enabled' => true]);
        $created = $this->postJson(self::URL, $payload)->assertCreated()
            ->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.canShare', false)->json('data');
        $this->postJson(self::URL, [...$payload, 'visibility' => 'team'])->assertForbidden();
        Sanctum::actingAs($this->actor('admin'));
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 0);
        $this->putJson(self::URL.'/'.$created['id'], ['version' => 1, 'name' => 'Not allowed'])->assertForbidden();
        $this->deleteJson(self::URL.'/'.$created['id'], ['version' => 1])->assertForbidden();
    }

    public function test_native_presets_map_to_real_workflows_without_running_them_and_invalid_inputs_are_rejected(): void
    {
        Sanctum::actingAs($this->actor('editor'));
        foreach (StudioTemplateRecipe::PRESETS as $preset) {
            $this->postJson(self::URL, ['name' => $preset, 'presetId' => $preset, 'config' => []])
                ->assertCreated()->assertJsonPath('data.presetId', $preset)
                ->assertJsonPath('data.workflowId', StudioTemplateRecipe::workflow($preset));
        }
        $this->postJson(self::URL, ['name' => 'Mismatch', 'presetId' => 'twilight', 'workflowId' => 'listing-video', 'config' => []])->assertUnprocessable();
        $this->postJson(self::URL, ['name' => 'Bad', 'presetId' => 'invented-provider', 'config' => []])->assertUnprocessable();
        $this->postJson(self::URL, ['name' => '  ', 'presetId' => 'twilight', 'config' => []])->assertUnprocessable();
        $this->postJson(self::URL, ['name' => 'Oversized', 'presetId' => 'twilight', 'config' => ['prompt' => str_repeat('a', 66000)]])->assertUnprocessable();
        $this->postJson(self::URL, ['name' => 'Invalid duration', 'presetId' => 'walkthrough', 'config' => ['duration' => 0]])->assertUnprocessable();
        $this->postJson(self::URL, ['name' => 'Invalid volume', 'presetId' => 'walkthrough', 'config' => ['audio' => ['musicVolume' => 15]]])->assertUnprocessable();
    }

    private function actor(string $role, int $team = 501): User
    {
        return User::factory()->create(['role' => $role, 'metadata' => ['team_id' => $team]]);
    }

    private function template(User $owner, ?string $visibility, string $name = 'Evening recipe'): Template
    {
        return Template::create([
            'team_id' => $owner->metadata['team_id'], 'created_by' => $owner->id, 'name' => $name,
            'visibility' => $visibility, 'preset_id' => 'twilight', 'workflow_id' => 'twilight',
            'config' => ['prompt' => 'Keep the property'],
        ]);
    }
}
