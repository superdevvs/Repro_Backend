<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\AccountLink;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\FalService;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioWorkspaceShootAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCES = '/api/studio/workspaces/sources';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_blank_search_browses_recent_shoots_with_a_stable_twenty_record_limit(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin', 'metadata' => null]);
        $shoots = Shoot::factory()->count(23)->create(['updated_at' => now()]);
        Sanctum::actingAs($admin);

        $expected = $shoots->sortByDesc('id')->take(20)->pluck('id')->all();
        foreach (['', '?q=', '?q=%20%20'] as $suffix) {
            $response = $this->getJson(self::SOURCES.'/shoots'.$suffix)->assertOk()
                ->assertJsonPath('meta.query', '')->assertJsonPath('meta.total', 20);
            $this->assertSame($expected, array_column($response->json('data'), 'id'));
        }
        // The legacy search contract remains opt-in search and team-scoped.
        $this->getJson('/api/studio/shoots/search?q=')->assertOk()->assertJsonPath('data', []);
    }

    public function test_privileged_users_can_find_resolve_and_generate_from_dashboard_shoots_without_team_links(): void
    {
        $client = User::factory()->create(['role' => 'client', 'metadata' => ['team_id' => 999]]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'address' => '583 Cross Team Avenue']);
        $file = $this->file($shoot, $client);
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitImageEditFromBuffer')->times(3)->andReturn(['request_id' => 'edit']);
        $fal->shouldReceive('imageEditStatus')->with('edit')->times(3)->andReturn(['status' => 'completed']);
        $fal->shouldReceive('imageEditResult')->with('edit')->times(3)->andReturn([
            'edited_image_url' => 'data:image/jpeg;base64,'.base64_encode(Storage::disk('public')->get($file->path)),
        ]);

        foreach (['admin', 'superadmin', 'editing_manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'metadata' => null]));
            $this->getJson(self::SOURCES.'/shoots')->assertOk()->assertJsonPath('data.0.id', $shoot->id);
            $this->getJson(self::SOURCES.'/shoots?q=Cross%20Team')->assertOk()->assertJsonPath('data.0.id', $shoot->id);
            $this->getJson(self::SOURCES."/shoots/{$shoot->id}/media?workflow=photo-enhancement")
                ->assertOk()->assertJsonPath('data.0.id', $file->id);
            $this->resolve($shoot)->assertOk()->assertJsonPath('data.record.id', (string) $shoot->id);
            $created = $this->postJson('/api/studio/workspaces', $this->payload($file))->assertCreated();
            $workspace = StudioWorkspace::findOrFail($created->json('data.id'));
            $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertAccepted();
            $workspace->refresh();
            (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->handle(app(WorkspaceProcessor::class));
            $this->assertSame('completed', $workspace->fresh()->status);
            $this->assertSame(['photo'], array_column($workspace->fresh()->outputs, 'mediaId'));
        }
    }

    public function test_editors_only_browse_actual_assignments_and_service_media_remains_scoped(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $peer = User::factory()->create(['role' => 'editor']);
        $assigned = Shoot::factory()->create(['editor_id' => $editor->id]);
        $serviceAssigned = Shoot::factory()->create(['editor_id' => null]);
        $service = Service::factory()->create();
        $otherService = Service::factory()->create();
        $serviceAssigned->services()->attach($service->id, ['editor_id' => $editor->id]);
        $serviceAssigned->services()->attach($otherService->id, ['editor_id' => $peer->id]);
        $ownFile = $this->file($serviceAssigned, $peer, ['shoot_service_id' => $serviceAssigned->serviceItems()->where('service_id', $service->id)->value('id')]);
        $peerFile = $this->file($serviceAssigned, $peer, ['shoot_service_id' => $serviceAssigned->serviceItems()->where('service_id', $otherService->id)->value('id')]);
        $createdOnly = Shoot::factory()->create(['created_by' => $editor->id, 'editor_id' => $peer->id, 'address' => 'Unassigned Beacon House']);
        $unassignedFile = $this->file($createdOnly, $peer);
        Sanctum::actingAs($editor);

        $response = $this->getJson(self::SOURCES.'/shoots')->assertOk();
        $this->assertEqualsCanonicalizing([$assigned->id, $serviceAssigned->id], array_column($response->json('data'), 'id'));
        $this->getJson(self::SOURCES.'/shoots?q=Unassigned%20Beacon')->assertOk()->assertJsonPath('data', []);
        $this->getJson(self::SOURCES."/shoots/{$createdOnly->id}/media?workflow=photo-enhancement")->assertNotFound();
        $this->resolve($createdOnly)->assertNotFound();
        $media = $this->getJson(self::SOURCES."/shoots/{$serviceAssigned->id}/media?workflow=photo-enhancement")->assertOk();
        $this->assertSame([$ownFile->id], array_column($media->json('data'), 'id'));
        $this->postJson('/api/studio/workspaces', $this->payload($ownFile))->assertCreated();
        $this->postJson('/api/studio/workspaces', $this->payload($peerFile))->assertForbidden();
        $this->postJson('/api/studio/workspaces', $this->payload($unassignedFile))->assertForbidden();
    }

    public function test_client_visibility_matches_directional_links_and_delivered_ghost_policy(): void
    {
        config(['studio.client_access_enabled' => true]);
        $client = User::factory()->create(['role' => 'client']);
        $linked = User::factory()->create(['role' => 'client']);
        $reverse = User::factory()->create(['role' => 'client']);
        $notShared = User::factory()->create(['role' => 'client']);
        AccountLink::create(['main_account_id' => $client->id, 'linked_account_id' => $linked->id, 'created_by' => $client->id, 'status' => 'active', 'shared_details' => ['shoots' => true]]);
        AccountLink::create(['main_account_id' => $reverse->id, 'linked_account_id' => $client->id, 'created_by' => $reverse->id, 'status' => 'active', 'shared_details' => ['shoots' => true]]);
        AccountLink::create(['main_account_id' => $client->id, 'linked_account_id' => $notShared->id, 'created_by' => $client->id, 'status' => 'active', 'shared_details' => ['invoices' => true]]);
        $own = Shoot::factory()->create(['client_id' => $client->id]);
        $shared = Shoot::factory()->create(['client_id' => $linked->id]);
        $reverseShoot = Shoot::factory()->create(['client_id' => $reverse->id]);
        $notSharedShoot = Shoot::factory()->create(['client_id' => $notShared->id]);
        $ghost = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $hiddenGhost = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'editing']);
        $ghost->ghostUsers()->attach($client->id);
        $hiddenGhost->ghostUsers()->attach($client->id);
        Sanctum::actingAs($client);

        $response = $this->getJson(self::SOURCES.'/shoots')->assertOk();
        $this->assertEqualsCanonicalizing([$own->id, $shared->id, $ghost->id], array_column($response->json('data'), 'id'));
        foreach ([$reverseShoot, $notSharedShoot, $hiddenGhost] as $denied) {
            $this->resolve($denied)->assertNotFound();
            $this->getJson(self::SOURCES."/shoots/{$denied->id}/media?workflow=photo-enhancement")->assertNotFound();
            $this->postJson('/api/studio/workspaces', $this->payload($this->file($denied, $client, ['workflow_stage' => ShootFile::STAGE_COMPLETED])))->assertForbidden();
        }
        foreach ([$shared, $ghost] as $allowed) {
            $file = $this->file($allowed, $client, ['workflow_stage' => ShootFile::STAGE_COMPLETED]);
            $this->resolve($allowed)->assertOk();
            $this->getJson(self::SOURCES."/shoots/{$allowed->id}/media?workflow=photo-enhancement")->assertOk()->assertJsonPath('data.0.id', $file->id);
            $created = $this->postJson('/api/studio/workspaces', $this->payload($file))->assertCreated();
            $allowed->update(['payment_status' => 'unpaid']);
            $this->getJson(self::SOURCES."/shoots/{$allowed->id}/media?workflow=photo-enhancement")->assertOk()->assertJsonPath('data', []);
            $this->postJson('/api/studio/workspaces/'.$created->json('data.id').'/generate')->assertForbidden();
        }
    }

    public function test_worker_rechecks_editor_assignment_before_provider_submission(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $editor->id]);
        $file = $this->file($shoot, $editor);
        Sanctum::actingAs($editor);
        $created = $this->postJson('/api/studio/workspaces', $this->payload($file))->assertCreated();
        $workspace = StudioWorkspace::findOrFail($created->json('data.id'));
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertAccepted();
        $shoot->update(['editor_id' => null]);
        $this->mock(FalService::class)->shouldNotReceive('submitImageEditFromBuffer');
        $workspace->refresh();

        $this->expectException(AuthorizationException::class);
        (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->handle(app(WorkspaceProcessor::class));
    }

    public function test_raw_sources_return_browser_decodable_previews_without_exposing_the_original(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create();
        $file = $this->file($shoot, $admin, ['filename' => 'bracket.CR3', 'mime_type' => 'image/x-canon-cr3', 'thumbnail_path' => 'thumb.jpg']);
        Storage::disk('public')->put('thumb.jpg', UploadedFile::fake()->image('thumb.jpg', 40, 30)->getContent());
        Sanctum::actingAs($admin);
        $response = $this->getJson(self::SOURCES."/shoots/{$shoot->id}/media?workflow=photo-enhancement")->assertOk();
        $url = $response->json('data.0.thumbnailUrl');
        $this->assertStringContainsString('/sources/files/', $url);
        $preview = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(40, getimagesizefromstring($preview->getContent())[0]);
        $file->update(['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED]);
        $this->getJson($url)->assertUnprocessable();
    }

    public function test_hdr_sources_expose_stack_metadata_and_queue_one_private_merge(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create();
        $files = collect([1, 2, 3])->map(fn ($sequence) => $this->file($shoot, $admin, ['bracket_group' => 1, 'sequence' => $sequence]));
        Sanctum::actingAs($admin);
        $this->getJson(self::SOURCES."/shoots/{$shoot->id}/media?workflow=photo-enhancement")
            ->assertOk()->assertJsonPath('data.0.bracketGroup', 1)->assertJsonPath('data.0.sequence', 1);
        $input = ['fileIds' => $files->pluck('id')->all()];
        $response = $this->postJson(self::SOURCES.'/hdr', $input)->assertAccepted()->assertJsonPath('data.status', 'processing');
        $this->postJson(self::SOURCES.'/hdr', $input)->assertAccepted();
        Queue::assertPushed(\App\Jobs\MergeStudioHdr::class, 1);
        $this->postJson('/api/studio/workspaces', ['name' => 'HDR', 'presetId' => 'listing-ready', 'media' => [$response->json('data.media')]])->assertUnprocessable();
    }

    public function test_hdr_merge_rejects_mixed_stacks_and_inaccessible_or_quarantined_exposures(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $editor->id]);
        $first = $this->file($shoot, $editor, ['bracket_group' => 1]);
        $second = $this->file($shoot, $editor, ['bracket_group' => 2]);
        Sanctum::actingAs($editor);
        $input = ['fileIds' => [$first->id, $second->id]];
        $this->postJson(self::SOURCES.'/hdr', $input)->assertUnprocessable();
        $second->update(['bracket_group' => 1, 'scan_status' => ShootFile::SCAN_STATUS_QUARANTINED]);
        $this->postJson(self::SOURCES.'/hdr', $input)->assertUnprocessable();
        $second->update(['scan_status' => ShootFile::SCAN_STATUS_CLEAN]);
        $shoot->update(['editor_id' => null]);
        $this->postJson(self::SOURCES.'/hdr', $input)->assertForbidden();
        Queue::assertNotPushed(\App\Jobs\MergeStudioHdr::class);
    }

    public function test_hdr_worker_caches_the_merged_image_and_provider_receives_only_that_image(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create();
        $files = collect([1, 2, 3])->map(fn ($sequence) => $this->file($shoot, $admin, ['bracket_group' => 1, 'sequence' => $sequence]));
        $merged = UploadedFile::fake()->image('merged.jpg', 640, 480)->getContent();
        $this->mock(\App\Services\Studio\HdrExposureFusion::class)->shouldReceive('merge')->once()->with(\Mockery::on(fn ($sources) => count($sources) === 3))->andReturn($merged);
        Sanctum::actingAs($admin);
        $input = ['fileIds' => $files->pluck('id')->all()];
        $this->postJson(self::SOURCES.'/hdr', $input)->assertAccepted();
        $job = Queue::pushed(\App\Jobs\MergeStudioHdr::class)->first();
        $job->handle(app(\App\Services\Studio\WorkspaceHdrService::class));
        $response = $this->getJson(self::SOURCES.'/hdr?'.http_build_query($input))->assertOk()->assertJsonPath('data.status', 'ready');
        $media = $response->json('data.media');
        $this->assertArrayNotHasKey('fileId', $media);
        $this->assertSame($input['fileIds'], $media['stackFileIds']);
        $this->get($media['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $created = $this->postJson('/api/studio/workspaces', ['name' => 'HDR', 'presetId' => 'listing-ready', 'media' => [$media]])->assertCreated();
        $workspace = StudioWorkspace::findOrFail($created->json('data.id'));
        $fal = $this->mock(FalService::class);
        $this->assertSame($merged, app(\App\Services\Studio\WorkspaceMediaService::class)->bytes($workspace->media[0]));
        $fal->shouldReceive('submitImageEditFromBuffer')->once()->withArgs(fn ($bytes, ...$rest) => getimagesizefromstring($bytes)[0] === 640)->andReturn(['request_id' => 'hdr-edit']);
        $fal->shouldReceive('imageEditStatus')->with('hdr-edit')->andReturn(['status' => 'completed']);
        $fal->shouldReceive('imageEditResult')->with('hdr-edit')->andReturn(['edited_image_url' => 'data:image/jpeg;base64,'.base64_encode($merged)]);
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertAccepted();
        $workspace->refresh();
        (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->handle(app(WorkspaceProcessor::class));
        $this->assertCount(1, $workspace->fresh()->outputs);
        $files->first()->update(['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED]);
        $this->getJson($media['url'])->assertUnprocessable();
        $this->postJson('/api/studio/workspaces', ['name' => 'HDR', 'presetId' => 'listing-ready', 'media' => [$media]])->assertUnprocessable();
    }

    private function resolve(Shoot $shoot): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::SOURCES.'/resolve', ['destination' => 'studio', 'recordType' => 'shoot', 'recordId' => (string) $shoot->id]);
    }

    private function payload(ShootFile $file): array
    {
        return ['name' => 'Authorized shoot edit', 'presetId' => 'listing-ready', 'media' => [['id' => 'photo', 'shootId' => $file->shoot_id, 'fileId' => $file->id]]];
    }

    private function file(Shoot $shoot, User $uploader, array $overrides = []): ShootFile
    {
        $name = \Illuminate\Support\Str::uuid().'.jpg';
        $path = 'shoots/'.$shoot->id.'/'.$name;
        Storage::disk('public')->put($path, UploadedFile::fake()->image($name, 32, 24)->getContent());

        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id, 'filename' => $name, 'stored_filename' => $name,
            'path' => $path, 'storage_path' => $path, 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'file_size' => 1024, 'uploaded_by' => $uploader->id, 'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'is_hidden' => false,
        ], $overrides));
    }
}
