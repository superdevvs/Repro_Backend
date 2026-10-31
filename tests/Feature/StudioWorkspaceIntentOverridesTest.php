<?php

namespace Tests\Feature;

use App\Models\StudioWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudioWorkspaceIntentOverridesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config([
            'services.autoenhance.api_key' => 'fixture-autoenhance',
            'services.fal.key' => 'fixture-fal',
            'services.openai.api_key' => 'fixture-openai',
            'studio_providers.virtualstagingai.api_key' => 'fixture-staging',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    #[DataProvider('explicitFields')]
    public function test_prompt_overrides_contain_only_inferred_fields(array $input, array $expected, string $status = 'ready'): void
    {
        $response = $this->postJson('/api/studio/workspaces/intent', $input + ['mediaCount' => 1])
            ->assertOk()->assertJsonPath('data.status', $status);
        $this->assertSame($expected, $response->json('data.configOverrides'));
        $this->assertInstanceOf(\stdClass::class, json_decode($response->getContent())->data->configOverrides);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(0, StudioWorkspace::count());
    }

    public static function explicitFields(): array
    {
        return [
            'blank photo preset' => [['prompt' => '', 'presetId' => 'listing-ready'], []],
            'blank video preset' => [['prompt' => '  ', 'presetId' => 'property-reel'], []],
            'duration only' => [['prompt' => '15 seconds', 'presetId' => 'property-reel'], ['prompt' => '15 seconds', 'duration' => 15]],
            'resolution only' => [['prompt' => '720p', 'presetId' => 'property-reel'], ['prompt' => '720p', 'resolution' => '720p']],
            'explicit default resolution still overrides' => [['prompt' => '1080p', 'presetId' => 'property-reel'], ['prompt' => '1080p', 'resolution' => '1080p']],
            'ratio only' => [['prompt' => 'Use 4:5', 'presetId' => 'property-reel'], ['prompt' => 'Use 4:5', 'ratio' => '4:5']],
            'music does not clear a selected track' => [['prompt' => 'With music', 'presetId' => 'property-reel'], ['prompt' => 'With music'], 'needs_input'],
            'sound retains chosen music preferences' => [['prompt' => 'With sound', 'presetId' => 'property-reel'], ['prompt' => 'With sound', 'audio' => ['original' => true]]],
            'explicit mute clears track only' => [['prompt' => 'Without audio', 'presetId' => 'property-reel'], ['prompt' => 'Without audio', 'audio' => ['original' => false, 'musicId' => null]]],
            'remove music preserves original sound preference' => [['prompt' => 'No music', 'presetId' => 'property-reel'], ['prompt' => 'No music', 'audio' => ['musicId' => null]]],
            'staging without specific room or style' => [['prompt' => 'Virtual staging'], ['prompt' => 'Virtual staging']],
            'staging room and style only' => [['prompt' => 'Stage this bedroom in coastal style'], ['prompt' => 'Stage this bedroom in coastal style', 'adjustments' => ['roomType' => 'bed', 'furnitureStyle' => 'coastal']]],
            'furniture removal is explicit' => [['prompt' => 'Replace furniture in bedroom'], ['prompt' => 'Replace furniture in bedroom', 'adjustments' => ['roomType' => 'bed', 'removal' => 'on']]],
            'preset choice is separate from config' => [['prompt' => 'Make this twilight', 'mode' => 'video'], ['prompt' => 'Make this twilight']],
            'chat remains clarification' => [['prompt' => 'What is twilight?'], ['prompt' => 'What is twilight?'], 'needs_input'],
        ];
    }

    public function test_sparse_overrides_preserve_saved_recipe_and_legacy_config_stays_default_filled(): void
    {
        $saved = ['prompt' => 'Saved instructions', 'ratio' => '4:5', 'duration' => 45, 'resolution' => '720p',
            'transition' => 'fade', 'audio' => ['original' => false, 'musicId' => 'selected-workspace-track', 'musicVolume' => 0.7, 'fade' => false]];
        $empty = $this->postJson('/api/studio/workspaces/intent', ['presetId' => 'property-reel', 'mediaCount' => 3])
            ->assertOk()->assertJsonPath('data.config.resolution', '1080p')->assertJsonPath('data.config.duration', 30)
            ->assertJsonPath('data.config.audio.musicId', null)->assertJsonPath('data.config.audio.musicVolume', 0.35);
        $this->assertSame($saved, array_replace_recursive($saved, $empty->json('data.configOverrides')));

        $duration = $this->postJson('/api/studio/workspaces/intent', ['presetId' => 'property-reel', 'mediaCount' => 3, 'prompt' => '15 seconds'])->assertOk();
        $this->assertSame(array_replace($saved, ['prompt' => '15 seconds', 'duration' => 15]), array_replace_recursive($saved, $duration->json('data.configOverrides')));

        $sound = $this->postJson('/api/studio/workspaces/intent', ['presetId' => 'property-reel', 'mediaCount' => 3, 'prompt' => 'With sound'])->assertOk();
        $merged = array_replace_recursive($saved, $sound->json('data.configOverrides'));
        $this->assertSame(array_replace($saved['audio'], ['original' => true]), $merged['audio']);
        $this->assertSame($saved['resolution'], $merged['resolution']);

        $muted = $this->postJson('/api/studio/workspaces/intent', ['presetId' => 'property-reel', 'mediaCount' => 3, 'prompt' => 'No sound'])->assertOk();
        $this->assertSame(array_replace($saved['audio'], ['original' => false, 'musicId' => null]), array_replace_recursive($saved, $muted->json('data.configOverrides'))['audio']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, StudioWorkspace::count());
    }
}
