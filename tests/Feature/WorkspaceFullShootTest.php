<?php

namespace Tests\Feature;

use App\Exceptions\StudioProviderException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\{Category, Service, Shoot, ShootFile, StudioWorkspace, User};
use App\Services\Studio\{FullShootPhotoGroups, WorkspaceFullShoot, WorkspaceMediaService, WorkspaceProcessor};
use App\Services\Studio\Providers\{FotelloClient, FotelloException};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Queue, Storage};
use Mockery;
use Tests\TestCase;

class WorkspaceFullShootTest extends TestCase
{
    use RefreshDatabase;

    private array $uploaded = [];
    private array $enhancements = [];
    private bool $ready = false;
    private string $jpeg;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['studio_providers.fotello.api_key' => 'test-key', 'studio_providers.fotello.team_id' => 'test-team']);
        $image = imagecreatetruecolor(32, 24);
        ob_start(); imagejpeg($image); $this->jpeg = ob_get_clean(); imagedestroy($image);
    }

    private function workspace(): StudioWorkspace
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['status' => 'editing', 'workflow_status' => 'editing']);
        $service = Service::factory()->create(['name' => 'HDR Photos', 'uses_hdr_brackets' => true, 'category_id' => Category::firstOrCreate(['name' => 'Photos'])->id]);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'bracket_mode' => 5]);
        $serviceId = $shoot->services()->first()->pivot->id;
        $media = [];
        for ($i = 1; $i <= 6; $i++) {
            $name = $i <= 5 ? 'exposure-'.$i.'.CR3' : 'single.jpg';
            $path = 'shoots/'.$shoot->id.'/'.$name;
            Storage::disk('public')->put($path, $i <= 5 ? 'original-raw-'.$i : $this->jpeg);
            $file = ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $serviceId,
                'filename' => $name, 'stored_filename' => $name, 'path' => $path, 'storage_path' => $path,
                'file_size' => 100, 'uploaded_by' => $user->id, 'workflow_stage' => 'todo', 'scan_status' => 'clean',
                'media_type' => $i <= 5 ? 'raw' : 'photo', 'file_type' => $i <= 5 ? 'image/x-canon-cr3' : 'image/jpeg',
                'bracket_group' => $i <= 5 ? 1 : null, 'sequence' => $i <= 5 ? $i : null]);
            $media[] = ['id' => 'file:'.$file->id, 'fileId' => $file->id, 'shootId' => $shoot->id, 'name' => $name, 'kind' => $i <= 5 ? 'raw' : 'image'];
        }
        return StudioWorkspace::create(['team_id' => $user->id, 'created_by' => $user->id, 'shoot_id' => $shoot->id,
            'name' => 'HDR test', 'preset_id' => 'full-shoot', 'media' => $media, 'status' => 'generating', 'outputs' => [],
            'config' => ['ratio' => '16:9', 'prompt' => '', 'frames' => [], 'adjustments' => ['sceneType' => 'interior']],
            'operation' => ['id' => 'operation-1', 'type' => 'generate', 'payload' => [], 'completed' => [],
                'routing' => ['full-shoot' => ['provider' => 'fotello', 'model' => 'enhance']]]]);
    }

    private function provider(): FotelloClient
    {
        $client = Mockery::mock(FotelloClient::class);
        $this->app->bind(FotelloClient::class, fn () => $client);
        $client->shouldReceive('createListing')->once()->with(['name' => 'HDR test'])->andReturn(['id' => 'listing']);
        $client->shouldReceive('createUpload')->times(6)->andReturnUsing(fn ($body) => ['id' => $body['filename'], 'url' => 'https://storage.test/upload', 'uri' => 'gs://test/source', 'expires' => '2099-01-01T00:00:00Z']);
        $client->shouldReceive('uploadBytes')->times(6)->andReturnUsing(function ($upload, $bytes) { $this->uploaded[$upload['id']] = $bytes; });
        $client->shouldReceive('createEnhance')->twice()->andReturnUsing(function ($body) {
            $this->enhancements[] = $body;
            return ['id' => 'enhance-'.count($this->enhancements)];
        });
        $client->shouldReceive('getEnhance')->andReturnUsing(fn ($id) => ['id' => $id, 'status' => $this->ready ? 'completed' : 'in_progress', 'enhanced_image_url' => 'https://storage.test/'.$id.'.jpg']);
        $client->shouldReceive('downloadBytes')->twice()->andReturn($this->jpeg);
        return $client;
    }

    public function test_original_exposures_are_merged_per_stack_and_return_to_edited_without_duplicate_jobs(): void
    {
        $workspace = $this->workspace();
        $this->provider();
        $processor = app(WorkspaceProcessor::class);
        $processor->process($workspace, 'operation-1');
        $this->assertCount(6, $this->uploaded);
        $this->assertSame('original-raw-1', $this->uploaded['exposure-1.CR3']);
        $this->assertCount(5, $this->enhancements[0]['upload_ids']);
        $this->assertCount(1, $this->enhancements[1]['upload_ids']);
        $this->assertSame('generating', $workspace->fresh()->status);
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        $this->ready = true;
        $processor->process($workspace->fresh(), 'operation-1');
        $workspace->refresh();
        $this->assertSame('completed', $workspace->status);
        $this->assertCount(2, $workspace->outputs);
        $this->assertCount(5, $workspace->outputs[0]['sourceFileIds']);
        $files = ShootFile::where('shoot_id', $workspace->shoot_id)->where('is_ai_edited', true)->get();
        $this->assertCount(2, $files);
        $this->assertTrue($files->every(fn ($file) => $file->workflow_stage === 'verified' && ! $file->ai_editing_metadata['requires_review']));
        $this->assertSame(6, ShootFile::where('shoot_id', $workspace->shoot_id)->where('workflow_stage', 'todo')->count());
        $this->assertCount(2, $workspace->present()['photoGroups']);
        $this->assertFalse($workspace->present()['requiresReview']);
        $this->assertSame('ready', Shoot::find($workspace->shoot_id)->status);
        (new ProcessStudioWorkspace($workspace->id, 'operation-1'))->handle($processor);
        $this->assertSame(2, ShootFile::where('shoot_id', $workspace->shoot_id)->where('is_ai_edited', true)->count());
        Http::assertNothingSent();
    }

    public function test_database_queue_lock_excludes_duplicates_and_releases_after_failure(): void
    {
        $job = new class { public ?int $delay = null; public function release($delay): void { $this->delay = $delay; } };
        $middleware = (new \App\Jobs\Middleware\StudioWorkspaceLock('test-shoot'))->releaseAfter(30)->expireAfter(7260);
        $called = 0;
        try {
            $middleware->handle($job, function () use ($middleware, $job, &$called) {
                $middleware->handle($job, function () use (&$called) { $called++; });
                throw new \RuntimeException('worker stopped');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('worker stopped', $exception->getMessage());
        }
        $this->assertSame(0, $called);
        $this->assertSame(30, $job->delay);
        $middleware->handle($job, function () use (&$called) { $called++; });
        $this->assertSame(1, $called);
    }

    public function test_same_stack_numbers_in_different_services_are_kept_separate(): void
    {
        $workspace = $this->workspace();
        $file = ShootFile::find($workspace->media[4]['fileId']);
        $file->update(['shoot_service_id' => null]);
        $groups = app(FullShootPhotoGroups::class)->forWorkspace($workspace);
        $this->assertCount(3, $groups);
        $this->assertCount(4, $groups[0]['sourceFileIds']);
        $this->assertCount(1, $groups[1]['sourceFileIds']);
    }

    public function test_ambiguous_submission_is_not_submitted_again(): void
    {
        $workspace = $this->workspace();
        $groups = app(FullShootPhotoGroups::class)->forWorkspace($workspace);
        $key = 'hdr-'.hash('sha256', implode('|', $groups[0]['sourceMediaIds']));
        $operation = $workspace->operation;
        $operation['providerState'] = ['full-shoot-groups' => $groups, $key.'-enhance' => ['submitting' => true]];
        $workspace->update(['operation' => $operation]);
        $client = Mockery::mock(FotelloClient::class);
        $client->shouldNotReceive('createEnhance');
        $this->app->bind(FotelloClient::class, fn () => $client);
        $this->expectException(StudioProviderException::class);
        $this->expectExceptionMessage('could not be confirmed');
        app(WorkspaceFullShoot::class)->run($workspace, 'operation-1');
    }

    public function test_changed_stacks_stop_before_any_provider_request(): void
    {
        $workspace = $this->workspace();
        $operation = $workspace->operation;
        $operation['providerState']['full-shoot-groups'] = app(FullShootPhotoGroups::class)->forWorkspace($workspace);
        $workspace->update(['operation' => $operation]);
        ShootFile::find($workspace->media[0]['fileId'])->update(['bracket_group' => 2]);
        $this->expectException(StudioProviderException::class);
        $this->expectExceptionMessage('stacks changed');
        app(WorkspaceFullShoot::class)->run($workspace, 'operation-1');
    }
}
