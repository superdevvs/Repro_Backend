<?php

namespace Tests\Feature;

use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\PhotoPresetOptions;
use App\Services\Studio\WorkspaceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudioWorkspaceCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config([
            'studio.client_access_enabled' => false,
            'services.autoenhance.api_key' => 'fixture-autoenhance', 'services.fal.key' => 'fixture-fal',
            'services.openai.api_key' => 'fixture-openai', 'studio_providers.virtualstagingai.api_key' => 'fixture-staging',
            'studio_providers.fotello.api_key' => 'fixture-fotello', 'studio_providers.fotello.team_id' => 'fixture-team',
        ]);
    }

    public function test_catalog_matches_executable_presets_and_honest_controls_without_secrets(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $response = $this->getJson('/api/studio/workspaces/catalog')->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonCount(12, 'data.presets')
            ->assertJsonPath('data.controls.audio', class_exists(\App\Services\Studio\WorkspaceVideoOptions::class))
            ->assertJsonPath('data.controls.selectableResolution', class_exists(\App\Services\Studio\WorkspaceVideoOptions::class))
            ->assertJsonPath('data.controls.templateScopes', Schema::hasColumns('studio_templates', ['visibility', 'preset_id']) ? ['curated', 'mine', 'team'] : ['curated']);
        $this->assertSame(WorkspaceCatalog::PRESETS, array_column($response->json('data.presets'), 'id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['fixture-autoenhance', 'fixture-fal', 'fixture-staging', 'fixture-fotello', 'fixture-team', 'provider', 'api_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        foreach ($response->json('data.templates') as $template) {
            $this->assertContains($template['presetId'], WorkspaceCatalog::PRESETS);
            $options = $template['config']['adjustments'] ?? [];
            $this->assertSame($options, PhotoPresetOptions::normalize($template['presetId'], $options));
        }
        foreach (['Evening glow', 'Natural lawn', 'Modern living', 'Listing polish', 'Room by room', 'First impression'] as $label) {
            $this->assertContains($label, array_column($response->json('data.templates'), 'label'));
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(0, StudioWorkspace::count());
    }

    public function test_catalog_and_intent_keep_auth_role_and_client_rollout_gates(): void
    {
        $this->getJson('/api/studio/workspaces/catalog')->assertUnauthorized();
        $this->postJson('/api/studio/workspaces/intent', ['mediaCount' => 1])->assertUnauthorized();
        foreach (['photographer', 'client'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/studio/workspaces/catalog')->assertForbidden();
            $this->postJson('/api/studio/workspaces/intent', ['mediaCount' => 1])->assertForbidden();
        }
        config(['studio.client_access_enabled' => true]);
        $this->getJson('/api/studio/workspaces/catalog')->assertOk();
        $this->postJson('/api/studio/workspaces/intent', ['mediaCount' => 1, 'prompt' => 'Make this brighter'])->assertOk()->assertJsonPath('data.presetId', 'listing-ready');
        foreach (['admin', 'superadmin', 'editing_manager', 'editor'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/studio/workspaces/catalog')->assertOk();
        }
    }

    #[DataProvider('intentCases')]
    public function test_intent_plans_real_operations_without_submitting(array $input, string $status, ?string $preset): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/studio/workspaces/intent', $input)->assertOk()
            ->assertJsonPath('data.status', $status)->assertJsonPath('data.presetId', $preset)
            ->assertJsonPath('data.config.prompt', trim($input['prompt'] ?? ''));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(0, StudioWorkspace::count());
    }

    public static function intentCases(): array
    {
        return [
            'twilight wins navigation mode' => [['prompt' => 'Make this twilight', 'mode' => 'video', 'mediaCount' => 1], 'ready', 'twilight'],
            'multi photo reel wins navigation mode' => [['prompt' => 'Turn these photos into a 15 second reel', 'mode' => 'photo', 'mediaCount' => 3], 'ready', 'property-reel'],
            'generic removal' => [['prompt' => 'Remove this chair', 'mode' => 'photo', 'mediaCount' => 1], 'ready', 'listing-ready'],
            'generic brightness' => [['prompt' => 'Make it brighter', 'mediaCount' => 1], 'ready', 'listing-ready'],
            'generic enhancement' => [['prompt' => 'Make it better', 'mediaCount' => 1], 'ready', 'listing-ready'],
            'edit phrased as question' => [['prompt' => 'Can you make this brighter?', 'mediaCount' => 1], 'ready', 'listing-ready'],
            'plain business chat with photo' => [['prompt' => 'Who is my photographer?', 'mediaCount' => 1], 'needs_input', null],
            'image question with photo' => [['prompt' => 'What is in this image?', 'mode' => 'photo', 'mediaCount' => 1], 'needs_input', null],
            'effect question is not a request' => [['prompt' => 'What is twilight?', 'mediaCount' => 1], 'needs_input', null],
            'smart apostrophe question' => [['prompt' => 'What’s twilight?', 'mediaCount' => 1], 'needs_input', null],
            'staging advice is not a request' => [['prompt' => 'Does this need virtual staging?', 'mediaCount' => 1], 'needs_input', null],
            'video mood respects context' => [['prompt' => 'Make it cinematic', 'mode' => 'video', 'mediaCount' => 1], 'ready', 'walkthrough'],
            'video motion respects context' => [['prompt' => 'Gentle natural movement', 'mode' => 'video', 'mediaCount' => 2], 'ready', 'property-reel'],
            'empty photo' => [['prompt' => '', 'mode' => 'photo', 'mediaCount' => 1], 'ready', 'listing-ready'],
            'empty video' => [['prompt' => '', 'mode' => 'video', 'mediaCount' => 1], 'needs_input', null],
            'single animation' => [['prompt' => 'Animate this photo', 'mediaCount' => 1], 'ready', 'walkthrough'],
            'explicit preset' => [['prompt' => '', 'presetId' => 'green-grass', 'mediaCount' => 1], 'ready', 'green-grass'],
            'conflicting preset' => [['prompt' => 'Make a walkthrough', 'presetId' => 'twilight', 'mediaCount' => 1], 'needs_input', null],
            'compatible photo effects' => [['prompt' => 'Twilight and green grass', 'mediaCount' => 1], 'ready', 'twilight'],
            'photo alternatives' => [['prompt' => 'Twilight or green grass', 'mediaCount' => 1], 'needs_input', null],
            'unrelated negative twilight' => [['prompt' => 'Make twilight but do not change the house or windows', 'mediaCount' => 1], 'ready', 'twilight'],
            'unrelated negative grass' => [['prompt' => 'Green grass without altering the driveway', 'mediaCount' => 1], 'ready', 'green-grass'],
            'mixed output modes' => [['prompt' => 'Twilight and a walkthrough', 'mediaCount' => 1], 'needs_input', null],
            'selected photo clear video' => [['prompt' => 'Animate this photo', 'presetId' => 'twilight', 'mediaCount' => 1], 'needs_input', null],
            'negated effect' => [['prompt' => "Don't turn this into twilight", 'mediaCount' => 1], 'needs_input', null],
            'negated effect smart apostrophe' => [['prompt' => 'Don’t replace the sky', 'mediaCount' => 1], 'needs_input', null],
            'full shoot plan only' => [['prompt' => 'Edit the full shoot', 'mediaCount' => 150], 'ready', 'full-shoot'],
            'no media' => [['prompt' => 'Twilight', 'mediaCount' => 0], 'needs_input', 'twilight'],
            'too many video sources' => [['prompt' => 'Make a reel', 'mediaCount' => 13], 'needs_input', 'property-reel'],
            'unavailable music control' => [['prompt' => 'Make a reel with music', 'mediaCount' => 3], 'needs_input', 'property-reel'],
            'unsupported length' => [['prompt' => 'Make a 200 second reel', 'mediaCount' => 2], 'needs_input', null],
        ];
    }

    public function test_plan_preserves_requested_duration_ratio_and_full_shoot_confirmation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Make a 10 second reel in 4:5', 'mediaCount' => 2])
            ->assertOk()->assertJsonPath('data.config.duration', 10)->assertJsonPath('data.config.ratio', '4:5')
            ->assertJsonPath('data.requiresPreparation', true)->assertJsonPath('data.mode', 'video');
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Full shoot', 'mediaCount' => 300])
            ->assertOk()->assertJsonPath('data.requiresConfirmation', true);
        Http::assertNothingSent();
    }

    public function test_free_text_checks_revision_readiness_as_well_as_photo_enhancement(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        config(['services.fal.key' => null]);
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Remove this chair', 'mediaCount' => 1])
            ->assertOk()->assertJsonPath('data.status', 'unavailable');
        $this->postJson('/api/studio/workspaces/intent', ['presetId' => 'listing-ready', 'mediaCount' => 1])
            ->assertOk()->assertJsonPath('data.status', 'ready');
        config(['services.autoenhance.api_key' => null, 'services.fal.key' => 'fixture-fal']);
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Remove this chair', 'mediaCount' => 1])
            ->assertOk()->assertJsonPath('data.status', 'unavailable');
    }

    public function test_staging_prompt_maps_room_and_style_to_real_provider_options(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Virtual staging for a coastal bedroom', 'mediaCount' => 1])
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.config.adjustments.roomType', 'bed')
            ->assertJsonPath('data.config.adjustments.furnitureStyle', 'coastal');
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Stage this bedroom in coastal style', 'mediaCount' => 1])
            ->assertOk()->assertJsonPath('data.presetId', 'virtual-staging')->assertJsonPath('data.config.adjustments.roomType', 'bed')
            ->assertJsonPath('data.config.adjustments.furnitureStyle', 'coastal');
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Virtual staging in a mid-century modern living room', 'mediaCount' => 1])
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.config.adjustments.furnitureStyle', 'midcentury');
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Virtual staging for bedroom and kitchen', 'mediaCount' => 2])
            ->assertOk()->assertJsonPath('data.status', 'needs_input')->assertJsonPath('data.reason', 'ambiguous_room');
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Virtual staging in coastal or luxury style', 'mediaCount' => 1])
            ->assertOk()->assertJsonPath('data.status', 'needs_input')->assertJsonPath('data.reason', 'ambiguous_style');
        Http::assertNothingSent();
    }

    public function test_invalid_inputs_cannot_inject_routes_or_unbounded_work(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'editor']));
        foreach ([['mediaCount' => 301], ['mediaCount' => -1], ['mediaCount' => 1, 'presetId' => 'custom-provider'], ['mediaCount' => 1, 'prompt' => str_repeat('x', 4001)]] as $input) {
            $this->postJson('/api/studio/workspaces/intent', $input)->assertUnprocessable();
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_video_finishing_intent_uses_installed_capabilities_and_requires_a_real_music_selection(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        if (! class_exists(\App\Services\Studio\WorkspaceVideoOptions::class)) {
            $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Make a reel in 720p with sound', 'mediaCount' => 2])
                ->assertOk()->assertJsonPath('data.status', 'needs_input')->assertJsonPath('data.reason', 'unsupported_video_control');

            return;
        }
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Make a reel in 720p with sound', 'mediaCount' => 2])
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.config.resolution', '720p')->assertJsonPath('data.config.audio.original', true);
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Make a reel with music', 'mediaCount' => 2])
            ->assertOk()->assertJsonPath('data.status', 'needs_input')->assertJsonPath('data.reason', 'music_selection')->assertJsonPath('data.config.audio.musicId', null);
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Make a reel without audio', 'mediaCount' => 2])
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.config.audio.original', false);
        $this->postJson('/api/studio/workspaces/intent', ['prompt' => 'Make a reel in 4k with voiceover', 'mediaCount' => 2])
            ->assertOk()->assertJsonPath('data.status', 'needs_input')->assertJsonPath('data.reason', 'unsupported_video_control');
        Http::assertNothingSent();
    }
}
