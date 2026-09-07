<?php

namespace Tests\Feature;

use App\Exceptions\StudioProviderException;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\Providers\FotelloClient;
use App\Services\Studio\Providers\FotelloException;
use App\Services\Studio\Providers\OpenAiImageProvider;
use App\Services\Studio\WorkspaceImageOperations;
use App\Services\Studio\WorkspacePhotoEnhancement;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkspacePhotoEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private FotelloClient&MockInterface $client;

    private string $source;

    private const ROUTE = ['provider' => 'fotello', 'model' => 'enhance', 'fallback' => null];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'studio_providers.fotello.api_key' => 'test-only-credential', 'studio_providers.fotello.team_id' => 'provider-team',
            'services.openai.api_key' => null, 'services.fal.video_poll_timeout' => 0, 'studio_uploads.disk' => 'public',
        ]);
        $this->client = Mockery::mock(FotelloClient::class);
        $this->app->bind(FotelloClient::class, fn () => $this->client);
        $this->source = $this->image(40, 80, 120);
    }

    public function test_checkpoints_resume_completed_enhancement_without_recreating_listing_upload_or_paid_job(): void
    {
        $workspace = $this->workspace();
        $this->creates();
        $this->client->shouldReceive('getEnhance')->with('enhance-1')->twice()->andReturn($this->completed());
        $this->client->shouldReceive('downloadBytes')->with('https://signed.example.test/result.jpg')->twice()->andReturn($this->source);
        $this->assertSame($this->source, $this->runPhoto($workspace));
        // Reconstructed service/workspace simulates a worker restart after the provider finished.
        $this->assertSame($this->source, $this->runPhoto($workspace->fresh()));
        $checkpoint = $workspace->fresh()->operation['providerState'];
        $this->assertSame(['id' => 'listing-1'], $checkpoint['photo-listing-uploads']);
        $this->assertSame('upload-1', $checkpoint[$this->key().'-upload']['id']);
        $this->assertTrue($checkpoint[$this->key().'-uploaded']);
        $this->assertSame('interior', $checkpoint[$this->key().'-scene']);
        $this->assertSame('enhance-1', $checkpoint[$this->key().'-enhance']['id']);
        $this->assertSame(hash('sha256', 'provider-team'), $checkpoint['photo-team']);
        Http::assertNothingSent();
    }

    public function test_upload_interruption_reuses_the_saved_upload_before_creating_the_enhancement(): void
    {
        $workspace = $this->workspace();
        $this->client->shouldReceive('createListing')->once()->andReturn(['id' => 'listing-1']);
        $this->client->shouldReceive('createUpload')->once()->andReturn($this->upload());
        $this->client->shouldReceive('uploadBytes')->once()->andThrow(new FotelloException('unavailable', retryable: true));
        try {
            $this->runPhoto($workspace);
            $this->fail('Interrupted upload was accepted.');
        } catch (FotelloException $exception) {
            $this->assertTrue($exception->retryable);
        }
        $this->assertArrayNotHasKey($this->key().'-uploaded', $workspace->fresh()->operation['providerState']);
        $this->client->shouldReceive('uploadBytes')->once()->with($this->upload(), $this->source);
        $this->client->shouldReceive('createEnhance')->once()->andReturn(['id' => 'enhance-1']);
        $this->client->shouldReceive('getEnhance')->once()->andReturn($this->completed());
        $this->client->shouldReceive('downloadBytes')->once()->andReturn($this->source);
        $this->assertSame($this->source, $this->runPhoto($workspace->fresh()));
    }

    public function test_pending_timeout_retains_enhance_id_and_retries_poll_only(): void
    {
        $workspace = $this->workspace();
        $this->creates();
        $this->client->shouldReceive('getEnhance')->with('enhance-1')->once()->andReturn(['id' => 'enhance-1', 'status' => 'in_progress']);
        try {
            $this->runPhoto($workspace);
            $this->fail('Pending work was reported complete.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('existing job', $exception->getMessage());
        }
        $this->assertSame('enhance-1', $workspace->fresh()->operation['providerState'][$this->key().'-enhance']['id']);
        $this->client->shouldReceive('getEnhance')->with('enhance-1')->once()->andReturn($this->completed());
        $this->client->shouldReceive('downloadBytes')->once()->andReturn($this->source);
        $this->assertSame($this->source, $this->runPhoto($workspace->fresh()));
    }

    public function test_expired_unuploaded_slot_is_refreshed_without_recreating_listing_or_enhance(): void
    {
        $workspace = $this->workspace();
        $expired = array_merge($this->upload('expired'), ['expires' => '2000-01-01T00:00:00Z']);
        $operation = $workspace->operation;
        $operation['providerState'] = ['photo-team' => hash('sha256', 'provider-team'), 'photo-listing-uploads' => ['id' => 'listing-1'], $this->key().'-upload' => $expired];
        $workspace->update(['operation' => $operation]);
        $this->client->shouldReceive('createUpload')->once()->andReturn($this->upload('refreshed'));
        $this->client->shouldReceive('uploadBytes')->once()->with($this->upload('refreshed'), $this->source);
        $this->client->shouldReceive('createEnhance')->once()->with(Mockery::on(fn ($body) => $body['upload_ids'] === ['upload-refreshed']))->andReturn(['id' => 'enhance-1']);
        $this->client->shouldReceive('getEnhance')->once()->with('enhance-1')->andReturn($this->completed());
        $this->client->shouldReceive('downloadBytes')->once()->andReturn($this->source);
        $this->assertSame($this->source, $this->runPhoto($workspace));
        $this->assertSame('upload-refreshed', $workspace->fresh()->operation['providerState'][$this->key().'-upload']['id']);
    }

    public function test_expired_upload_url_does_not_replace_an_already_uploaded_source_or_its_paid_enhancement(): void
    {
        $workspace = $this->workspace();
        $operation = $workspace->operation;
        $operation['providerState'] = [
            'photo-team' => hash('sha256', 'provider-team'), 'photo-listing-uploads' => ['id' => 'listing-1'],
            $this->key().'-upload' => array_merge($this->upload(), ['expires' => '2000-01-01T00:00:00Z']),
            $this->key().'-uploaded' => true, $this->key().'-enhance' => ['id' => 'enhance-1'], $this->key().'-scene' => 'interior',
        ];
        $workspace->update(['operation' => $operation]);
        $this->client->shouldReceive('getEnhance')->with('enhance-1')->once()->andReturn($this->completed());
        $this->client->shouldReceive('downloadBytes')->once()->andReturn($this->source);
        $this->assertSame($this->source, $this->runPhoto($workspace));
        $this->assertSame('upload-1', $workspace->fresh()->operation['providerState'][$this->key().'-upload']['id']);
    }

    #[DataProvider('creationStages')]
    public function test_ambiguous_creation_keeps_a_submission_marker_and_blocks_duplicate_paid_work(string $stage): void
    {
        $workspace = $this->workspace();
        $exception = new FotelloException('unavailable', ambiguousOutcome: true);
        if ($stage !== 'listing') {
            $this->client->shouldReceive('createListing')->once()->andReturn(['id' => 'listing-1']);
        }
        if ($stage === 'enhance') {
            $this->client->shouldReceive('createUpload')->once()->andReturn($this->upload());
            $this->client->shouldReceive('uploadBytes')->once();
        }
        $method = ['listing' => 'createListing', 'upload' => 'createUpload', 'enhance' => 'createEnhance'][$stage];
        $this->client->shouldReceive($method)->once()->andThrow($exception);
        try {
            $this->runPhoto($workspace);
            $this->fail('Ambiguous submission accepted.');
        } catch (FotelloException $caught) {
            $this->assertSame($exception, $caught);
        }
        $key = $stage === 'listing' ? 'photo-listing-uploads' : $this->key().'-'.$stage;
        $this->assertSame(['submitting' => true], $workspace->fresh()->operation['providerState'][$key]);
        try {
            $this->runPhoto($workspace->fresh());
            $this->fail('A duplicate submission was permitted.');
        } catch (StudioProviderException $caught) {
            $this->assertTrue($caught->ambiguous);
            $this->assertStringContainsString('administrator', $caught->getMessage());
        }
        $this->assertSame([], $workspace->fresh()->outputs ?? []);
    }

    public static function creationStages(): array
    {
        return [['listing'], ['upload'], ['enhance']];
    }

    public function test_confirmed_failed_enhancement_retries_only_that_paid_job_and_preserves_its_upload(): void
    {
        $workspace = $this->workspace();
        $this->creates();
        $this->client->shouldReceive('getEnhance')->with('enhance-1')->once()->andReturn(['id' => 'enhance-1', 'status' => 'failed']);
        try {
            $this->runPhoto($workspace);
            $this->fail('Failed enhancement accepted.');
        } catch (StudioProviderException $exception) {
            $this->assertStringContainsString('reuse its uploaded source', $exception->getMessage());
        }
        $checkpoint = $workspace->fresh()->operation['providerState'];
        $this->assertArrayNotHasKey($this->key().'-enhance', $checkpoint);
        $this->assertTrue($checkpoint[$this->key().'-uploaded']);
        $this->assertSame('upload-1', $checkpoint[$this->key().'-upload']['id']);
        $this->client->shouldReceive('createEnhance')->once()->with(Mockery::on(fn ($payload) => $payload['upload_ids'] === ['upload-1']))->andReturn(['id' => 'enhance-2']);
        $this->client->shouldReceive('getEnhance')->with('enhance-2')->once()->andReturn($this->completed('enhance-2'));
        $this->client->shouldReceive('downloadBytes')->once()->andReturn($this->source);
        $this->assertSame($this->source, $this->runPhoto($workspace->fresh()));
    }

    public function test_different_shoots_get_separate_listings_while_each_shoot_reuses_its_listing(): void
    {
        $workspace = $this->workspace();
        $items = [['id' => 'a', 'shootId' => 10], ['id' => 'b', 'shootId' => 20], ['id' => 'c', 'shootId' => 10]];
        $this->client->shouldReceive('createListing')->twice()->with(Mockery::on(fn ($body) => $body['num_total_brackets'] === 1))->andReturn(['id' => 'listing-10'], ['id' => 'listing-20']);
        foreach ([['a', 'listing-10'], ['b', 'listing-20'], ['c', 'listing-10']] as [$id, $listing]) {
            $this->client->shouldReceive('createUpload')->once()->with(Mockery::on(fn ($body) => $body['listingId'] === $listing && $body['filename'] === $this->key($id).'.jpg'))->andReturn($this->upload($id));
            $this->client->shouldReceive('uploadBytes')->once()->with($this->upload($id), $this->source);
            $this->client->shouldReceive('createEnhance')->once()->with(Mockery::on(fn ($body) => $body['listing_id'] === $listing && $body['upload_ids'] === ['upload-'.$id]))->andReturn(['id' => 'enhance-'.$id]);
            $this->client->shouldReceive('getEnhance')->once()->with('enhance-'.$id)->andReturn($this->completed('enhance-'.$id));
        }
        $this->client->shouldReceive('downloadBytes')->times(3)->andReturn($this->source);
        foreach ($items as $item) {
            $this->assertSame($this->source, app(WorkspacePhotoEnhancement::class)->run($workspace->fresh(), 'operation-1', $item, $this->source, self::ROUTE));
        }
        $checkpoint = $workspace->fresh()->operation['providerState'];
        $this->assertSame('listing-10', $checkpoint['photo-listing-10']['id']);
        $this->assertSame('listing-20', $checkpoint['photo-listing-20']['id']);
    }

    public function test_provider_team_change_cannot_resume_using_another_accounts_resource_ids(): void
    {
        $workspace = $this->workspace();
        $this->creates();
        $this->client->shouldReceive('getEnhance')->once()->andReturn($this->completed());
        $this->client->shouldReceive('downloadBytes')->once()->andReturn($this->source);
        $this->runPhoto($workspace);
        $checkpoint = $workspace->fresh()->operation['providerState'];
        config(['studio_providers.fotello.team_id' => 'different-provider-team']);
        try {
            $this->runPhoto($workspace->fresh());
            $this->fail('A different editing account reused the old resource IDs.');
        } catch (StudioProviderException $exception) {
            $this->assertStringContainsString('account changed', $exception->getMessage());
        }
        $this->assertSame($checkpoint, $workspace->fresh()->operation['providerState']);
    }

    public function test_upstream_media_authorization_prevents_provider_access_to_another_users_upload(): void
    {
        $workspace = $this->workspace();
        $media = $workspace->media;
        $media[0]['mediaRef'] = 'studio/uploads/9999/9999/private.jpg';
        $workspace->update(['media' => $media]);
        $this->expectException(AuthorizationException::class);
        app(WorkspaceProcessor::class)->process($workspace, 'operation-1');
    }

    public function test_processor_stores_a_real_completed_image_and_presents_no_provider_credentials_or_checkpoints(): void
    {
        $workspace = $this->workspace();
        $this->creates(false);
        $result = $this->image(20, 200, 30);
        $this->client->shouldReceive('getEnhance')->once()->andReturn($this->completed());
        $this->client->shouldReceive('downloadBytes')->once()->andReturn($result);
        app(WorkspaceProcessor::class)->process($workspace, 'operation-1');
        $workspace->refresh();
        $this->assertSame('completed', $workspace->status);
        $this->assertCount(1, $workspace->outputs);
        $output = $workspace->outputs[0];
        $this->assertSame('m1', $output['mediaId']);
        $this->assertSame('completed', $output['status']);
        $stored = Storage::disk('public')->get($output['path']);
        $this->assertNotSame($this->source, $stored);
        $this->assertSame([64, 48], array_slice(getimagesizefromstring($stored), 0, 2));
        $image = imagecreatefromstring($stored);
        $pixel = imagecolorsforindex($image, imagecolorat($image, 32, 24));
        $this->assertGreaterThan(180, $pixel['green']);
        imagedestroy($image);
        $presented = json_encode($workspace->present());
        foreach (['fotello', 'providerState', 'provider-team', 'test-only-credential', 'signed.example.test', 'enhance-1', 'listing-1'] as $private) {
            $this->assertStringNotContainsString($private, $presented);
        }
        Http::assertNothingSent();
    }

    public function test_native_default_controls_do_not_trigger_a_second_paid_revision(): void
    {
        $workspace = $this->workspace();
        $config = $workspace->config;
        $config['adjustments'] += ['brightness' => 0, 'warmth' => 0, 'windows' => 50, 'look' => 'Natural', 'lensCorrection' => true, 'verticalCorrection' => true, 'skyReplacement' => false];
        $workspace->update(['config' => $config]);
        $this->creates();
        $this->client->shouldReceive('getEnhance')->once()->andReturn($this->completed());
        $this->client->shouldReceive('downloadBytes')->once()->andReturn($this->source);
        $this->mock(OpenAiImageProvider::class)->shouldNotReceive('edit');
        $result = app(WorkspaceImageOperations::class)->edit($workspace, 'operation-1', $workspace->media[0], $this->source, 'Standard enhancement');
        $this->assertSame($this->source, $result);
        Http::assertNothingSent();
    }

    #[DataProvider('refinementRequests')]
    public function test_requested_custom_edits_run_on_the_enhanced_image_using_the_pinned_revision_provider(string $request): void
    {
        $workspace = $this->workspace();
        $config = $workspace->config;
        if ($request === 'prompt') {
            $config['prompt'] = 'Keep the existing window';
        } elseif ($request === 'adjustment') {
            $config['adjustments']['brightness'] = 5;
        }
        $operation = $workspace->operation;
        $operation['routing']['revision'] = ['provider' => 'openai', 'model' => 'gpt-image-2', 'fallback' => null];
        $workspace->update(['config' => $config, 'operation' => $operation]);
        $enhanced = $this->image(10, 200, 30);
        $refined = $this->image(20, 180, 50);
        $references = $request === 'reference' ? [$this->image(100, 150, 200)] : [];
        $this->creates();
        // A second run resumes the native job and the saved revision, never resubmitting either.
        $this->client->shouldReceive('getEnhance')->with('enhance-1')->twice()->andReturn($this->completed());
        $this->client->shouldReceive('downloadBytes')->twice()->andReturn($enhanced);
        $this->mock(OpenAiImageProvider::class)->shouldReceive('edit')->once()->with($enhanced, 'Requested image update', ['model' => 'gpt-image-2', 'reference_images' => $references])->andReturn($refined);
        foreach ([1, 2] as $attempt) {
            $result = app(WorkspaceImageOperations::class)->edit($workspace->fresh(), 'operation-1', $workspace->media[0], $this->source, 'Requested image update', $references);
            $this->assertSame($refined, $result);
        }
        $key = 'openai-'.hash('sha256', 'm1-refine');
        $path = $workspace->fresh()->operation['providerState'][$key]['path'];
        $this->assertSame($refined, Storage::disk('local')->get($path));
        Http::assertNothingSent();
    }

    public static function refinementRequests(): array
    {
        return [['prompt'], ['adjustment'], ['reference']];
    }

    private function creates(bool $exactSource = true): void
    {
        $this->client->shouldReceive('createListing')->once()->andReturn(['id' => 'listing-1']);
        $this->client->shouldReceive('createUpload')->once()->andReturn($this->upload());
        $source = $exactSource ? $this->source : Mockery::on(fn ($bytes) => is_string($bytes) && getimagesizefromstring($bytes) !== false);
        $this->client->shouldReceive('uploadBytes')->once()->with($this->upload(), $source);
        $this->client->shouldReceive('createEnhance')->once()->with([
            'listing_id' => 'listing-1', 'upload_ids' => ['upload-1'], 'input_preview_image_id' => 'upload-1', 'shot_type' => 'interior',
        ])->andReturn(['id' => 'enhance-1']);
    }

    private function runPhoto(StudioWorkspace $workspace): string
    {
        return app(WorkspacePhotoEnhancement::class)->run($workspace, 'operation-1', $workspace->media[0], $this->source, self::ROUTE);
    }

    private function workspace(): StudioWorkspace
    {
        $user = User::factory()->create(['role' => 'admin']);
        $ref = "studio/uploads/{$user->id}/{$user->id}/source.jpg";
        Storage::disk('public')->put($ref, $this->source);

        return StudioWorkspace::create([
            'team_id' => $user->id, 'created_by' => $user->id, 'name' => 'Test workspace', 'preset_id' => 'listing-ready',
            'media' => [['id' => 'm1', 'mediaRef' => $ref, 'name' => 'source.jpg', 'kind' => 'image']],
            'config' => ['prompt' => '', 'ratio' => '4:5', 'adjustments' => ['sceneType' => 'interior'], 'frames' => [['mediaId' => 'm1', 'method' => 'fit']]],
            'outputs' => [], 'prepared_frames' => [], 'status' => 'generating',
            'operation' => ['id' => 'operation-1', 'type' => 'generate', 'payload' => [], 'completed' => [], 'requests' => [], 'routing' => ['listing-ready' => self::ROUTE]],
        ]);
    }

    private function upload(string $id = '1'): array
    {
        return ['id' => 'upload-'.$id, 'url' => 'https://signed.example.test/upload-'.$id, 'uri' => 's3://provider/upload-'.$id, 'expires' => '2099-09-09T00:00:00Z'];
    }

    private function completed(string $id = 'enhance-1'): array
    {
        return ['id' => $id, 'status' => 'completed', 'enhanced_image_url' => 'https://signed.example.test/result.jpg'];
    }

    private function key(string $id = 'm1'): string
    {
        return 'photo-'.hash('sha256', $id);
    }

    private function image(int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        ob_start();
        imagejpeg($image, null, 95);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
