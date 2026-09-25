<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioProviderSetting;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\Providers\VirtualStagingAiException;
use App\Services\Studio\VirtualStagingOptions;
use App\Services\Studio\VirtualStagingProcessor;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class VirtualStagingAiTest extends TestCase
{
    use RefreshDatabase;

    private string $jpeg;

    private const KEY = 'vsai-test-key';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'studio.client_access_enabled' => false,
            'studio_uploads.disk' => 'public',
            'services.fal.key' => 'fal-fixture-secret',
            'studio_providers.virtualstagingai.api_key' => self::KEY,
            'studio_providers.virtualstagingai.poll_interval' => 0,
            'studio_providers.virtualstagingai.poll_timeout' => 5,
        ]);
        $this->jpeg = $this->image();
    }

    public function test_staging_options_cover_removal_masks_resolution_and_base_variations(): void
    {
        $removed = VirtualStagingOptions::config(VirtualStagingOptions::normalize([
            'removal' => 'on', 'addFurniture' => false, 'roomType' => 'bed', 'furnitureStyle' => 'coastal',
        ]), null, null);
        $this->assertSame(['type' => 'staging', 'remove_furniture' => ['mode' => 'on']], $removed);

        $aliased = VirtualStagingOptions::normalize(['roomType' => 'living-room', 'furnitureStyle' => 'editorial', 'resolution' => '1536', 'watermark' => true, 'removal' => 'auto']);
        $staged = VirtualStagingOptions::config($aliased, 'data:image/png;base64,abc', null);
        $this->assertSame('living', $aliased['roomType']);
        $this->assertSame('modern', $aliased['style']);
        $this->assertSame(1536, $staged['output_resolution']);
        $this->assertTrue($staged['add_virtually_staged_watermark']);
        $this->assertSame('auto', $staged['remove_furniture']['mode']);
        $this->assertSame('data:image/png;base64,abc', $staged['remove_furniture']['mask_url']);

        $again = VirtualStagingOptions::config(VirtualStagingOptions::normalize(['removal' => 'on']), null, 'var_removal');
        $this->assertSame('var_removal', $again['add_furniture']['base_variation_id']);
        $this->assertArrayNotHasKey('remove_furniture', $again);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        VirtualStagingOptions::assert(['roomType' => 'castle']);
    }

    public function test_virtual_staging_downloads_results_and_adds_later_arrangements_on_the_same_render(): void
    {
        $workspace = $this->workspace();
        $posts = $this->fakeApi();
        app(WorkspaceProcessor::class)->process($workspace, 'operation-1');
        $workspace->refresh();
        $this->assertSame('completed', $workspace->status);
        $this->assertCount(1, $workspace->outputs);
        $this->assertSame('Staged · Modern Living room', $workspace->outputs[0]['label']);
        $this->assertSame('rnd_1', $workspace->outputs[0]['vsai']['renderId']);
        $this->assertArrayNotHasKey('vsai', $workspace->present()['outputs'][0]);
        $this->assertNotFalse(@getimagesizefromstring(Storage::disk('public')->get($workspace->outputs[0]['path'])));
        $this->assertSame('data:image/jpeg;base64,', substr($posts->posts[0]['image_url'], 0, 23));
        $this->assertSame('living', $posts->posts[0]['config']['add_furniture']['room_type']);
        $this->assertArrayNotHasKey('remove_furniture', $posts->posts[0]['config']);
        $this->assertStringNotContainsString(self::KEY, json_encode($workspace->present()));

        $config = $workspace->config;
        $config['adjustments'] = ['roomType' => 'kitchen', 'furnitureStyle' => 'coastal', 'removal' => 'off', 'addFurniture' => true, 'variationCount' => 1];
        $workspace->update(['status' => 'generating', 'progress' => 0, 'config' => $config, 'operation' => [
            'id' => 'operation-2', 'type' => 'generate', 'payload' => [], 'completed' => [], 'requests' => [],
        ]]);
        app(WorkspaceProcessor::class)->process($workspace->fresh(), 'operation-2');
        $workspace->refresh();
        $this->assertCount(2, $posts->posts);
        $this->assertSame('coastal', $posts->posts[1]['config']['add_furniture']['style']);
        $this->assertSame('kitchen', $posts->posts[1]['config']['add_furniture']['room_type']);
        $this->assertCount(2, $workspace->outputs);
        $this->assertSame('rnd_1', $workspace->outputs[1]['vsai']['renderId']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/variations'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'fal.ai') || str_contains($request->url(), '127.0.0.1'));
    }

    public function test_finished_staging_is_saved_on_the_shoot_as_an_edited_photo(): void
    {
        $shoot = Shoot::factory()->create();
        $workspace = $this->workspace(['removal' => 'on', 'addFurniture' => true]);
        $media = $workspace->media;
        $media[0]['shootId'] = $shoot->id;
        $media[0]['name'] = 'living-room.jpg';
        $workspace->update(['media' => $media]);
        $this->fakeApi();

        app(VirtualStagingProcessor::class)->run($workspace->fresh(), 'operation-1', $workspace->fresh()->media);

        $edited = ShootFile::query()->where('shoot_id', $shoot->id)->where('media_type', 'edited')->get();
        $this->assertCount(1, $edited);
        $file = $edited->first();
        $this->assertSame(ShootFile::STAGE_COMPLETED, $file->workflow_stage);
        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $file->scan_status);
        $this->assertTrue($file->is_ai_edited);
        $this->assertSame('virtualstagingai', $file->ai_editing_metadata['provider']);
        $this->assertSame('staging', $file->ai_editing_metadata['variation_type']);
        $this->assertTrue(Storage::disk('local')->exists($file->storage_path));
        $this->assertSame(0, ShootFile::query()->where('shoot_id', $shoot->id)->where('ai_editing_metadata->variation_type', 'removal')->count());
    }

    public function test_removal_and_staging_keep_the_empty_room_and_reuse_it_as_the_base(): void
    {
        $workspace = $this->workspace(['removal' => 'on', 'addFurniture' => true, 'variationCount' => 1]);
        $posts = $this->fakeApi();
        app(VirtualStagingProcessor::class)->run($workspace, 'operation-1', $workspace->media);
        $workspace->refresh();
        $this->assertSame(['Furniture removed', 'Staged · Modern Living room'], array_column($workspace->outputs, 'label'));
        $workspace->update(['status' => 'generating', 'operation' => [
            'id' => 'operation-2', 'type' => 'generate', 'payload' => [], 'completed' => [], 'requests' => [],
        ]]);
        app(VirtualStagingProcessor::class)->run($workspace->fresh(), 'operation-2', $workspace->media);
        $this->assertSame('var_removal', $posts->posts[1]['config']['add_furniture']['base_variation_id']);
        $this->assertArrayNotHasKey('remove_furniture', $posts->posts[1]['config']);
    }

    public function test_a_timed_out_render_resumes_without_creating_another_render(): void
    {
        $workspace = $this->workspace();
        $posts = $this->fakeApi();
        config(['studio_providers.virtualstagingai.poll_timeout' => 0]);
        try {
            app(VirtualStagingProcessor::class)->run($workspace, 'operation-1', $workspace->media);
            $this->fail('A render that had not been confirmed was stored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('longer than expected', $exception->getMessage());
        }
        $this->assertCount(1, $posts->posts);
        $this->assertNotEmpty($workspace->fresh()->operation['providerState']);
        config(['studio_providers.virtualstagingai.poll_timeout' => 5]);
        app(VirtualStagingProcessor::class)->run($workspace->fresh(), 'operation-1', $workspace->media);
        $this->assertCount(1, $posts->posts);
        $this->assertCount(1, $workspace->fresh()->outputs);
    }

    public function test_private_result_addresses_are_rejected_before_download(): void
    {
        $workspace = $this->workspace();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
            if ($request->method() === 'POST' && str_ends_with($path, '/renders')) {
                return Http::response(['id' => 'rnd_private', 'created_at' => 1, 'eta' => null, 'variations' => ['total_count' => 0, 'items' => []]], 201);
            }
            if ($request->method() === 'GET') {
                return Http::response($this->renderBody('rnd_private', [[
                    'id' => 'var_private', 'type' => 'staging', 'status' => 'done', 'render_id' => 'rnd_private',
                    'result' => ['url' => 'https://127.0.0.1/secret.jpg'],
                    'config' => ['type' => 'staging', 'add_furniture' => ['style' => 'modern', 'room_type' => 'living']],
                ]]));
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
        try {
            app(VirtualStagingProcessor::class)->run($workspace, 'operation-1', $workspace->media);
            $this->fail('A private result address was downloaded.');
        } catch (VirtualStagingAiException $exception) {
            $this->assertStringContainsString('invalid image address', $exception->getMessage());
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '127.0.0.1'));
        $this->assertSame([], $workspace->fresh()->outputs ?? []);
    }

    public function test_generate_requires_a_saved_key_and_supported_options_and_pins_the_provider(): void
    {
        config(['studio_providers.virtualstagingai.api_key' => null]);
        $user = $this->actor();
        $workspace = $this->createWorkspace($user);
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertStatus(422);
        Queue::assertNothingPushed();

        config(['studio_providers.virtualstagingai.api_key' => self::KEY]);
        $workspace->update(['config' => array_merge($workspace->config, ['adjustments' => ['roomType' => 'castle']])]);
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertStatus(422);
        Queue::assertNothingPushed();

        $workspace->update(['config' => array_merge($workspace->fresh()->config, ['adjustments' => ['roomType' => 'living-room', 'furnitureStyle' => 'editorial']])]);
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertStatus(202);
        $fresh = $workspace->fresh();
        $this->assertSame('virtualstagingai', $fresh->operation['routing']['virtual-staging']['provider']);
        $this->assertSame('staging', $fresh->operation['routing']['virtual-staging']['model']);
        Queue::assertPushed(ProcessStudioWorkspace::class);
        Http::assertNothingSent();
    }

    public function test_furniture_analysis_stores_a_mask_without_exposing_the_key(): void
    {
        $user = $this->actor();
        $workspace = $this->createWorkspace($user, 'virtual-staging');
        Http::fake(function (Request $request) {
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/analyze')) {
                return Http::response(['id' => 'an_1', 'created_at' => 1, 'status' => 'done', 'percentage_masked' => 18.2, 'result_url' => 'https://results.example.test/mask.jpg']);
            }
            if (str_starts_with($request->url(), 'https://results.example.test/')) {
                return Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
        $response = $this->postJson('/api/studio/workspaces/'.$workspace->id.'/furniture-analysis', ['mediaId' => 'm1'])
            ->assertOk()->assertJsonPath('data.percentageMasked', 18.2);
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertNotEmpty(Storage::disk('public')->allFiles('studio/workspaces/'.$workspace->id.'/masks'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Api-Key '.self::KEY));
    }

    public function test_superadmin_can_save_the_virtual_staging_key_without_it_appearing_in_responses(): void
    {
        $this->actor('superadmin');
        $secret = 'vsai-confidential-key';
        Http::fake([
            'https://api.virtualstagingai.app/v2/user' => Http::response([
                'user' => ['email' => 'owner@example.com', 'uid' => 'uid-1'],
                'photoLimit' => ['staging' => 100, 'imageEditing' => 'UNLIMITED'],
                'photosUsedThisPeriod' => ['staging' => 4, 'imageEditing' => 0],
            ]),
        ]);
        $saved = $this->putJson('/api/studio/provider-settings', ['credentials' => ['virtualStagingAi' => ['apiKey' => $secret]]])
            ->assertOk()->assertJsonPath('data.credentials.virtualStagingAi.keyConfigured', true)
            ->assertJsonPath('data.credentials.virtualStagingAi.usage.stagingUsed', 4)
            ->assertJsonPath('data.credentials.virtualStagingAi.usage.stagingLimit', 100);
        $this->assertStringNotContainsString($secret, $saved->getContent());
        $this->assertStringNotContainsString('owner@example.com', $saved->getContent());
        $raw = (string) \Illuminate\Support\Facades\DB::table('studio_provider_settings')->value('payload');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, StudioProviderSetting::findOrFail(1)->payload['credentials']['virtualStagingAi']['apiKey']);
        $this->putJson('/api/studio/provider-settings', ['services' => [['id' => 'virtual-staging', 'provider' => 'fal', 'model' => 'fal-ai/flux-kontext/dev']]])->assertUnprocessable();
        $this->assertSame('virtualstagingai', app(\App\Services\Studio\StudioProviderSettings::class)->route('virtual-staging')['provider']);
    }

    public function test_a_rejected_virtual_staging_key_is_not_saved(): void
    {
        $this->actor('superadmin');
        Http::fake(['https://api.virtualstagingai.app/v2/user' => Http::response(['message' => 'unauthorized'], 401)]);
        $this->putJson('/api/studio/provider-settings', ['credentials' => ['virtualStagingAi' => ['apiKey' => 'rejected-key']]])->assertUnprocessable();
        $this->assertSame(0, StudioProviderSetting::count());
    }

    private function fakeApi(): object
    {
        $bag = (object) ['posts' => []];
        $catalog = [];
        Http::fake(function (Request $request) use ($bag, &$catalog) {
            if ($request->hasHeader('Authorization', 'Api-Key '.self::KEY) === false && ! str_starts_with($request->url(), 'https://results.example.test/')) {
                return Http::response(['message' => 'missing key'], 401);
            }
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
            if ($request->method() === 'POST' && str_ends_with($path, '/renders')) {
                $body = $request->data();
                $bag->posts[] = $body;
                $catalog = isset($body['config']['remove_furniture']) ? ['var_removal', 'var_stage'] : ['var_stage'];

                return Http::response(['id' => 'rnd_1', 'created_at' => 1, 'eta' => null, 'variations' => ['total_count' => 0, 'items' => []]], 201);
            }
            if ($request->method() === 'POST' && str_contains($path, '/variations')) {
                $body = $request->data();
                $bag->posts[] = $body;
                $catalog[] = 'var_next';

                return Http::response(['variations' => [['id' => 'var_next', 'status' => 'queued', 'render_id' => 'rnd_1']]], 201);
            }
            if ($request->method() === 'GET' && str_contains($path, '/renders/')) {
                $items = [];
                foreach ($catalog as $id) {
                    $type = $id === 'var_removal' ? 'removal' : 'staging';
                    $items[] = [
                        'id' => $id, 'type' => $type, 'status' => 'done', 'render_id' => 'rnd_1',
                        'result' => ['url' => 'https://results.example.test/'.$id.'.jpg'],
                        'config' => $type === 'removal' ? ['type' => 'staging', 'remove_furniture' => ['mode' => 'on']] : ['type' => 'staging', 'add_furniture' => ['style' => 'modern', 'room_type' => 'living']],
                    ];
                }

                return Http::response($this->renderBody('rnd_1', $items));
            }
            if (str_starts_with($request->url(), 'https://results.example.test/')) {
                return Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        return $bag;
    }

    private function renderBody(string $id, array $items): array
    {
        return ['id' => $id, 'created_at' => 1, 'eta' => null, 'variations' => ['total_count' => count($items), 'next_cursor' => null, 'items' => $items]];
    }

    private function workspace(array $adjustments = []): StudioWorkspace
    {
        $user = User::factory()->create(['role' => 'admin']);
        $ref = "studio/uploads/{$user->id}/{$user->id}/source.jpg";
        Storage::disk('public')->put($ref, $this->jpeg);

        return StudioWorkspace::create([
            'team_id' => $user->id, 'created_by' => $user->id, 'name' => 'Staging workspace', 'preset_id' => 'virtual-staging',
            'media' => [['id' => 'm1', 'mediaRef' => $ref, 'name' => 'source.jpg', 'kind' => 'image']],
            'config' => ['prompt' => '', 'ratio' => '16:9', 'frames' => [['mediaId' => 'm1', 'method' => 'fit']], 'adjustments' => $adjustments + ['roomType' => 'living', 'furnitureStyle' => 'modern', 'removal' => 'off', 'addFurniture' => true, 'variationCount' => 1]],
            'outputs' => [], 'status' => 'generating', 'version' => 1,
            'operation' => ['id' => 'operation-1', 'type' => 'generate', 'payload' => [], 'completed' => [], 'requests' => [], 'routing' => ['virtual-staging' => ['provider' => 'virtualstagingai', 'model' => 'staging', 'fallback' => null]]],
        ]);
    }

    private function createWorkspace(User $user, string $preset = 'virtual-staging'): StudioWorkspace
    {
        $ref = "studio/uploads/100/{$user->id}/photo-1.jpg";
        Storage::disk('public')->put($ref, $this->jpeg);
        $response = $this->postJson('/api/studio/workspaces', [
            'name' => 'Property edit', 'presetId' => $preset,
            'media' => [['id' => 'm1', 'mediaRef' => $ref]],
            'config' => ['adjustments' => ['roomType' => 'living', 'furnitureStyle' => 'modern']],
        ])->assertCreated();

        return StudioWorkspace::findOrFail($response->json('data.id'));
    }

    private function actor(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role, 'metadata' => ['team_id' => 100]]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function image(): string
    {
        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 80, 120));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
